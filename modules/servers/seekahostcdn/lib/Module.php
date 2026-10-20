<?php
/**
 * Core logic shared by the SeekaHost CDN server module and addon:
 * schema, settings, provisioning, DNS management, usage sync and billing.
 */

namespace SeekahostCDN;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/BunnyClient.php';

class Module
{
    const MODULE = 'seekahostcdn';
    const BYTES_PER_GB = 1000000000; // bandwidth is billed in decimal GB, as bunny.net does

    // Record types a client may create from the client area
    public static $clientRecordTypes = [
        BunnyClient::REC_A, BunnyClient::REC_AAAA, BunnyClient::REC_CNAME, BunnyClient::REC_TXT,
        BunnyClient::REC_MX, BunnyClient::REC_SRV, BunnyClient::REC_CAA, BunnyClient::REC_PULLZONE,
    ];

    // Subdomains probed when importing a domain's existing DNS
    private static $probeNames = [
        'www', 'mail', 'webmail', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'cpanel', 'whm', 'webdisk',
        'cpcalendars', 'cpcontacts', 'autodiscover', 'autoconfig', 'm', 'shop', 'store', 'blog', 'api',
        'app', 'dev', 'staging', 'test', 'cdn', 'static', 'img', 'portal', 'admin', 'secure', 'vpn',
        '_dmarc', 'default._domainkey', 'google._domainkey', 'selector1._domainkey',
        'selector2._domainkey', 'k1._domainkey', 'mail._domainkey', 's1._domainkey', 's2._domainkey',
        '_autodiscover._tcp', '_sip._tls', '_sipfederationtls._tcp',
    ];

    // ================================================================== Schema

