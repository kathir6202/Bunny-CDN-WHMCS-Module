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
        ]);
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

    // ========================================================== Lookups / API

    public static function zoneForService($serviceId)
    {
        return Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', (int) $serviceId)->first();
    }

    public static function apiKeyForServer($serverId)
    {
        $server = null;
        if ($serverId) {
            $server = Capsule::table('tblservers')->where('id', (int) $serverId)->first();
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

    public static function decodeList($json)
    {
        $list = json_decode((string) $json, true);
        return is_array($list) ? array_values(array_unique($list)) : [];
    }

    private static function touchZone($serviceId, array $data)
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

    /**
     * Creates the DNS zone + pull zone, imports existing records and points the
     * site at the CDN. Safe to re-run: an already provisioned service is left alone.
     */
    public static function provision(array $params)
    {
        self::ensureSchema();
        $serviceId = (int) $params['serviceid'];
        $domain = self::normaliseDomain($params['domain'] ?? '');

        if (!self::isValidHostname($domain)) {
            throw new \Exception('The service domain "' . $domain . '" is not a valid domain name.');
        }

        $bunny = self::clientForParams($params);

        $existing = self::zoneForService($serviceId);
        if ($existing && $existing->pull_zone_id && $existing->dns_zone_id) {
            $bunny->getPullZone($existing->pull_zone_id); // throws if it vanished
            return 'already provisioned';
        }

        $other = Capsule::table('mod_seekahostcdn_dns_zones')->where('domain', $domain)
            ->where('service_id', '!=', $serviceId)->first();
        if ($other) {
            throw new \Exception("Domain {$domain} is already used by CDN service #{$other->service_id}.");
        }

        // 1. Read the domain's current DNS while it is still live at the old provider
        $discovered = self::discoverLocalDns($domain);

        // 2. Work out where the CDN should fetch content from
        $origin = self::determineOrigin($params, $domain, $discovered);

        $createdDns = null;
        $createdPull = null;
        try {
            // 3. DNS zone (re-use one left behind by an earlier failed attempt)
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

            // 4. Pull zone
            $pullZone = self::createPullZone($bunny, $serviceId, $domain, $origin, $params);
            $createdPull = $pullZone['Id'];
            $pullZoneId = $pullZone['Id'];
            $cdnHost = self::systemHostname($pullZone);

            // 5. Hostnames on the pull zone so it answers for the site
            $hostnames = [];
            foreach ([$domain, 'www.' . $domain] as $host) {
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

            // 6. Import existing records (bunny scan merged with our own lookup)
            $records = self::mergeRecords($discovered, self::discoverViaBunny($bunny, $dnsZoneId));
            self::importRecords($bunny, $dnsZoneId, $records, true);

            // 7. Point apex and www at the CDN
            self::pointToCdn($bunny, $dnsZoneId, $pullZoneId, $cdnHost);

            $ns1 = $dnsZone['Nameserver1'] ?? 'kiki.bunny.net';
            $ns2 = $dnsZone['Nameserver2'] ?? 'coco.bunny.net';

            Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', $serviceId)->delete();
            Capsule::table('mod_seekahostcdn_dns_zones')->insert([
                'service_id' => $serviceId,
                'dns_zone_id' => (string) $dnsZoneId,
                'pull_zone_id' => (string) $pullZoneId,
                'domain' => $domain,
                'nameserver_1' => $ns1,
                'nameserver_2' => $ns2,
                'cdn_url' => $cdnHost,
                'origin_url' => $origin,
                'hostnames' => json_encode($hostnames),
                'ssl_hostnames' => json_encode([]),
                'nameservers_detected' => 0,
                'suspended' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Capsule::table('tblhosting')->where('id', $serviceId)->update([
                'dedicatedip' => (string) $pullZoneId,
                'ns1' => $ns1,
                'ns2' => $ns2,
            ]);
        } catch (\Exception $e) {
            // Roll back anything we created so bunny.net does not bill for orphans
            if ($createdPull) {
                try { $bunny->deletePullZone($createdPull); } catch (\Exception $ignored) {}
            }
            if ($createdDns) {
                try { $bunny->deleteDnsZone($createdDns); } catch (\Exception $ignored) {}
            }
            throw $e;
        }

        logActivity("SeekaHost CDN: provisioned {$domain} (service #{$serviceId}, pull zone {$pullZoneId}, DNS zone {$dnsZoneId})");
        return 'success';
    }

    private static function createPullZone(BunnyClient $bunny, $serviceId, $domain, $origin, array $params)
    {
        $slug = substr(preg_replace('/[^a-z0-9]+/', '-', $domain), 0, 40);
        $name = trim('sh' . $serviceId . '-' . $slug, '-');
        $data = [
            'Name' => $name,
            'OriginUrl' => $origin,
            'Type' => strtolower((string) ($params['configoption3'] ?? '')) === 'volume' ? 1 : 0,
        ];
        try {
            $zone = $bunny->createPullZone($data);
        } catch (BunnyException $e) {
            if (stripos($e->getMessage(), 'taken') === false && stripos($e->getMessage(), 'exists') === false) {
                throw $e;
            }
            $data['Name'] = substr($name, 0, 50) . '-' . substr(md5(uniqid('', true)), 0, 6);
            $zone = $bunny->createPullZone($data);
        }

        $update = [
            'MonthlyBandwidthLimit' => self::limitBytes($params),
            // Send the visitor's Host header so an IP origin serves the right site
            'AddHostHeader' => true,
        ];
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

    /** Monthly bandwidth limit for the product in bytes (0 = unlimited). */
    public static function limitBytes(array $params)
    {
        $gb = (float) ($params['configoption2'] ?? 0);
        return $gb > 0 ? (int) round($gb * self::BYTES_PER_GB) : 0;
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

    // =================================================================== SSL

    /**
     * Request free certificates for hostnames that do not have one yet. Only
     * succeeds once the domain's nameservers point at bunny.net.
     */
    public static function issueSsl($row, BunnyClient $bunny = null)
    {
        $bunny = $bunny ?: self::clientForService($row->service_id);
        $zone = $bunny->getDnsZone($row->dns_zone_id);
        $detected = self::nameserversPointed($row->domain, $zone);

        $hostnames = self::decodeList($row->hostnames);
        $done = self::decodeList($row->ssl_hostnames);
        $errors = [];
        if ($detected) {
            foreach (array_diff($hostnames, $done) as $host) {
                try {
                    $bunny->loadFreeCertificate($host);
                    try { $bunny->setForceSsl($row->pull_zone_id, $host, true); } catch (\Exception $ignored) {}
                    $done[] = $host;
                } catch (\Exception $e) {
                    $errors[] = $host . ': ' . $e->getMessage();
                }
            }
        }
        self::touchZone($row->service_id, [
            'ssl_hostnames' => json_encode(array_values(array_unique($done))),
            'nameservers_detected' => $detected ? 1 : 0,
            'nameserver_1' => $zone['Nameserver1'] ?? $row->nameserver_1,
            'nameserver_2' => $zone['Nameserver2'] ?? $row->nameserver_2,
        ]);
        return ['detected' => $detected, 'issued' => $done, 'errors' => $errors];
    }

    // ===================================================== Suspend / terminate

    public static function suspend(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if (!$row) {
            return 'success';
        }
        // A 1-byte monthly limit makes bunny.net refuse to serve the zone
        self::clientForParams($params)->updatePullZone($row->pull_zone_id, ['MonthlyBandwidthLimit' => 1]);
        self::touchZone($row->service_id, ['suspended' => 1]);
        return 'success';
    }

    public static function unsuspend(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if (!$row) {
            return 'success';
        }
        self::clientForParams($params)->updatePullZone($row->pull_zone_id, ['MonthlyBandwidthLimit' => self::limitBytes($params)]);
        self::touchZone($row->service_id, ['suspended' => 0]);
        return 'success';
    }

    public static function changePackage(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if (!$row) {
            return 'success';
        }
        $update = [];
        if (!$row->suspended) {
            $update['MonthlyBandwidthLimit'] = self::limitBytes($params);
        }
        if ($update) {
            self::clientForParams($params)->updatePullZone($row->pull_zone_id, $update);
        }
        return 'success';
    }

    public static function terminate(array $params)
    {
        $row = self::zoneForService($params['serviceid']);
        if (!$row) {
            Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->update(['dedicatedip' => '']);
            return 'success';
        }
        $bunny = self::clientForParams($params);

        // Bill unbilled usage before the zone (and its statistics) disappear
        if (self::isOn(self::settings()['enable_billing'])) {
            $prev = date('Y-m', strtotime('first day of last month'));
            self::billServiceMonth($row, $prev, $bunny);
            self::billServiceMonth($row, date('Y-m'), $bunny, date('Y-m-d'));
        }

        foreach ([['deletePullZone', $row->pull_zone_id], ['deleteDnsZone', $row->dns_zone_id]] as $call) {
            try {
                $bunny->{$call[0]}($call[1]);
            } catch (BunnyException $e) {
                if ($e->httpCode !== 404) {
                    throw $e;
                }
            }
        }

        Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', $row->service_id)->delete();
        Capsule::table('tblhosting')->where('id', $row->service_id)->update(['dedicatedip' => '']);
        logActivity("SeekaHost CDN: terminated {$row->domain} (service #{$row->service_id})");
        return 'success';
    }

    // ================================================================= Usage

    /** Snapshot month-to-date bandwidth and bunny.net cost for one zone. */
    public static function syncUsage($row, BunnyClient $bunny = null)
    {
        $bunny = $bunny ?: self::clientForService($row->service_id);
        $zone = $bunny->getPullZone($row->pull_zone_id);
        $bytes = (int) ($zone['MonthlyBandwidthUsed'] ?? 0);
        $cost = (float) ($zone['MonthlyCharges'] ?? 0);
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $exists = Capsule::table('mod_seekahostcdn_usage')->where('service_id', $row->service_id)
            ->where('usage_date', $today)->exists();
        if ($exists) {
            Capsule::table('mod_seekahostcdn_usage')->where('service_id', $row->service_id)->where('usage_date', $today)
                ->update(['zone_id' => (string) $row->pull_zone_id, 'bandwidth_bytes' => $bytes, 'cost' => $cost, 'updated_at' => $now]);
        } else {
            Capsule::table('mod_seekahostcdn_usage')->insert([
                'service_id' => $row->service_id, 'zone_id' => (string) $row->pull_zone_id, 'usage_date' => $today,
                'bandwidth_bytes' => $bytes, 'cost' => $cost, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $limit = (int) ($zone['MonthlyBandwidthLimit'] ?? 0);
        Capsule::table('tblhosting')->where('id', $row->service_id)->update([
            'bwusage' => (int) round($bytes / 1048576),
            'bwlimit' => ($row->suspended || $limit <= 1) ? 0 : (int) round($limit / 1048576),
            'lastupdate' => $now,
        ]);
        return ['bytes' => $bytes, 'bunny_cost' => $cost, 'limit' => $limit];
    }

    /** Sync every zone. Returns [synced, errors[]]. */
    public static function syncAllUsage()
    {
        self::ensureSchema();
        $synced = 0;
        $errors = [];
        $clients = [];
        $rows = Capsule::table('mod_seekahostcdn_dns_zones')
            ->join('tblhosting', 'tblhosting.id', '=', 'mod_seekahostcdn_dns_zones.service_id')
            ->select('mod_seekahostcdn_dns_zones.*', 'tblhosting.server')->get();
        foreach ($rows as $row) {
            try {
                $key = self::apiKeyForServer($row->server);
                $clients[$key] = $clients[$key] ?? new BunnyClient($key);
                self::syncUsage($row, $clients[$key]);
                if (empty(self::decodeList($row->ssl_hostnames)) || !$row->nameservers_detected
                    || count(self::decodeList($row->ssl_hostnames)) < count(self::decodeList($row->hostnames))) {
                    self::issueSsl($row, $clients[$key]);
                }
                $synced++;
            } catch (\Exception $e) {
                $errors[] = "Service #{$row->service_id}: " . $e->getMessage();
            }
        }
        return [$synced, $errors];
    }

    /** bunny.net cost for a month = the last month-to-date snapshot taken in it. */
    public static function bunnyCostForMonth($serviceId, $month)
    {
        $row = Capsule::table('mod_seekahostcdn_usage')->where('service_id', (int) $serviceId)
            ->where('usage_date', '>=', $month . '-01')
            ->where('usage_date', '<=', date('Y-m-t', strtotime($month . '-01')))
            ->orderBy('usage_date', 'desc')->first();
        return $row ? (float) $row->cost : 0.0;
    }

    // =============================================================== Billing

    /**
     * Invoice one service for one month (Y-m). $toDate limits the period for a
     * part month (used at termination). Never invoices the same month twice.
     */
    public static function billServiceMonth($row, $month, BunnyClient $bunny = null, $toDate = null)
    {
        $settings = self::settings();
        $service = Capsule::table('tblhosting')->where('id', $row->service_id)->first();
        if (!$service) {
            return 'missing service';
        }
        if (Capsule::table('mod_seekahostcdn_billing')->where('service_id', $row->service_id)->where('billing_month', $month)->exists()) {
            return 'already billed';
        }

        $from = $month . '-01';
        $to = $toDate ?: date('Y-m-t', strtotime($from));
        $serviceStart = $service->regdate && $service->regdate !== '0000-00-00' ? $service->regdate : $from;
        if ($serviceStart > $to) {
            return 'not active in period';
        }

        $bunny = $bunny ?: self::clientForService($row->service_id);
        $stats = $bunny->getStatistics($row->pull_zone_id, max($from, $serviceStart), $to);
        $bytes = (int) ($stats['TotalBandwidthUsed'] ?? 0);
        $gb = self::bytesToGb($bytes);
        $cost = self::priceForBytes($bytes);
        $currency = self::clientCurrency($service->userid);
        $clientAmount = self::toClientCurrency($cost, $currency);

        // Claim the month first; the unique key stops a parallel run double billing
        try {
            $billingId = Capsule::table('mod_seekahostcdn_billing')->insertGetId([
                'service_id' => $row->service_id,
                'userid' => $service->userid,
                'billing_month' => $month,
                'bandwidth_gb' => $gb,
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

        $periodLabel = date('j M Y', strtotime(max($from, $serviceStart))) . ' - ' . date('j M Y', strtotime($to));
        $result = localAPI('CreateInvoice', [
            'userid' => $service->userid,
            'status' => 'Unpaid',
            'sendinvoice' => true,
            'paymentmethod' => $service->paymentmethod,
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d', strtotime('+' . max(0, (int) $settings['invoice_due_days']) . ' days')),
            'itemdescription1' => sprintf('CDN bandwidth for %s (%s): %s GB @ %s/GB',
                $row->domain, $periodLabel, number_format($gb, 2),
                self::formatMoney(self::toClientCurrency(self::pricePerGb(), $currency), $currency)),
            'itemamount1' => $clientAmount,
            'itemtaxed1' => self::isOn($settings['tax_usage']),
        ]);

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
        $clients = [];
        $rows = Capsule::table('mod_seekahostcdn_dns_zones')
            ->join('tblhosting', 'tblhosting.id', '=', 'mod_seekahostcdn_dns_zones.service_id')
            ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
            ->select('mod_seekahostcdn_dns_zones.*', 'tblhosting.server')->get();
        foreach ($rows as $row) {
            try {
                $key = self::apiKeyForServer($row->server);
                $clients[$key] = $clients[$key] ?? new BunnyClient($key);
                $res = self::billServiceMonth($row, $month, $clients[$key]);
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