    public static function ensureSchema()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('mod_seekahostcdn_dns_zones')) {
            $schema->create('mod_seekahostcdn_dns_zones', function ($table) {
                $table->increments('id');
                $table->integer('service_id')->unique();
                $table->string('dns_zone_id', 32);
                $table->string('pull_zone_id', 32);
                $table->string('domain', 255);
                $table->string('nameserver_1', 255)->nullable();
                $table->string('nameserver_2', 255)->nullable();
                $table->string('cdn_url', 255)->nullable();
                $table->string('origin_url', 255)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
        self::addColumns('mod_seekahostcdn_dns_zones', [
            'hostnames' => function ($t) { $t->text('hostnames')->nullable(); },
            'ssl_hostnames' => function ($t) { $t->text('ssl_hostnames')->nullable(); },
            'nameservers_detected' => function ($t) { $t->boolean('nameservers_detected')->default(0); },
            'suspended' => function ($t) { $t->boolean('suspended')->default(0); },
            'updated_at' => function ($t) { $t->timestamp('updated_at')->nullable(); },
            'mode' => function ($t) { $t->string('mode', 10)->default('dns'); },
            'source' => function ($t) { $t->string('source', 10)->default('product'); },
            'addon_id' => function ($t) { $t->integer('addon_id')->default(0); },
            'server_id' => function ($t) { $t->integer('server_id')->default(0); },
            'limit_bytes' => function ($t) { $t->bigInteger('limit_bytes')->default(0); },
            'storage_zone_id' => function ($t) { $t->string('storage_zone_id', 32)->nullable(); },
            'storage_zone_name' => function ($t) { $t->string('storage_zone_name', 100)->nullable(); },
            'storage_region' => function ($t) { $t->string('storage_region', 10)->nullable(); },
            'overage_month' => function ($t) { $t->string('overage_month', 7)->nullable(); },
        ]);

        // Daily month-to-date snapshot of each zone: bandwidth and bunny.net cost
        if (!$schema->hasTable('mod_seekahostcdn_usage')) {
            $schema->create('mod_seekahostcdn_usage', function ($table) {
                $table->increments('id');
                $table->integer('service_id');
                $table->string('zone_id');
                $table->date('usage_date');
                $table->bigInteger('bandwidth_bytes')->default(0);
                $table->decimal('cost', 12, 4)->default(0);
                $table->integer('invoice_id')->nullable();
                $table->boolean('invoiced')->default(0);
                $table->timestamps();
                $table->unique(['service_id', 'usage_date']);
            });
        }

        self::addColumns('mod_seekahostcdn_usage', [
            'storage_bytes' => function ($t) { $t->bigInteger('storage_bytes')->default(0); },
        ]);

        // One row per bandwidth alert sent, so each alert goes out once a month
        if (!$schema->hasTable('mod_seekahostcdn_alerts')) {
            $schema->create('mod_seekahostcdn_alerts', function ($table) {
                $table->increments('id');
                $table->integer('service_id');
                $table->string('alert_month', 7);
                $table->integer('level');
                $table->timestamp('created_at')->nullable();
                $table->unique(['service_id', 'alert_month', 'level']);
            });
        }

        if (!$schema->hasTable('mod_seekahostcdn_billing')) {
            $schema->create('mod_seekahostcdn_billing', function ($table) {
                $table->increments('id');
                $table->integer('service_id');
                $table->string('billing_month', 7);
                $table->decimal('bandwidth_gb', 14, 4)->default(0);
                $table->decimal('cost', 10, 2)->default(0);
                $table->integer('invoice_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['service_id', 'billing_month']);
            });
        }
        self::addColumns('mod_seekahostcdn_billing', [
            'userid' => function ($t) { $t->integer('userid')->nullable(); },
            'bunny_cost' => function ($t) { $t->decimal('bunny_cost', 12, 4)->default(0); },
            'client_amount' => function ($t) { $t->decimal('client_amount', 10, 2)->default(0); },
            'currency_code' => function ($t) { $t->string('currency_code', 10)->nullable(); },
            'status' => function ($t) { $t->string('status', 20)->default('invoiced'); },
            'notes' => function ($t) { $t->text('notes')->nullable(); },
            'storage_gb' => function ($t) { $t->decimal('storage_gb', 14, 4)->default(0); },
            'storage_cost' => function ($t) { $t->decimal('storage_cost', 10, 2)->default(0); },
        ]);

        self::ensureEmailTemplates();
    }

    private static function addColumns($table, array $columns)
    {
        foreach ($columns as $name => $definition) {
            if (!Capsule::schema()->hasColumn($table, $name)) {
                Capsule::schema()->table($table, $definition);
            }
        }
    }

    // ================================================================ Settings

    public static function settings()
    {
        $defaults = [
            'price_per_gb' => '0.05',
            'enable_billing' => '',
            'billing_day' => '1',
            'min_invoice' => '0.50',
            'invoice_due_days' => '7',
            'tax_usage' => '',
            'price_per_gb_storage' => '0',
            'warn_percent' => '80',
            'overage_action' => 'notify',
            'auto_unsuspend' => 'on',
            'included_products' => '',
            'included_limit_gb' => '0',
            'included_mode' => 'CNAME only',
            'included_bill' => '',
        ];
        try {
            $stored = Capsule::table('tbladdonmodules')->where('module', self::MODULE)
                ->pluck('value', 'setting');
            $stored = is_object($stored) && method_exists($stored, 'toArray') ? $stored->toArray() : (array) $stored;
        } catch (\Exception $e) {
            $stored = [];
        }
        return array_merge($defaults, array_filter($stored, function ($v) { return $v !== null; }));
    }

    public static function isOn($value)
    {
        return in_array(strtolower((string) $value), ['on', '1', 'yes', 'true'], true);
    }

    public static function pricePerGb()
    {
        return max(0, (float) self::settings()['price_per_gb']);
    }

    public static function pricePerStorageGb()
    {
        return max(0, (float) self::settings()['price_per_gb_storage']);
    }

    /** Product IDs whose services get a free CDN included. */
    public static function includedProductIds()
    {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) self::settings()['included_products']))));
    }

    // ========================================================== Lookups / API

    public static function zoneForService($serviceId)
    {
        return Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', (int) $serviceId)->first();
    }

    public static function apiKeyForServer($serverId)
    {
        $server = null;
        if ($serverId) {
            // Only our own servers: a hosting service's cPanel server also has an access hash
            $server = Capsule::table('tblservers')->where('id', (int) $serverId)->where('type', self::MODULE)->first();
        }
        if (!$server || trim((string) $server->accesshash) === '') {
            $server = Capsule::table('tblservers')->where('type', self::MODULE)->where('disabled', 0)
                ->where('accesshash', '!=', '')->orderBy('id')->first();
        }
        return $server ? trim((string) $server->accesshash) : '';
    }

    public static function clientForParams(array $params)
    {
        $key = trim((string) ($params['serveraccesshash'] ?? ''));
        if ($key === '') {
            $key = self::apiKeyForServer($params['serverid'] ?? 0);
        }
        return new BunnyClient($key);
    }

    public static function clientForService($serviceId)
    {
        $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->first();
        return new BunnyClient(self::apiKeyForServer($service ? $service->server : 0));
    }

    /** API client for a provisioned row (works for products, addons and included CDN). */
    public static function clientForRow($row)
    {
        $serverId = (int) ($row->server_id ?? 0);
        if (!$serverId) {
            $serverId = (int) Capsule::table('tblhosting')->where('id', (int) $row->service_id)->value('server');
        }
        return new BunnyClient(self::apiKeyForServer($serverId));
    }

    /** WHMCS status of whatever the CDN belongs to: the product, the addon or the hosting service. */
    public static function serviceStatus($row)
    {
        if (($row->source ?? 'product') === 'addon' && !empty($row->addon_id)) {
            return (string) Capsule::table('tblhostingaddons')->where('id', (int) $row->addon_id)->value('status');
        }
        return (string) Capsule::table('tblhosting')->where('id', (int) $row->service_id)->value('domainstatus');
    }

    public static function decodeList($json)
    {
        $list = json_decode((string) $json, true);
        return is_array($list) ? array_values(array_unique($list)) : [];
    }

    public static function touchZone($serviceId, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', (int) $serviceId)->update($data);
    }

    // ================================================================= Money

    public static function bytesToGb($bytes)
    {
        return round(((float) $bytes) / self::BYTES_PER_GB, 4);
    }

    /** Our price for a byte count, in the WHMCS default currency. */
    public static function priceForBytes($bytes)
    {
        return round(self::bytesToGb($bytes) * self::pricePerGb(), 2);
    }

    public static function clientCurrency($userId)
    {
        if (function_exists('getCurrency')) {
            $c = getCurrency($userId);
            if (!empty($c['id'])) {
                return $c;
            }
        }
        $currencyId = (int) Capsule::table('tblclients')->where('id', (int) $userId)->value('currency');
        $row = $currencyId ? Capsule::table('tblcurrencies')->where('id', $currencyId)->first() : null;
        if (!$row) {
            $row = Capsule::table('tblcurrencies')->where('default', 1)->first();
        }
        return $row ? (array) $row : ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'rate' => 1];
    }

    /** Convert an amount in the default currency to the client's currency. */
    public static function toClientCurrency($amount, array $currency)
    {
        $rate = (float) ($currency['rate'] ?? 1);
        return round($amount * ($rate > 0 ? $rate : 1), 2);
    }

    public static function formatMoney($amount, array $currency)
    {
        if (function_exists('formatCurrency') && !empty($currency['id'])) {
            return (string) formatCurrency($amount, $currency['id']);
        }
        return ($currency['prefix'] ?? '') . number_format((float) $amount, 2) . ($currency['suffix'] ?? '');
    }

    public static function defaultCurrency()
    {
        $row = Capsule::table('tblcurrencies')->where('default', 1)->first();
        return $row ? (array) $row : ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'rate' => 1];
    }

    public static function formatBytes($bytes)
    {
        $bytes = (float) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $pow = min((int) floor(log($bytes, 1000)), count($units) - 1);
        return round($bytes / pow(1000, $pow), 2) . ' ' . $units[$pow];
    }

    // ============================================================ Provisioning

    public static function normaliseDomain($domain)
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $domain)) {
            $ascii = idn_to_ascii($domain, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            $domain = $ascii ?: $domain;
        }
        return $domain;
    }

    public static function isValidHostname($host)
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i', $host);
    }

    // ----------------------------------------------------------- product options

    /** "dns" = client moves nameservers to bunny.net, "cname" = client keeps their DNS. */
    public static function setupMode(array $params)
    {
        return stripos((string) ($params['configoption4'] ?? ''), 'cname') !== false ? 'cname' : 'dns';
    }

    /** True when the pull zone should serve files from a new storage zone. */
    public static function usesStorage(array $params)
    {
        return stripos((string) ($params['configoption5'] ?? ''), 'storage') !== false;
    }

    /** Storage region code from a dropdown value such as "NY (New York)". */
    public static function storageRegion(array $params)
    {
        $code = strtoupper(trim(strtok((string) ($params['configoption6'] ?? 'DE'), ' (')));
        return preg_match('/^[A-Z]{2,3}$/', $code) ? $code : 'DE';
    }

    /**
     * Monthly bandwidth limit in bytes (0 = unlimited). A configurable option whose
     * name contains "Bandwidth" (e.g. "100 GB", "1 TB", "Unlimited") wins over the
     * product setting, so bandwidth packages can be sold as upgrades.
     */
    public static function limitBytes(array $params)
    {
        foreach (($params['configoptions'] ?? []) as $name => $value) {
            if (preg_match('/bandwidth|traffic/i', (string) $name)) {
                $bytes = self::parseSize($value);
                if ($bytes !== null) {
                    return $bytes;
                }
            }
        }
        if (isset($params['_shcdn_limit_bytes'])) {
            return (int) $params['_shcdn_limit_bytes'];
        }
        $gb = (float) ($params['configoption2'] ?? 0);
        return $gb > 0 ? (int) round($gb * self::BYTES_PER_GB) : 0;
    }

    /** "500", "500 GB", "1.5TB", "Unlimited" -> bytes; null when not understood. */
    public static function parseSize($value)
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        if (strpos($v, 'unlimited') !== false || $v === '0') {
            return 0;
        }
        if (!preg_match('/^([\d.]+)\s*(tb|gb|mb)?/', $v, $m)) {
            return null;
        }
        $mult = ['tb' => 1000 * self::BYTES_PER_GB, 'gb' => self::BYTES_PER_GB, 'mb' => self::BYTES_PER_GB / 1000];
        $unit = !empty($m[2]) ? $m[2] : 'gb';
        return (int) round((float) $m[1] * $mult[$unit]);
    }

    public static function normaliseHost($host)
    {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = idn_to_ascii($host, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            $host = $ascii ?: $host;
        }
        return $host;
    }

    /** True when $host is the root of its DNS zone (example.com, example.co.uk). */
    public static function isZoneApex($host)
    {
        if (function_exists('dns_get_record')) {
            foreach ((array) @dns_get_record($host, DNS_SOA) as $r) {
                if (strcasecmp(rtrim($r['host'] ?? '', '.'), $host) === 0) {
                    return true;
                }
            }
        }
        return substr_count($host, '.') === 1;
    }

    /** Hostnames the pull zone should answer for. */
    public static function hostnamesFor($mode, $domain)
    {
        if ($mode === 'dns') {
            return [$domain, 'www.' . $domain];
        }
        if (strpos($domain, 'www.') !== 0 && self::isZoneApex($domain)) {
            return [$domain, 'www.' . $domain];
        }
        return [$domain];
    }

    /**
     * Provision the CDN for a product, product addon or included-with-hosting
     * service. Safe to re-run: an already provisioned service is left alone.
     *
     * dns mode:   DNS zone + pull zone, existing records copied, @/www point to the CDN.
     * cname mode: pull zone + hostnames only; the client adds a CNAME at their DNS host.
     * storage:    a storage zone is created and used as the pull zone's origin.
     */
    public static function provision(array $params)
    {
        self::ensureSchema();
        $serviceId = (int) $params['serviceid'];
        $mode = self::setupMode($params);
        $useStorage = self::usesStorage($params);
        $domain = $mode === 'dns' ? self::normaliseDomain($params['domain'] ?? '') : self::normaliseHost($params['domain'] ?? '');

        if (!self::isValidHostname($domain)) {
            throw new \Exception('The service domain "' . $domain . '" is not a valid domain name.');
        }

        $bunny = self::clientForParams($params);

        $existing = self::zoneForService($serviceId);
        if ($existing && $existing->pull_zone_id) {
            $bunny->getPullZone($existing->pull_zone_id); // throws if it vanished
            return 'already provisioned';
        }

        $other = Capsule::table('mod_seekahostcdn_dns_zones')->where('domain', $domain)
            ->where('service_id', '!=', $serviceId)->first();
        if ($other) {
            throw new \Exception("Domain {$domain} is already used by CDN service #{$other->service_id}.");
        }

        // Read the domain's current DNS while it is still live at the old provider
        $discovered = $mode === 'dns' ? self::discoverLocalDns($domain) : self::hostARecords($domain);
        $origin = $useStorage ? '' : self::determineOrigin($params, $domain, $discovered);

        $createdDns = null;
        $createdPull = null;
        $createdStorage = null;
        $dnsZone = [];
        $dnsZoneId = '';
        try {
            if ($mode === 'dns') {
                // Re-use a DNS zone left behind by an earlier failed attempt
                try {
                    $dnsZone = $bunny->createDnsZone($domain);
                    $createdDns = $dnsZone['Id'];
                } catch (BunnyException $e) {
                    $dnsZone = $bunny->findDnsZone($domain);
                    if (!$dnsZone) {
                        throw $e;
                    }
                }
                $dnsZoneId = $dnsZone['Id'];
            }

            $storage = null;
            if ($useStorage) {
                $storage = self::createStorageZone($bunny, $serviceId, $domain, self::storageRegion($params));
                $createdStorage = $storage['Id'];
            }

            $pullZone = self::createPullZone($bunny, $serviceId, $domain, $origin, $params, $createdStorage);
            $createdPull = $pullZone['Id'];
            $pullZoneId = $pullZone['Id'];
            $cdnHost = self::systemHostname($pullZone);

            $hostnames = [];
            foreach (self::hostnamesFor($mode, $domain) as $host) {
                try {
                    $bunny->addHostname($pullZoneId, $host);
                    $hostnames[] = $host;
                } catch (BunnyException $e) {
                    if (stripos($e->getMessage(), 'already') !== false) {
                        $hostnames[] = $host;
                    } else {
                        logActivity("SeekaHost CDN: could not add hostname {$host} to pull zone {$pullZoneId}: " . $e->getMessage());
                    }
                }
            }

            if ($mode === 'dns') {
                $records = self::mergeRecords($discovered, self::discoverViaBunny($bunny, $dnsZoneId));
                self::importRecords($bunny, $dnsZoneId, $records, true);
                self::pointToCdn($bunny, $dnsZoneId, $pullZoneId, $cdnHost);
            }

            $ns1 = $dnsZone['Nameserver1'] ?? ($mode === 'dns' ? 'kiki.bunny.net' : '');
            $ns2 = $dnsZone['Nameserver2'] ?? ($mode === 'dns' ? 'coco.bunny.net' : '');
            $addonId = (int) ($params['addonId'] ?? $params['addonid'] ?? 0);
            $source = $params['_shcdn_source'] ?? ($addonId ? 'addon' : 'product');

            Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', $serviceId)->delete();
            Capsule::table('mod_seekahostcdn_dns_zones')->insert([
                'service_id' => $serviceId,
                'dns_zone_id' => (string) $dnsZoneId,
                'pull_zone_id' => (string) $pullZoneId,
                'domain' => $domain,
                'nameserver_1' => $ns1,
                'nameserver_2' => $ns2,
                'cdn_url' => $cdnHost,
                'origin_url' => $useStorage ? 'storage:' . $storage['Name'] : $origin,
                'hostnames' => json_encode($hostnames),
                'ssl_hostnames' => json_encode([]),
                'nameservers_detected' => 0,
                'suspended' => 0,
                'mode' => $mode,
                'source' => $source,
                'addon_id' => $addonId,
                'server_id' => (int) ($params['serverid'] ?? 0),
                'limit_bytes' => self::limitBytes($params),
                'storage_zone_id' => $storage ? (string) $storage['Id'] : null,
                'storage_zone_name' => $storage ? (string) $storage['Name'] : null,
                'storage_region' => $storage ? self::storageRegion($params) : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Only a standalone CDN product owns the WHMCS service fields
            if ($source === 'product') {
                Capsule::table('tblhosting')->where('id', $serviceId)->update([
                    'dedicatedip' => (string) $pullZoneId,
                    'ns1' => $ns1,
                    'ns2' => $ns2,
                ]);
            }
        } catch (\Exception $e) {
            // Roll back anything we created so bunny.net does not bill for orphans
            foreach ([['deletePullZone', $createdPull], ['deleteStorageZone', $createdStorage], ['deleteDnsZone', $createdDns]] as $call) {
                if ($call[1]) {
                    try { $bunny->{$call[0]}($call[1]); } catch (\Exception $ignored) {}
                }
            }
            throw $e;
        }

        logActivity("SeekaHost CDN: provisioned {$domain} ({$mode} mode, {$source} #{$serviceId}, pull zone {$pullZoneId})");
        return 'success';
    }

    /** Current A records of a hostname, shaped like discovered apex records. */
    private static function hostARecords($host)
    {
        $out = [];
        if (function_exists('dns_get_record')) {
            foreach ((array) @dns_get_record($host, DNS_A) as $r) {
                if (!empty($r['ip'])) {
                    $out[] = self::rec(BunnyClient::REC_A, '', $r['ip'], $r['ttl'] ?? 3600);
                }
            }
        }
        return $out;
    }

    private static function zoneName($serviceId, $domain, $max)
    {
        $slug = substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($domain)), 0, $max - 12);
        return trim('sh' . $serviceId . '-' . $slug, '-');
    }

    private static function createStorageZone(BunnyClient $bunny, $serviceId, $domain, $region)
    {
        $name = self::zoneName($serviceId, $domain, 60);
        try {
            return $bunny->createStorageZone($name, $region);
        } catch (BunnyException $e) {
            if (stripos($e->getMessage(), 'taken') === false && stripos($e->getMessage(), 'exists') === false) {
                throw $e;
            }
            return $bunny->createStorageZone(substr($name, 0, 50) . '-' . substr(md5(uniqid('', true)), 0, 6), $region);
        }
    }

    private static function createPullZone(BunnyClient $bunny, $serviceId, $domain, $origin, array $params, $storageZoneId = null)
    {
        $name = self::zoneName($serviceId, $domain, 52);
        $data = [
            'Name' => $name,
            'Type' => strtolower((string) ($params['configoption3'] ?? '')) === 'volume' ? 1 : 0,
        ];
        if ($storageZoneId) {
            $data['StorageZoneId'] = (int) $storageZoneId;
            $data['OriginType'] = 2;
        } else {
            $data['OriginUrl'] = $origin;
        }
        try {
            $zone = $bunny->createPullZone($data);
        } catch (BunnyException $e) {
            if (stripos($e->getMessage(), 'taken') === false && stripos($e->getMessage(), 'exists') === false) {
                throw $e;
            }
            $data['Name'] = substr($name, 0, 50) . '-' . substr(md5(uniqid('', true)), 0, 6);
            $zone = $bunny->createPullZone($data);
        }

        $update = ['MonthlyBandwidthLimit' => self::limitBytes($params)];
        if (!$storageZoneId) {
            // Send the visitor's Host header so an IP origin serves the right site
            $update['AddHostHeader'] = true;
        }
        $bunny->updatePullZone($zone['Id'], $update);
        return $zone;
    }

    public static function systemHostname(array $pullZone)
    {
        foreach (($pullZone['Hostnames'] ?? []) as $h) {
            if (!empty($h['IsSystemHostname'])) {
                return $h['Value'];
            }
        }
        return ($pullZone['Name'] ?? '') . '.b-cdn.net';
    }

    /**
     * Origin precedence: client custom field "Origin Server", product setting,
     * then the domain's current A record. Never the domain itself (that would loop
     * once the nameservers move to bunny.net).
     */
    public static function determineOrigin(array $params, $domain, array $discovered)
    {
        $candidates = [];
        foreach (($params['customfields'] ?? []) as $name => $value) {
            if (preg_match('/^origin/i', (string) $name) && trim((string) $value) !== '') {
                $candidates[] = trim((string) $value);
            }
        }
        $productOrigin = trim((string) ($params['configoption1'] ?? ''));
        if ($productOrigin !== '' && stripos($productOrigin, 'yourdomain.com') === false) {
            $candidates[] = $productOrigin;
        }

        foreach ($candidates as $candidate) {
            $url = preg_match('#^https?://#i', $candidate) ? $candidate : 'http://' . $candidate;
            $parts = parse_url($url);
            $host = strtolower($parts['host'] ?? '');
            if ($host === '') {
                continue;
            }
            if ($host === $domain || $host === 'www.' . $domain) {
                $ip = self::firstApexIp($discovered) ?: self::resolveIp($host);
                if (!$ip) {
                    continue;
                }
                $url = $parts['scheme'] . '://' . $ip . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
            return rtrim($url, '/');
        }

        $ip = self::firstApexIp($discovered);
        if ($ip) {
            return 'http://' . $ip;
        }
        throw new \Exception('Could not determine the origin server: the domain has no A record and no origin is set on the product or in the "Origin Server" custom field.');
    }

    private static function firstApexIp(array $records)
    {
        foreach ($records as $r) {
            if ($r['Name'] === '' && $r['Type'] === BunnyClient::REC_A) {
                return $r['Value'];
            }
        }
        return null;
    }

    private static function resolveIp($host)
    {
        $ip = gethostbyname($host);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    // ============================================================ DNS discovery

    /** Look up the domain's live public DNS for the apex and common subdomains. */
    public static function discoverLocalDns($domain)
    {
        if (!function_exists('dns_get_record')) {
            return [];
        }
        $records = [];
        $wildcards = [];

        $apex = (array) @dns_get_record($domain, DNS_A + DNS_AAAA + DNS_MX + DNS_TXT + DNS_CAA);
        foreach ($apex as $r) {
            if (strcasecmp(rtrim($r['host'] ?? '', '.'), $domain) === 0 && ($converted = self::fromPhpDns($r, ''))) {
                $records[] = $converted;
            }
        }

        foreach (self::$probeNames as $label) {
            $host = $label . '.' . $domain;
            $parent = strpos($label, '.') === false ? '' : substr($label, strpos($label, '.') + 1);

            // Records a random sibling also returns come from a wildcard, not a real name
            if (!isset($wildcards[$parent])) {
                $wildcards[$parent] = [];
                $wildName = $parent === '' ? '*' : '*.' . $parent;
                foreach (self::lookupName('shcdn' . substr(md5(uniqid('', true)), 0, 10) . ($parent === '' ? '' : '.' . $parent) . '.' . $domain, $wildName) as $w) {
                    $key = $w['Type'] . '|' . strtolower($w['Value']);
                    $wildcards[$parent][$key] = true;
                    // The apex "*" already answers for deeper names
                    if ($parent === '' || !isset($wildcards[''][$key])) {
                        $records[] = $w;
                    }
                }
            }

            foreach (self::lookupName($host, $label) as $r) {
                if (!isset($wildcards[$parent][$r['Type'] . '|' . strtolower($r['Value'])])) {
                    $records[] = $r;
                }
            }
        }

        return self::mergeRecords($records, []);
    }

    /** All records for one name, with CNAME taking precedence as DNS requires. */
    private static function lookupName($host, $name)
    {
        $isService = strpos($name, '_') === 0 && preg_match('/\._(tcp|udp|tls)$/', $name);
        $out = [];
        $found = (array) @dns_get_record($host, $isService ? DNS_SRV : DNS_CNAME);
        foreach ($found as $r) {
            if (strcasecmp(rtrim($r['host'] ?? '', '.'), $host) === 0 && ($converted = self::fromPhpDns($r, $name))) {
                $out[] = $converted;
            }
        }
        if ($out || $isService) {
            return $out;
        }
        foreach ((array) @dns_get_record($host, DNS_A + DNS_AAAA + DNS_TXT + DNS_MX) as $r) {
            if (strcasecmp(rtrim($r['host'] ?? '', '.'), $host) === 0 && ($converted = self::fromPhpDns($r, $name))) {
                $out[] = $converted;
            }
        }
        return $out;
    }

    private static function fromPhpDns(array $r, $name)
    {
        $ttl = $r['ttl'] ?? 3600;
        switch ($r['type'] ?? '') {
            case 'A':
                return self::rec(BunnyClient::REC_A, $name, $r['ip'], $ttl);
            case 'AAAA':
                return self::rec(BunnyClient::REC_AAAA, $name, $r['ipv6'], $ttl);
            case 'CNAME':
                return self::rec(BunnyClient::REC_CNAME, $name, rtrim($r['target'], '.'), $ttl);
            case 'MX':
                return self::rec(BunnyClient::REC_MX, $name, rtrim($r['target'], '.'), $ttl, ['Priority' => (int) $r['pri']]);
            case 'TXT':
                $txt = isset($r['entries']) ? implode('', $r['entries']) : ($r['txt'] ?? '');
                return $txt === '' ? null : self::rec(BunnyClient::REC_TXT, $name, $txt, $ttl);
            case 'SRV':
                return self::rec(BunnyClient::REC_SRV, $name, rtrim($r['target'], '.'), $ttl, [
                    'Priority' => (int) $r['pri'], 'Weight' => (int) $r['weight'], 'Port' => (int) $r['port'],
                ]);
            case 'CAA':
                return self::rec(BunnyClient::REC_CAA, $name, $r['value'] ?? '', $ttl, [
                    'Flags' => (int) ($r['flags'] ?? 0), 'Tag' => $r['tag'] ?? 'issue',
                ]);
        }
        return null;
    }

    private static function rec($type, $name, $value, $ttl, array $extra = [])
    {
        return array_merge([
            'Type' => (int) $type,
            'Name' => (string) $name,
            'Value' => (string) $value,
            'Ttl' => max(60, min(86400, (int) $ttl ?: 3600)),
        ], $extra);
    }

    /** Use bunny.net's own scanner as an extra source. Failures are ignored. */
    public static function discoverViaBunny(BunnyClient $bunny, $dnsZoneId)
    {
        try {
            $bunny->startDnsScan($dnsZoneId);
            for ($i = 0; $i < 5; $i++) {
                sleep(2);
                $res = $bunny->getDnsScanResult($dnsZoneId);
                $status = $res['Status'] ?? null;
                if ($status === 3 || $status === 'Failed') {
                    return [];
                }
                if (!empty($res['Records']) || $status === 2 || $status === 'Completed') {
                    break;
                }
            }
        } catch (\Exception $e) {
            return [];
        }

        $names = array_flip(array_map('strtoupper', BunnyClient::$recordTypeNames));
        $out = [];
        foreach (($res['Records'] ?? []) as $r) {
            $type = $r['Type'] ?? null;
            if (!is_numeric($type)) {
                $type = $names[strtoupper((string) $type)] ?? null;
            }
            if ($type === null || !in_array((int) $type, [0, 1, 2, 3, 4, 8, 9], true)) {
                continue;
            }
            $extra = [];
            foreach (['Priority', 'Weight', 'Port', 'Flags', 'Tag'] as $k) {
                if (isset($r[$k])) {
                    $extra[$k] = $r[$k];
                }
            }
            $name = rtrim((string) ($r['Name'] ?? ''), '.');
            $out[] = self::rec((int) $type, $name === '@' ? '' : $name, rtrim((string) ($r['Value'] ?? ''), '.'), $r['Ttl'] ?? 3600, $extra);
        }
        return $out;
    }

    private static function recordKey(array $r)
    {
        return (int) $r['Type'] . '|' . strtolower((string) $r['Name']) . '|' . strtolower(rtrim((string) $r['Value'], '.'));
    }

    public static function mergeRecords(array $a, array $b)
    {
        $out = [];
        foreach (array_merge($a, $b) as $r) {
            if (($r['Value'] ?? '') === '') {
                continue;
            }
            $out[self::recordKey($r)] = $r;
        }
        return array_values($out);
    }

    /**
     * Add records that are not already in the zone. Apex/www web records are
     * skipped when $skipWebRecords is set, because those names point at the CDN.
     */
    public static function importRecords(BunnyClient $bunny, $dnsZoneId, array $records, $skipWebRecords)
    {
        $current = $bunny->getDnsRecords($dnsZoneId);
        $have = [];
        $cdnNames = [];
        foreach ($current as $r) {
            $have[self::recordKey($r)] = true;
            if ((int) $r['Type'] === BunnyClient::REC_PULLZONE) {
                $cdnNames[strtolower($r['Name'])] = true;
            }
        }

        $added = 0;
        $failed = [];
        foreach ($records as $r) {
            $isWeb = in_array((int) $r['Type'], [BunnyClient::REC_A, BunnyClient::REC_AAAA, BunnyClient::REC_CNAME], true);
            $name = strtolower($r['Name']);
            if ($isWeb && (($skipWebRecords && in_array($name, ['', 'www'], true)) || isset($cdnNames[$name]))) {
                continue;
            }
            if (isset($have[self::recordKey($r)])) {
                continue;
            }
            try {
                $bunny->addDnsRecord($dnsZoneId, $r);
                $have[self::recordKey($r)] = true;
                $added++;
            } catch (\Exception $e) {
                $failed[] = (BunnyClient::$recordTypeNames[$r['Type']] ?? $r['Type']) . ' ' . ($r['Name'] ?: '@') . ': ' . $e->getMessage();
            }
        }
        return ['added' => $added, 'failed' => $failed];
    }

    /** Replace apex/www A, AAAA and CNAME records with CDN (pull zone) records. */
    public static function pointToCdn(BunnyClient $bunny, $dnsZoneId, $pullZoneId, $cdnHost)
    {
        $records = $bunny->getDnsRecords($dnsZoneId);
        foreach (['', 'www'] as $name) {
            $hasCdn = false;
            foreach ($records as $r) {
                if (strtolower($r['Name']) !== $name) {
                    continue;
                }
                $type = (int) $r['Type'];
                $ours = (string) ($r['PullZoneId'] ?? '') === (string) $pullZoneId
                    || (string) ($r['LinkName'] ?? '') === (string) $pullZoneId
                    || strcasecmp((string) ($r['Value'] ?? ''), $cdnHost) === 0;
                if ($type === BunnyClient::REC_PULLZONE && $ours) {
                    $hasCdn = true;
                } elseif (in_array($type, [BunnyClient::REC_A, BunnyClient::REC_AAAA, BunnyClient::REC_CNAME, BunnyClient::REC_PULLZONE], true)) {
                    $bunny->deleteDnsRecord($dnsZoneId, $r['Id']);
                }
            }
            if (!$hasCdn) {
                $bunny->addDnsRecord($dnsZoneId, self::cdnRecord($name, $pullZoneId, $cdnHost));
            }
        }
    }

    private static function cdnRecord($name, $pullZoneId, $cdnHost)
    {
        return [
            'Type' => BunnyClient::REC_PULLZONE,
            'Name' => $name,
            'Value' => $cdnHost,
            'PullZoneId' => (int) $pullZoneId,
            'Ttl' => 300,
        ];
    }

    // ========================================================== Nameservers

    /**
     * True when the domain's public NS records are bunny.net's. bunny.net's own
     * "NameserversDetected" flag is not reliable for this (it is set on new zones).
     */
    public static function nameserversPointed($domain, array $zone = [])
    {
        if (!function_exists('dns_get_record')) {
            return false;
        }
        $expected = array_filter(array_map(function ($ns) {
            return strtolower(rtrim((string) $ns, '.'));
        }, [$zone['Nameserver1'] ?? '', $zone['Nameserver2'] ?? '']));

        $found = [];
        foreach ((array) @dns_get_record($domain, DNS_NS) as $r) {
            if (!empty($r['target'])) {
                $found[] = strtolower(rtrim($r['target'], '.'));
            }
        }
        if (!$found) {
            return false;
        }
        foreach ($found as $ns) {
            if (!in_array($ns, $expected, true) && substr($ns, -strlen('.bunny.net')) !== '.bunny.net') {
                return false;
            }
        }
        return true;
    }

    // ============================================================ Connection

    /** True when a hostname's public DNS sends visitors to the pull zone. */
    public static function hostPointsToCdn($host, $cdnHost)
    {
        if (!function_exists('dns_get_record') || $cdnHost === '') {
            return false;
        }
        foreach ((array) @dns_get_record($host, DNS_CNAME) as $r) {
            $target = strtolower(rtrim((string) ($r['target'] ?? ''), '.'));
            if ($target === strtolower($cdnHost) || substr($target, -strlen('.b-cdn.net')) === '.b-cdn.net') {
                return true;
            }
        }
        // Flattened records (ALIAS/ANAME at the root) resolve to the CDN's own IPs
        $ips = function ($h) {
            $out = [];
            foreach ((array) @dns_get_record($h, DNS_A) as $r) {
                if (!empty($r['ip'])) {
                    $out[] = $r['ip'];
                }
            }
            return $out;
        };
        $hostIps = $ips($host);
        return $hostIps && array_intersect($hostIps, $ips($cdnHost));
    }

    /** Whether the client has finished their DNS step (nameservers or CNAME). */
    public static function isConnected($row, array $zone = [])
    {
        if (($row->mode ?? 'dns') === 'cname') {
            $hosts = self::decodeList($row->hostnames);
            return $hosts && self::hostPointsToCdn($hosts[0], $row->cdn_url);
        }
        return self::nameserversPointed($row->domain, $zone ?: [
            'Nameserver1' => $row->nameserver_1, 'Nameserver2' => $row->nameserver_2,
        ]);
    }

    // =================================================================== SSL

    /**
     * Request free certificates for hostnames that already point at the CDN
     * (bunny.net nameservers in dns mode, a CNAME per hostname in cname mode).
     */
    public static function issueSsl($row, BunnyClient $bunny = null)
    {
        $bunny = $bunny ?: self::clientForRow($row);
        $hostnames = self::decodeList($row->hostnames);
        $done = array_values(array_intersect(self::decodeList($row->ssl_hostnames), $hostnames));
        $update = [];

        if (($row->mode ?? 'dns') === 'cname') {
            $ready = array_values(array_filter($hostnames, function ($h) use ($row) {
                return self::hostPointsToCdn($h, $row->cdn_url);
            }));
            $detected = $hostnames && in_array($hostnames[0], $ready, true);
        } else {
            $zone = $bunny->getDnsZone($row->dns_zone_id);
            $detected = self::nameserversPointed($row->domain, $zone);
            $ready = $detected ? $hostnames : [];
            $update['nameserver_1'] = $zone['Nameserver1'] ?? $row->nameserver_1;
            $update['nameserver_2'] = $zone['Nameserver2'] ?? $row->nameserver_2;
        }

        $errors = [];
        foreach (array_diff($ready, $done) as $host) {
            try {
                $bunny->loadFreeCertificate($host);
                try { $bunny->setForceSsl($row->pull_zone_id, $host, true); } catch (\Exception $ignored) {}
                $done[] = $host;
            } catch (\Exception $e) {
                $errors[] = $host . ': ' . $e->getMessage();
            }
        }

        $update['ssl_hostnames'] = json_encode(array_values(array_unique($done)));
        $update['nameservers_detected'] = $detected ? 1 : 0;
        self::touchZone($row->service_id, $update);
        return ['detected' => $detected, 'issued' => $done, 'errors' => $errors, 'pending' => array_values(array_diff($hostnames, $done))];
    }

    // ===================================================== Suspend / terminate

    /** Stop serving: a 1-byte monthly limit makes bunny.net refuse requests. DNS keeps working. */
    public static function suspendRow($row, BunnyClient $bunny = null)
    {
        ($bunny ?: self::clientForRow($row))->updatePullZone($row->pull_zone_id, ['MonthlyBandwidthLimit' => 1]);
        self::touchZone($row->service_id, ['suspended' => 1]);
    }

    public static function unsuspendRow($row, BunnyClient $bunny = null, $limitBytes = null)
    {
        $limit = $limitBytes !== null ? (int) $limitBytes : (int) $row->limit_bytes;
        ($bunny ?: self::clientForRow($row))->updatePullZone($row->pull_zone_id, ['MonthlyBandwidthLimit' => $limit]);
        self::touchZone($row->service_id, ['suspended' => 0, 'limit_bytes' => $limit, 'overage_month' => null]);
    }

    public static function suspend(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if ($row) {
            self::suspendRow($row, self::clientForParams($params));
        }
        return 'success';
    }

    public static function unsuspend(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if ($row) {
            self::unsuspendRow($row, self::clientForParams($params), self::limitBytes($params));
        }
        return 'success';
    }

    public static function changePackage(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if (!$row) {
            return 'success';
        }
        $limit = self::limitBytes($params);
        self::touchZone($row->service_id, ['limit_bytes' => $limit]);
        if (!$row->suspended) {
            self::clientForParams($params)->updatePullZone($row->pull_zone_id, ['MonthlyBandwidthLimit' => $limit]);
        }
        return 'success';
    }

    public static function terminate(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if (!$row) {
            if (empty($params['addonId']) && empty($params['_shcdn_source'])) {
                Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->update(['dedicatedip' => '']);
            }
            return 'success';
        }
        return self::terminateRow($row, self::clientForParams($params));
    }

    public static function terminateRow($row, BunnyClient $bunny = null)
    {
        $bunny = $bunny ?: self::clientForRow($row);

        // Bill unbilled usage before the zone (and its statistics) disappear
        if (self::isOn(self::settings()['enable_billing'])) {
            $prev = date('Y-m', strtotime('first day of last month'));
            self::billServiceMonth($row, $prev, $bunny);
            self::billServiceMonth($row, date('Y-m'), $bunny, date('Y-m-d'));
        }

        $calls = [['deletePullZone', $row->pull_zone_id]];
        if (!empty($row->dns_zone_id)) {
            $calls[] = ['deleteDnsZone', $row->dns_zone_id];
        }
        if (!empty($row->storage_zone_id)) {
            $calls[] = ['deleteStorageZone', $row->storage_zone_id];
        }
        foreach ($calls as $call) {
            try {
                $bunny->{$call[0]}($call[1]);
            } catch (BunnyException $e) {
                if ($e->httpCode !== 404) {
                    throw $e;
                }
            }
        }

        Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', $row->service_id)->delete();
        if (($row->source ?? 'product') === 'product') {
            Capsule::table('tblhosting')->where('id', $row->service_id)->update(['dedicatedip' => '']);
        }
        logActivity("SeekaHost CDN: terminated {$row->domain} (service #{$row->service_id})");
        return 'success';
    }

    // ================================================================= Usage

    /** Snapshot month-to-date bandwidth, storage and bunny.net cost for one zone. */
    public static function syncUsage($row, BunnyClient $bunny = null)
    {
        $bunny = $bunny ?: self::clientForRow($row);
        $zone = $bunny->getPullZone($row->pull_zone_id);
        $bytes = (int) ($zone['MonthlyBandwidthUsed'] ?? 0);
        $cost = (float) ($zone['MonthlyCharges'] ?? 0);
        $limit = (int) ($zone['MonthlyBandwidthLimit'] ?? 0);
        $storageBytes = 0;
        if (!empty($row->storage_zone_id)) {
            try {
                $storageBytes = (int) ($bunny->getStorageZone($row->storage_zone_id)['StorageUsed'] ?? 0);
            } catch (\Exception $e) {
                // keep bandwidth figures even if storage is unavailable
            }
        }
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $data = ['zone_id' => (string) $row->pull_zone_id, 'bandwidth_bytes' => $bytes, 'cost' => $cost,
            'storage_bytes' => $storageBytes, 'updated_at' => $now];
        $exists = Capsule::table('mod_seekahostcdn_usage')->where('service_id', $row->service_id)
            ->where('usage_date', $today)->exists();
        if ($exists) {
            Capsule::table('mod_seekahostcdn_usage')->where('service_id', $row->service_id)->where('usage_date', $today)->update($data);
        } else {
            Capsule::table('mod_seekahostcdn_usage')->insert($data + [
                'service_id' => $row->service_id, 'usage_date' => $today, 'created_at' => $now,
            ]);
        }

        // Rows created before limits were stored learn them from bunny.net once
        if ((int) $row->limit_bytes === 0 && !$row->suspended && $limit > 1) {
            self::touchZone($row->service_id, ['limit_bytes' => $limit]);
            $row->limit_bytes = $limit;
        }

        if (($row->source ?? 'product') === 'product') {
            Capsule::table('tblhosting')->where('id', $row->service_id)->update([
                'bwusage' => (int) round($bytes / 1048576),
                'bwlimit' => (int) round(((int) $row->limit_bytes) / 1048576),
                'lastupdate' => $now,
            ]);
        }

        self::checkOverage($row, $bytes, $bunny);
        return ['bytes' => $bytes, 'bunny_cost' => $cost, 'limit' => $limit, 'storage_bytes' => $storageBytes];
    }

    /** Sync every zone, reset last month's overage suspensions. Returns [synced, errors[]]. */
    public static function syncAllUsage()
    {
        self::ensureSchema();
        self::resetOverages();
        $synced = 0;
        $errors = [];
        foreach (Capsule::table('mod_seekahostcdn_dns_zones')->get() as $row) {
            try {
                $client = self::clientForRow($row);
                self::syncUsage($row, $client);
                if (count(self::decodeList($row->ssl_hostnames)) < count(self::decodeList($row->hostnames)) || !$row->nameservers_detected) {
                    self::issueSsl(self::zoneForService($row->service_id), $client);
                }
                $synced++;
            } catch (\Exception $e) {
                $errors[] = "Service #{$row->service_id}: " . $e->getMessage();
            }
        }
        return [$synced, $errors];
    }

    // ============================================================== Overage

    /**
     * Warn at the configured percentage, and at 100% email the client, add an
     * admin To-Do and optionally suspend. Each alert is sent once per month.
     */
    public static function checkOverage($row, $bytes, BunnyClient $bunny = null)
    {
        $limit = (int) $row->limit_bytes;
        if ($limit <= 0 || $row->suspended || !in_array(self::serviceStatus($row), ['Active'], true)) {
            return;
        }
        $settings = self::settings();
        $warnAt = max(1, min(99, (int) $settings['warn_percent'] ?: 80));
        $percent = $bytes / $limit * 100;
        $month = date('Y-m');
        $vars = [
            'cdn_domain' => $row->domain,
            'cdn_used' => self::formatBytes($bytes),
            'cdn_limit' => self::formatBytes($limit),
            'cdn_percent' => (string) round($percent),
        ];

        if ($percent >= 100) {
            if (!self::alertOnce($row->service_id, $month, 100)) {
                return;
            }
            $suspend = $settings['overage_action'] === 'suspend';
            self::sendClientEmail($row->service_id, 'SeekaHost CDN Bandwidth Limit Reached', $vars);
            self::addTodo("CDN bandwidth limit reached: {$row->domain}",
                "Service #{$row->service_id} used {$vars['cdn_used']} of {$vars['cdn_limit']} this month."
                . ($suspend ? ' The CDN has been suspended until next month.' : ' bunny.net stops serving the zone until next month or until the limit is raised.'));
            if ($suspend) {
                self::suspendForOverage($row, $bunny);
            }
        } elseif ($percent >= $warnAt && self::alertOnce($row->service_id, $month, $warnAt)) {
            self::sendClientEmail($row->service_id, 'SeekaHost CDN Bandwidth Warning', $vars);
        }
    }

    private static function suspendForOverage($row, BunnyClient $bunny = null)
    {
        $reason = 'CDN bandwidth limit exceeded';
        if (($row->source ?? 'product') === 'product') {
            localAPI('ModuleSuspend', ['serviceid' => $row->service_id, 'suspendreason' => $reason]);
            // ModuleSuspend calls our SuspendAccount; make sure the zone is stopped either way
            $fresh = self::zoneForService($row->service_id);
            if ($fresh && !$fresh->suspended) {
                self::suspendRow($fresh, $bunny);
            }
        } else {
            // Addon / included CDN: stop the CDN only, never the customer's hosting
            self::suspendRow($row, $bunny);
        }
        self::touchZone($row->service_id, ['overage_month' => date('Y-m')]);
        logActivity("SeekaHost CDN: suspended {$row->domain} (service #{$row->service_id}) for exceeding its bandwidth limit");
    }

    /** At the start of a new month lift suspensions that were only for bandwidth. */
    public static function resetOverages()
    {
        if (!self::isOn(self::settings()['auto_unsuspend'])) {
            return;
        }
        $rows = Capsule::table('mod_seekahostcdn_dns_zones')->whereNotNull('overage_month')
            ->where('overage_month', '<', date('Y-m'))->get();
        foreach ($rows as $row) {
            try {
                if (($row->source ?? 'product') === 'product') {
                    $service = Capsule::table('tblhosting')->where('id', $row->service_id)->first();
                    if ($service && $service->domainstatus === 'Suspended' && stripos((string) $service->suspendreason, 'bandwidth') !== false) {
                        localAPI('ModuleUnsuspend', ['serviceid' => $row->service_id]);
                    }
                    $fresh = self::zoneForService($row->service_id);
                    if ($fresh && $fresh->suspended && $service && $service->domainstatus === 'Active') {
                        self::unsuspendRow($fresh);
                    }
                } elseif (self::serviceStatus($row) === 'Active') {
                    self::unsuspendRow($row);
                }
                self::touchZone($row->service_id, ['overage_month' => null]);
                logActivity("SeekaHost CDN: lifted bandwidth suspension for {$row->domain} (new month)");
            } catch (\Exception $e) {
                logActivity("SeekaHost CDN: could not lift bandwidth suspension for service #{$row->service_id}: " . $e->getMessage());
            }
        }
    }

    private static function alertOnce($serviceId, $month, $level)
    {
        try {
            Capsule::table('mod_seekahostcdn_alerts')->insert([
                'service_id' => (int) $serviceId, 'alert_month' => $month, 'level' => (int) $level,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Exception $e) {
            return false; // already sent this month
        }
    }

    private static function addTodo($title, $description)
    {
        try {
            Capsule::table('tbltodolist')->insert([
                'date' => date('Y-m-d'), 'title' => $title, 'description' => $description,
                'admin' => 0, 'status' => 'Pending', 'duedate' => date('Y-m-d'),
            ]);
        } catch (\Exception $e) {
            logActivity('SeekaHost CDN: ' . $title);
        }
    }

    // =============================================================== Emails

    private static $emailTemplates = [
        'SeekaHost CDN Bandwidth Warning' => [
            'subject' => 'CDN bandwidth for {$cdn_domain} is at {$cdn_percent}%',
            'message' => '<p>Dear {$client_name},</p><p>Your CDN for <strong>{$cdn_domain}</strong> has used {$cdn_used} of its {$cdn_limit} monthly bandwidth ({$cdn_percent}%).</p><p>When the limit is reached the CDN stops serving content until next month. You can upgrade your bandwidth from the client area at any time.</p><p>{$signature}</p>',
        ],
        'SeekaHost CDN Bandwidth Limit Reached' => [
            'subject' => 'CDN bandwidth limit reached for {$cdn_domain}',
            'message' => '<p>Dear {$client_name},</p><p>Your CDN for <strong>{$cdn_domain}</strong> has used its full monthly bandwidth of {$cdn_limit}.</p><p>The CDN is paused until the start of next month. To restore it now, please upgrade your bandwidth from the client area or contact support.</p><p>{$signature}</p>',
        ],
    ];

    /** Create the product email templates once so admins can edit them under Email Templates. */
    public static function ensureEmailTemplates()
    {
        try {
            foreach (self::$emailTemplates as $name => $tpl) {
                if (!Capsule::table('tblemailtemplates')->where('type', 'product')->where('name', $name)->exists()) {
                    Capsule::table('tblemailtemplates')->insert([
                        'type' => 'product', 'name' => $name, 'subject' => $tpl['subject'],
                        'message' => $tpl['message'], 'custom' => 1, 'language' => '',
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Falls back to a custom message when sending
        }
    }

    public static function sendClientEmail($serviceId, $templateName, array $vars)
    {
        $res = localAPI('SendEmail', [
            'messagename' => $templateName,
            'id' => (int) $serviceId,
            'customvars' => base64_encode(serialize($vars)),
        ]);
        if (($res['result'] ?? '') === 'success') {
            return true;
        }
        $tpl = self::$emailTemplates[$templateName];
        $res = localAPI('SendEmail', [
            'customtype' => 'product',
            'id' => (int) $serviceId,
            'customsubject' => $tpl['subject'],
            'custommessage' => $tpl['message'],
            'customvars' => base64_encode(serialize($vars)),
        ]);
        return ($res['result'] ?? '') === 'success';
    }

    // =============================================================== Billing

    /** Latest month-to-date snapshot taken within a month. */
    private static function lastSnapshot($serviceId, $month)
    {
        return Capsule::table('mod_seekahostcdn_usage')->where('service_id', (int) $serviceId)
            ->where('usage_date', '>=', $month . '-01')
            ->where('usage_date', '<=', date('Y-m-t', strtotime($month . '-01')))
            ->orderBy('usage_date', 'desc')->first();
    }

    /** bunny.net cost for a month = the last month-to-date snapshot taken in it. */
    public static function bunnyCostForMonth($serviceId, $month)
    {
        $row = self::lastSnapshot($serviceId, $month);
        return $row ? (float) $row->cost : 0.0;
    }

    /** Peak storage used in a month, in bytes. */
    public static function storageForMonth($serviceId, $month)
    {
        return (int) Capsule::table('mod_seekahostcdn_usage')->where('service_id', (int) $serviceId)
            ->where('usage_date', '>=', $month . '-01')
            ->where('usage_date', '<=', date('Y-m-t', strtotime($month . '-01')))
            ->max('storage_bytes');
    }

    /** Whether usage of this row is invoiced at all. */
    public static function isBillable($row)
    {
        return ($row->source ?? 'product') !== 'included' || self::isOn(self::settings()['included_bill']);
    }

    /**
     * Invoice one service for one month (Y-m). $toDate limits the period for a
     * part month (used at termination). Never invoices the same month twice.
     */
    public static function billServiceMonth($row, $month, BunnyClient $bunny = null, $toDate = null)
    {
        $settings = self::settings();
        if (!self::isBillable($row)) {
            return 'not billable';
        }
        $service = Capsule::table('tblhosting')->where('id', $row->service_id)->first();
        if (!$service) {
            return 'missing service';
        }
        if (Capsule::table('mod_seekahostcdn_billing')->where('service_id', $row->service_id)->where('billing_month', $month)->exists()) {
            return 'already billed';
        }

        $from = $month . '-01';
        $to = $toDate ?: date('Y-m-t', strtotime($from));
        $start = date('Y-m-d', strtotime($row->created_at ?: $from));
        if ($service->regdate && $service->regdate !== '0000-00-00' && ($row->source ?? 'product') === 'product') {
            $start = $service->regdate;
        }
        if ($start > $to) {
            return 'not active in period';
        }
        $periodFrom = max($from, $start);

        $bunny = $bunny ?: self::clientForRow($row);
        $stats = $bunny->getStatistics($row->pull_zone_id, $periodFrom, $to);
        $bytes = (int) ($stats['TotalBandwidthUsed'] ?? 0);
        $gb = self::bytesToGb($bytes);
        $bandwidthCost = self::priceForBytes($bytes);

        $storageBytes = !empty($row->storage_zone_id) ? self::storageForMonth($row->service_id, $month) : 0;
        $storageGb = self::bytesToGb($storageBytes);
        $storageCost = round($storageGb * self::pricePerStorageGb(), 2);

        $cost = round($bandwidthCost + $storageCost, 2);
        $currency = self::clientCurrency($service->userid);
        $clientBandwidth = self::toClientCurrency($bandwidthCost, $currency);
        $clientStorage = self::toClientCurrency($storageCost, $currency);
        $clientAmount = round($clientBandwidth + $clientStorage, 2);

        // Claim the month first; the unique key stops a parallel run double billing
        try {
            $billingId = Capsule::table('mod_seekahostcdn_billing')->insertGetId([
                'service_id' => $row->service_id,
                'userid' => $service->userid,
                'billing_month' => $month,
                'bandwidth_gb' => $gb,
                'storage_gb' => $storageGb,
                'storage_cost' => $storageCost,
                'cost' => $cost,
                'bunny_cost' => self::bunnyCostForMonth($row->service_id, $month),
                'client_amount' => $clientAmount,
                'currency_code' => $currency['code'] ?? '',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            return 'already billed';
        }

        if ($cost < (float) $settings['min_invoice'] || $clientAmount <= 0) {
            Capsule::table('mod_seekahostcdn_billing')->where('id', $billingId)->update(['status' => 'below_minimum']);
            return 'below minimum';
        }

        $periodLabel = date('j M Y', strtotime($periodFrom)) . ' - ' . date('j M Y', strtotime($to));
        $invoice = [
            'userid' => $service->userid,
            'status' => 'Unpaid',
            'sendinvoice' => true,
            'paymentmethod' => $service->paymentmethod,
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d', strtotime('+' . max(0, (int) $settings['invoice_due_days']) . ' days')),
        ];
        $taxed = self::isOn($settings['tax_usage']);
        $n = 0;
        if ($clientBandwidth > 0 || $clientStorage <= 0) {
            $n++;
            $invoice["itemdescription{$n}"] = sprintf('CDN bandwidth for %s (%s): %s GB @ %s/GB',
                $row->domain, $periodLabel, number_format($gb, 2),
                self::formatMoney(self::toClientCurrency(self::pricePerGb(), $currency), $currency));
            $invoice["itemamount{$n}"] = $clientBandwidth;
            $invoice["itemtaxed{$n}"] = $taxed;
        }
        if ($clientStorage > 0) {
            $n++;
            $invoice["itemdescription{$n}"] = sprintf('CDN storage for %s (%s): %s GB @ %s/GB per month',
                $row->domain, date('F Y', strtotime($from)), number_format($storageGb, 2),
                self::formatMoney(self::toClientCurrency(self::pricePerStorageGb(), $currency), $currency));
            $invoice["itemamount{$n}"] = $clientStorage;
            $invoice["itemtaxed{$n}"] = $taxed;
        }
        $result = localAPI('CreateInvoice', $invoice);

        if (($result['result'] ?? '') === 'success') {
            Capsule::table('mod_seekahostcdn_billing')->where('id', $billingId)
                ->update(['invoice_id' => (int) $result['invoiceid'], 'status' => 'invoiced']);
            logActivity("SeekaHost CDN: invoice #{$result['invoiceid']} for {$gb} GB ({$month}) on service #{$row->service_id}", $service->userid);
            return 'invoiced';
        }

        Capsule::table('mod_seekahostcdn_billing')->where('id', $billingId)
            ->update(['status' => 'failed', 'notes' => (string) ($result['message'] ?? 'CreateInvoice failed')]);
        return 'failed: ' . ($result['message'] ?? '');
    }

    /** Invoice every CDN service for $month (default: last month). */
    public static function runMonthlyBilling($month = null)
    {
        self::ensureSchema();
        $month = $month ?: date('Y-m', strtotime('first day of last month'));
        $summary = ['invoiced' => 0, 'skipped' => 0, 'errors' => []];
        foreach (Capsule::table('mod_seekahostcdn_dns_zones')->get() as $row) {
            try {
                if (!in_array(self::serviceStatus($row), ['Active', 'Suspended'], true)) {
                    continue;
                }
                $res = self::billServiceMonth($row, $month, self::clientForRow($row));
                if ($res === 'invoiced') {
                    $summary['invoiced']++;
                } elseif (strpos($res, 'failed') === 0) {
                    $summary['errors'][] = "Service #{$row->service_id}: {$res}";
                } else {
                    $summary['skipped']++;
                }
            } catch (\Exception $e) {
                $summary['errors'][] = "Service #{$row->service_id}: " . $e->getMessage();
            }
        }
        logActivity(sprintf('SeekaHost CDN billing %s: %d invoiced, %d skipped, %d errors',
            $month, $summary['invoiced'], $summary['skipped'], count($summary['errors'])));
        foreach ($summary['errors'] as $err) {
            logActivity('SeekaHost CDN billing error: ' . $err);
        }
        return $summary;
    }

    // ===================================================== Included / addon CDN

    /**
     * Params for a CDN that is not its own product (included with hosting), built
     * from the addon settings. The hosting server's IP is the natural origin.
     */
    public static function includedParams($serviceId)
    {
        $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->first();
        if (!$service) {
            throw new \Exception('Service not found.');
        }
        $settings = self::settings();
        $server = Capsule::table('tblservers')->where('type', self::MODULE)->where('disabled', 0)
            ->where('accesshash', '!=', '')->orderBy('id')->first();
        if (!$server) {
            throw new \Exception('No SeekaHost CDN server is configured.');
        }
        $origin = '';
        if ($service->server) {
            $hostServer = Capsule::table('tblservers')->where('id', $service->server)->first();
            if ($hostServer && filter_var($hostServer->ipaddress, FILTER_VALIDATE_IP)) {
                $origin = 'http://' . $hostServer->ipaddress;
            }
        }
        if ($service->dedicatedip && filter_var($service->dedicatedip, FILTER_VALIDATE_IP)) {
            $origin = 'http://' . $service->dedicatedip;
        }
        $gb = (float) $settings['included_limit_gb'];
        return [
            'serviceid' => (int) $service->id,
            'userid' => (int) $service->userid,
            'domain' => $service->domain,
            'status' => $service->domainstatus,
            'serverid' => (int) $server->id,
            'serveraccesshash' => $server->accesshash,
            'configoption1' => $origin,
            'configoption2' => $gb > 0 ? (string) $gb : '0',
            'configoption3' => 'Standard',
            'configoption4' => $settings['included_mode'],
            'configoption5' => 'None',
            'customfields' => [],
            '_shcdn_source' => 'included',
        ];
    }

    // ======================================================== CDN settings

    public static $cacheChoices = [
        -1 => 'Respect origin headers', 0 => "Don't cache", 300 => '5 minutes', 3600 => '1 hour',
        14400 => '4 hours', 86400 => '1 day', 604800 => '1 week', 2592000 => '30 days', 31536000 => '1 year',
    ];

    /** Current client-editable pull zone settings. */
    public static function cdnSettings($row, BunnyClient $bunny)
    {
        $z = $bunny->getPullZone($row->pull_zone_id);
        $forceSsl = false;
        foreach (($z['Hostnames'] ?? []) as $h) {
            if (empty($h['IsSystemHostname']) && !empty($h['ForceSSL'])) {
                $forceSsl = true;
            }
        }
        return [
            'origin' => (string) ($z['OriginUrl'] ?? ''),
            'storageOrigin' => !empty($row->storage_zone_id),
            'forceSsl' => $forceSsl,
            'cacheTime' => (int) ($z['CacheControlMaxAgeOverride'] ?? -1),
            'browserCacheTime' => (int) ($z['CacheControlBrowserMaxAgeOverride'] ?? -1),
            'ignoreQueryStrings' => !empty($z['IgnoreQueryStrings']),
            'blockedCountries' => implode(', ', (array) ($z['BlockedCountries'] ?? [])),
            'allowedReferrers' => implode("\n", (array) ($z['AllowedReferrers'] ?? [])),
            'blockNoneReferrer' => !empty($z['BlockNoneReferrer']),
            'blockedIps' => implode("\n", (array) ($z['BlockedIps'] ?? [])),
        ];
    }

    private static function lines($text, $max, $label)
    {
        $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $text)))));
        if (count($items) > $max) {
            throw new \InvalidArgumentException("Too many {$label} (maximum {$max}).");
        }
        return $items;
    }

    /** Validate and save client CDN settings. Throws InvalidArgumentException with a client-safe message. */
    public static function saveCdnSettings($row, BunnyClient $bunny, array $in)
    {
        $update = [];

        if (empty($row->storage_zone_id) && isset($in['origin'])) {
            $origin = trim((string) $in['origin']);
            if (!preg_match('#^https?://#i', $origin)) {
                $origin = 'http://' . $origin;
            }
            $parts = parse_url($origin);
            $host = strtolower($parts['host'] ?? '');
            if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && !self::isValidHostname($host))) {
                throw new \InvalidArgumentException('Please enter a valid origin URL or IP address.');
            }
            if (in_array($host, self::decodeList($row->hostnames), true) || $host === strtolower($row->cdn_url)) {
                throw new \InvalidArgumentException('The origin cannot be a hostname served by the CDN itself. Use your server\'s IP address or another hostname.');
            }
            if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('The origin must be a public IP address.');
            }
            $origin = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
            $update['OriginUrl'] = $origin;
        }

        foreach (['cacheTime' => 'CacheControlMaxAgeOverride', 'browserCacheTime' => 'CacheControlBrowserMaxAgeOverride'] as $key => $field) {
            if (isset($in[$key])) {
                $value = (int) $in[$key];
                if (!isset(self::$cacheChoices[$value])) {
                    throw new \InvalidArgumentException('Please choose a cache time from the list.');
                }
                $update[$field] = $value;
            }
        }

        $update['IgnoreQueryStrings'] = self::isOn($in['ignoreQueryStrings'] ?? '');
        $update['BlockNoneReferrer'] = self::isOn($in['blockNoneReferrer'] ?? '');

        $countries = array_map('strtoupper', self::lines(str_replace(' ', ',', $in['blockedCountries'] ?? ''), 250, 'countries'));
        foreach ($countries as $c) {
            if (!preg_match('/^[A-Z]{2}$/', $c)) {
                throw new \InvalidArgumentException("\"{$c}\" is not a 2-letter country code (e.g. CN, RU).");
            }
        }
        $update['BlockedCountries'] = $countries;

        $referrers = array_map('strtolower', self::lines($in['allowedReferrers'] ?? '', 100, 'referrers'));
        foreach ($referrers as $r) {
            if (!self::isValidHostname(preg_replace('/^\*\./', '', $r))) {
                throw new \InvalidArgumentException("\"{$r}\" is not a valid domain for hotlink protection.");
            }
        }
        $update['AllowedReferrers'] = $referrers;

        $ips = self::lines($in['blockedIps'] ?? '', 500, 'IP addresses');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException("\"{$ip}\" is not a valid IP address.");
            }
        }
        $update['BlockedIps'] = $ips;

        $bunny->updatePullZone($row->pull_zone_id, $update);
        if (isset($update['OriginUrl'])) {
            self::touchZone($row->service_id, ['origin_url' => $update['OriginUrl']]);
        }

        $force = self::isOn($in['forceSsl'] ?? '');
        foreach (self::decodeList($row->ssl_hostnames) as $host) {
            try {
                $bunny->setForceSsl($row->pull_zone_id, $host, $force);
            } catch (\Exception $e) {
                // a hostname without a certificate cannot force HTTPS yet
            }
        }
    }

    /** Purge specific URLs on the client's own hostnames. Returns the number purged. */
    public static function purgeUrls($row, BunnyClient $bunny, $text)
    {
        $urls = self::lines($text, 30, 'URLs');
        if (!$urls) {
            throw new \InvalidArgumentException('Enter at least one URL to purge.');
        }
        $allowed = array_merge(self::decodeList($row->hostnames), [strtolower($row->cdn_url)]);
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!preg_match('#^https?://#i', $url) || !in_array($host, $allowed, true)) {
                throw new \InvalidArgumentException("\"{$url}\" is not a URL on your CDN hostnames.");
            }
        }
        foreach ($urls as $url) {
            $bunny->purgeUrl($url);
        }
        return count($urls);
    }

    /** Storage zone access details for the client (fetched live, never stored). */
    public static function storageDetails($row, BunnyClient $bunny)
    {
        $z = $bunny->getStorageZone($row->storage_zone_id);
        $host = (string) ($z['StorageHostname'] ?? 'storage.bunnycdn.com');
        return [
            'name' => (string) ($z['Name'] ?? $row->storage_zone_name),
            'region' => (string) ($z['Region'] ?? $row->storage_region),
            'ftpHost' => $host,
            'ftpPort' => 21,
            'apiEndpoint' => 'https://' . $host . '/' . ($z['Name'] ?? $row->storage_zone_name) . '/',
            'username' => (string) ($z['Name'] ?? $row->storage_zone_name),
            'password' => (string) ($z['Password'] ?? ''),
            'readOnlyPassword' => (string) ($z['ReadOnlyPassword'] ?? ''),
            'used' => self::formatBytes((int) ($z['StorageUsed'] ?? 0)),
            'files' => number_format((int) ($z['FilesStored'] ?? 0)),
        ];
    }

    // ============================================================== Hostnames

    public static function addHostnameToRow($row, BunnyClient $bunny, $host)
    {
        $host = self::normaliseHost($host);
        if (!self::isValidHostname($host)) {
            throw new \InvalidArgumentException("\"{$host}\" is not a valid hostname.");
        }
        $other = Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', '!=', $row->service_id)
            ->where('hostnames', 'like', '%"' . $host . '"%')->first();
        if ($other) {
            throw new \InvalidArgumentException("{$host} is already used by CDN service #{$other->service_id}.");
        }
        $hostnames = self::decodeList($row->hostnames);
        if (in_array($host, $hostnames, true)) {
            return;
        }
        try {
            $bunny->addHostname($row->pull_zone_id, $host);
        } catch (BunnyException $e) {
            if (stripos($e->getMessage(), 'already') === false) {
                throw $e;
            }
        }
        $hostnames[] = $host;
        self::touchZone($row->service_id, ['hostnames' => json_encode(array_values(array_unique($hostnames)))]);
    }

    public static function removeHostnameFromRow($row, BunnyClient $bunny, $host)
    {
        try {
            $bunny->removeHostname($row->pull_zone_id, $host);
        } catch (BunnyException $e) {
            if ($e->httpCode !== 404 && stripos($e->getMessage(), 'not found') === false) {
                throw $e;
            }
        }
        self::touchZone($row->service_id, [
            'hostnames' => json_encode(array_values(array_diff(self::decodeList($row->hostnames), [$host]))),
            'ssl_hostnames' => json_encode(array_values(array_diff(self::decodeList($row->ssl_hostnames), [$host]))),
        ]);
    }

    // ================================================================= Import

    /** Pull zones in the bunny.net account that are not linked to any WHMCS service. */
    public static function unlinkedPullZones(BunnyClient $bunny)
    {
        $linked = Capsule::table('mod_seekahostcdn_dns_zones')->pluck('pull_zone_id');
        $linked = array_map('strval', is_object($linked) && method_exists($linked, 'toArray') ? $linked->toArray() : (array) $linked);
        $out = [];
        for ($page = 1; $page <= 50; $page++) {
            $res = $bunny->listPullZonePage($page, 100);
            foreach (($res['Items'] ?? []) as $z) {
                if (in_array((string) $z['Id'], $linked, true)) {
                    continue;
                }
                $custom = [];
                foreach (($z['Hostnames'] ?? []) as $h) {
                    if (empty($h['IsSystemHostname'])) {
                        $custom[] = $h['Value'];
                    }
                }
                $out[] = [
                    'id' => (int) $z['Id'],
                    'name' => (string) ($z['Name'] ?? ''),
                    'origin' => (string) ($z['OriginUrl'] ?? ''),
                    'hostnames' => $custom,
                    'storageZoneId' => (int) ($z['StorageZoneId'] ?? 0),
                ];
            }
            if (empty($res['HasMoreItems'])) {
                break;
            }
        }
        return $out;
    }

    /**
     * Link an existing pull zone (and the domain's bunny.net DNS zone, if any) to a
     * WHMCS service, so it is managed and billed like a newly provisioned one.
     */
    public static function linkExistingZone($serviceId, $pullZoneId, $serverId)
    {
        self::ensureSchema();
        $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->first();
        if (!$service) {
            throw new \InvalidArgumentException("Service #{$serviceId} does not exist.");
        }
        if (self::zoneForService($serviceId)) {
            throw new \InvalidArgumentException("Service #{$serviceId} already has a CDN zone.");
        }
        if (Capsule::table('mod_seekahostcdn_dns_zones')->where('pull_zone_id', (string) $pullZoneId)->exists()) {
            throw new \InvalidArgumentException("Pull zone {$pullZoneId} is already linked.");
        }
        $bunny = new BunnyClient(self::apiKeyForServer($serverId));
        $z = $bunny->getPullZone($pullZoneId);

        $custom = [];
        $withSsl = [];
        foreach (($z['Hostnames'] ?? []) as $h) {
            if (empty($h['IsSystemHostname'])) {
                $custom[] = strtolower($h['Value']);
                if (!empty($h['HasCertificate'])) {
                    $withSsl[] = strtolower($h['Value']);
                }
            }
        }
        $domain = self::normaliseDomain($custom ? $custom[0] : $service->domain);
        $dnsZone = $domain ? $bunny->findDnsZone($domain) : null;
        $mode = $dnsZone ? 'dns' : 'cname';
        $product = Capsule::table('tblproducts')->where('id', $service->packageid)->value('servertype');
        $source = $product === self::MODULE ? 'product' : 'included';

        $storageId = (int) ($z['StorageZoneId'] ?? 0);
        $storageName = null;
        if ($storageId > 0) {
            try {
                $storageName = $bunny->getStorageZone($storageId)['Name'] ?? null;
            } catch (\Exception $e) {
                $storageId = 0;
            }
        }

        Capsule::table('mod_seekahostcdn_dns_zones')->insert([
            'service_id' => (int) $serviceId,
            'dns_zone_id' => $dnsZone ? (string) $dnsZone['Id'] : '',
            'pull_zone_id' => (string) $pullZoneId,
            'domain' => $mode === 'dns' ? $domain : ($custom[0] ?? $domain),
            'nameserver_1' => $dnsZone['Nameserver1'] ?? '',
            'nameserver_2' => $dnsZone['Nameserver2'] ?? '',
            'cdn_url' => self::systemHostname($z),
            'origin_url' => $storageId ? 'storage:' . $storageName : (string) ($z['OriginUrl'] ?? ''),
            'hostnames' => json_encode($custom),
            'ssl_hostnames' => json_encode($withSsl),
            'nameservers_detected' => 0,
            'suspended' => 0,
            'mode' => $mode,
            'source' => $source,
            'addon_id' => 0,
            'server_id' => (int) $serverId,
            'limit_bytes' => max(0, (int) ($z['MonthlyBandwidthLimit'] ?? 0)),
            'storage_zone_id' => $storageId ? (string) $storageId : null,
            'storage_zone_name' => $storageName,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($source === 'product') {
            Capsule::table('tblhosting')->where('id', (int) $serviceId)->update([
                'dedicatedip' => (string) $pullZoneId,
                'ns1' => $dnsZone['Nameserver1'] ?? '',
                'ns2' => $dnsZone['Nameserver2'] ?? '',
            ]);
        }
        $row = self::zoneForService($serviceId);
        try {
            self::issueSsl($row, $bunny);
        } catch (\Exception $e) {
            // SSL state is refreshed by the next cron run
        }
        logActivity("SeekaHost CDN: linked existing pull zone {$pullZoneId} to service #{$serviceId}");
        return $row;
    }

    // ======================================================= Client DNS input

    /**
     * Validate client input and build a bunny.net record. Throws with a message
     * that is safe to show the client.
     */
    public static function buildRecord(array $in, $domain, $pullZoneId, $cdnHost)
    {
        $type = (int) ($in['type'] ?? -1);
        if (!in_array($type, self::$clientRecordTypes, true)) {
            throw new \InvalidArgumentException('This record type is not allowed.');
        }

        $name = strtolower(trim((string) ($in['name'] ?? '')));
        $name = rtrim($name, '.');
        if ($name === '@') {
            $name = '';
        }
        if ($name === $domain) {
            $name = '';
        } elseif (substr($name, -strlen('.' . $domain)) === '.' . $domain) {
            $name = substr($name, 0, -strlen('.' . $domain));
        }
        if ($name !== '' && !preg_match('/^(\*|[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $name)) {
            throw new \InvalidArgumentException('Invalid record name.');
        }

        $ttl = (int) ($in['ttl'] ?? 3600);
        $ttl = max(60, min(86400, $ttl ?: 3600));

        if ($type === BunnyClient::REC_PULLZONE) {
            $record = self::cdnRecord($name, $pullZoneId, $cdnHost);
            $record['Ttl'] = $ttl;
            return $record;
        }

        $value = trim((string) ($in['value'] ?? ''));
        if ($value === '' || strlen($value) > 2048) {
            throw new \InvalidArgumentException('Please enter a value (max 2048 characters).');
        }
        $record = ['Type' => $type, 'Name' => $name, 'Value' => $value, 'Ttl' => $ttl];

        switch ($type) {
            case BunnyClient::REC_A:
                if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new \InvalidArgumentException('An A record needs a valid IPv4 address.');
                }
                break;
            case BunnyClient::REC_AAAA:
                if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new \InvalidArgumentException('An AAAA record needs a valid IPv6 address.');
                }
                break;
            case BunnyClient::REC_CNAME:
                if ($name === '') {
                    throw new \InvalidArgumentException('A CNAME cannot be used on the root domain. Use a CDN or A record instead.');
                }
                // fall through
            case BunnyClient::REC_MX:
            case BunnyClient::REC_SRV:
                $record['Value'] = rtrim(strtolower($value), '.');
                if (!self::isValidHostname($record['Value'])) {
                    throw new \InvalidArgumentException('The value must be a valid hostname.');
                }
                break;
            case BunnyClient::REC_CAA:
                $tag = strtolower(trim((string) ($in['tag'] ?? 'issue')));
                if (!in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
                    throw new \InvalidArgumentException('CAA tag must be issue, issuewild or iodef.');
                }
                $record['Tag'] = $tag;
                $record['Flags'] = max(0, min(255, (int) ($in['flags'] ?? 0)));
                break;
        }
        if (in_array($type, [BunnyClient::REC_MX, BunnyClient::REC_SRV], true)) {
            $record['Priority'] = max(0, min(65535, (int) ($in['priority'] ?? 10)));
        }
        if ($type === BunnyClient::REC_SRV) {
            $record['Weight'] = max(0, min(65535, (int) ($in['weight'] ?? 0)));
            $record['Port'] = max(1, min(65535, (int) ($in['port'] ?? 0)));
        }
        return $record;
    }

    /** When a CDN record is added for a new name, the pull zone must also serve that hostname. */
    public static function ensureHostname(BunnyClient $bunny, $row, $name)
    {
        $host = $name === '' ? $row->domain : $name . '.' . $row->domain;
        if (strpos($host, '*') !== false) {
            return;
        }
        $hostnames = self::decodeList($row->hostnames);
        if (in_array($host, $hostnames, true)) {
            return;
        }
        try {
            $bunny->addHostname($row->pull_zone_id, $host);
        } catch (BunnyException $e) {
            if (stripos($e->getMessage(), 'already') === false) {
                throw $e;
            }
        }
        $hostnames[] = $host;
        self::touchZone($row->service_id, ['hostnames' => json_encode(array_values(array_unique($hostnames)))]);
    }

    /** Sanitised record list for the client area. */
    public static function presentRecords(array $records)
    {
        $out = [];
        foreach ($records as $r) {
            $type = (int) ($r['Type'] ?? -1);
            $out[] = [
                'id' => (int) $r['Id'],
                'type' => $type,
                'typeName' => BunnyClient::$recordTypeNames[$type] ?? (string) $type,
                'name' => (string) ($r['Name'] ?? ''),
                'value' => (string) ($r['Value'] ?? ''),
                'ttl' => (int) ($r['Ttl'] ?? 0),
                'priority' => (int) ($r['Priority'] ?? 0),
                'weight' => (int) ($r['Weight'] ?? 0),
                'port' => (int) ($r['Port'] ?? 0),
                'flags' => (int) ($r['Flags'] ?? 0),
                'tag' => (string) ($r['Tag'] ?? ''),
                'editable' => in_array($type, self::$clientRecordTypes, true),
            ];
        }
        usort($out, function ($a, $b) {
            return [$a['name'], $a['type']] <=> [$b['name'], $b['type']];
        });
        return $out;
    }
}
