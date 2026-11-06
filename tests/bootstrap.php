<?php
/**
 * Minimal WHMCS stand-in for testing the module outside WHMCS:
 * SQLite database with the WHMCS tables the module touches, stubbed WHMCS
 * functions (localAPI, logActivity, ...), hooks, and a fixed fake DNS.
 */

namespace SeekahostTest {

    /** Fixed DNS answers in dns_get_record() format; extra records from .tmp/dns-extra.json. */
    class Dns
    {
        const TYPES = ['A' => 1, 'NS' => 2, 'CNAME' => 16, 'SOA' => 32, 'MX' => 16384, 'TXT' => 32768,
            'AAAA' => 134217728, 'SRV' => 33554432, 'CAA' => 8192];

        public static function zone()
        {
            $zone = [
                'example.com' => [['A', '93.184.215.14'], ['MX', 'mail.example.com', 10], ['TXT', 'v=spf1 -all'],
                    ['NS', 'a.iana-servers.net'], ['NS', 'b.iana-servers.net'], ['SOA', '']],
                'www.example.com' => [['CNAME', 'example.com']],
                'mail.example.com' => [['A', '93.184.215.20']],
                '_dmarc.example.com' => [['TXT', 'v=DMARC1; p=reject']],
                'shop-test.co.uk' => [['A', '203.0.113.9'], ['MX', 'mx.shop-test.co.uk', 10], ['SOA', '']],
                '*.shop-test.co.uk' => [['CNAME', 'shop-test.co.uk']],
                'mail.shop-test.co.uk' => [['A', '203.0.113.25']],
                'static.example.org' => [['A', '203.0.113.30']],
                'iana.org' => [['A', '192.0.43.8'], ['SOA', '']],
                'imported.example.net' => [['A', '203.0.113.77']],
                'addon-site.example.com' => [['A', '203.0.113.66']],
                'failpz.example.com' => [['A', '203.0.113.99']],
            ];
            $extra = __DIR__ . '/.tmp/dns-extra.json';
            if (file_exists($extra)) {
                foreach (json_decode(file_get_contents($extra), true) as $host => $records) {
                    $zone[$host] = $records;
                }
            }
            return $zone;
        }

        public static function lookup($host, $type)
        {
            $host = strtolower(rtrim($host, '.'));
            $zone = self::zone();
            $records = $zone[$host] ?? null;
            if ($records === null) {
                // Wildcard: closest "*.parent" entry answers for any missing name below it
                $labels = explode('.', $host);
                for ($i = 1; $i < count($labels) - 1 && $records === null; $i++) {
                    $records = $zone['*.' . implode('.', array_slice($labels, $i))] ?? null;
                }
            }
            $out = [];
            foreach ((array) $records as $r) {
                if (!(self::TYPES[$r[0]] & $type)) {
                    continue;
                }
                $rec = ['host' => $host, 'type' => $r[0], 'ttl' => 3600];
                switch ($r[0]) {
                    case 'A': $rec['ip'] = $r[1]; break;
                    case 'AAAA': $rec['ipv6'] = $r[1]; break;
                    case 'TXT': $rec['txt'] = $r[1]; $rec['entries'] = [$r[1]]; break;
                    case 'MX': $rec['target'] = $r[1]; $rec['pri'] = $r[2]; break;
                    default: $rec['target'] = $r[1];
                }
                $out[] = $rec;
            }
            return $out;
        }
    }
}

namespace SeekahostCDN {
    // The module calls these unqualified inside its namespace, so these take precedence
    function dns_get_record($host, $type = DNS_ANY)
    {
        return \SeekahostTest\Dns::lookup($host, $type);
    }

    function gethostbyname($host)
    {
        $a = \SeekahostTest\Dns::lookup($host, DNS_A);
        return $a ? $a[0]['ip'] : $host;
    }

    function sleep($seconds)
    {
        return 0; // bunny scan polling without waiting
    }
}

namespace {
    use Illuminate\Database\Capsule\Manager as DB;

    define('WHMCS', true);
    define('SHT_TMP', __DIR__ . '/.tmp');
    require __DIR__ . '/vendor/autoload.php';
    class_alias(DB::class, 'WHMCS\Database\Capsule');

    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => SHT_TMP . '/whmcs.sqlite']);
    $db->setAsGlobal();

    if (!DB::schema()->hasTable('tblhosting')) {
        $s = DB::schema();
        $s->create('tblclients', function ($t) { $t->increments('id'); $t->string('firstname'); $t->string('lastname'); $t->string('companyname')->default(''); $t->integer('currency')->default(1); });
        $s->create('tblcurrencies', function ($t) { $t->increments('id'); $t->string('code'); $t->string('prefix'); $t->string('suffix'); $t->decimal('rate', 10, 5); $t->integer('default'); });
        $s->create('tblservers', function ($t) { $t->increments('id'); $t->string('name'); $t->string('type'); $t->string('ipaddress'); $t->string('accesshash'); $t->integer('disabled')->default(0); });
        $s->create('tbladdonmodules', function ($t) { $t->string('module'); $t->string('setting'); $t->text('value')->nullable(); });
        $s->create('tblproducts', function ($t) { $t->increments('id'); $t->string('name'); $t->string('servertype'); });
        $s->create('tblhostingaddons', function ($t) { $t->increments('id'); $t->integer('hostingid'); $t->string('status'); });
        $s->create('tbltodolist', function ($t) { $t->increments('id'); $t->date('date'); $t->string('title'); $t->text('description'); $t->integer('admin'); $t->string('status'); $t->date('duedate'); });
        $s->create('tblemailtemplates', function ($t) { $t->increments('id'); $t->string('type'); $t->string('name'); $t->string('subject'); $t->text('message'); $t->integer('custom')->default(0); $t->string('language')->default(''); });
        $s->create('tblhosting', function ($t) {
            $t->increments('id'); $t->integer('userid'); $t->integer('packageid'); $t->integer('server'); $t->string('domain'); $t->string('domainstatus');
            $t->string('suspendreason')->default(''); $t->string('dedicatedip')->default(''); $t->string('ns1')->default(''); $t->string('ns2')->default('');
            $t->string('paymentmethod')->default('paypal'); $t->date('regdate')->nullable(); $t->integer('bwusage')->default(0); $t->integer('bwlimit')->default(0);
            $t->timestamp('lastupdate')->nullable();
        });

        DB::table('tblcurrencies')->insert([
            ['code' => 'USD', 'prefix' => '$', 'suffix' => ' USD', 'rate' => 1, 'default' => 1],
            ['code' => 'GBP', 'prefix' => '£', 'suffix' => ' GBP', 'rate' => 0.8, 'default' => 0],
        ]);
        DB::table('tblclients')->insert([
            ['firstname' => 'Ann', 'lastname' => 'Lee', 'currency' => 1],
            ['firstname' => 'Bob', 'lastname' => 'UK', 'currency' => 2],
        ]);
        DB::table('tblservers')->insert([
            ['name' => 'Bunny', 'type' => 'seekahostcdn', 'ipaddress' => '', 'accesshash' => 'test-key'],
            ['name' => 'cPanel1', 'type' => 'cpanel', 'ipaddress' => '198.51.100.20', 'accesshash' => 'WHM-TOKEN-NOT-BUNNY'],
        ]);
        DB::table('tblproducts')->insert([
            ['name' => 'CDN', 'servertype' => 'seekahostcdn'],
            ['name' => 'Hosting (CDN included)', 'servertype' => 'cpanel'],
            ['name' => 'Hosting basic', 'servertype' => 'cpanel'],
        ]);
        $svc = function ($user, $pid, $server, $domain, $reg) {
            return ['userid' => $user, 'packageid' => $pid, 'server' => $server, 'domain' => $domain, 'domainstatus' => 'Active', 'regdate' => $reg];
        };
        DB::table('tblhosting')->insert([
            $svc(1, 1, 1, 'example.com', '2026-08-15'),            // 1 nameserver mode
            $svc(2, 1, 1, 'www.shop-test.co.uk', '2026-09-20'),    // 2 CNAME mode, GBP client
            $svc(1, 1, 1, 'static.example.org', '2026-09-01'),     // 3 CNAME + storage
            $svc(1, 2, 2, 'iana.org', '2026-09-01'),               // 4 hosting with included CDN
            $svc(2, 1, 1, 'imported.example.net', '2026-09-01'),   // 5 import target
            $svc(1, 3, 2, 'addon-site.example.com', '2026-10-01'), // 6 hosting with paid CDN addon
            $svc(1, 1, 1, 'failpz.example.com', '2026-10-01'),     // 7 rollback test
            $svc(2, 3, 2, 'basic-hosting.example.com', '2026-10-01'), // 8 hosting, no CDN
        ]);
        DB::table('tblhostingaddons')->insert(['id' => 7, 'hostingid' => 6, 'status' => 'Active']);
        foreach (['price_per_gb' => '0.10', 'enable_billing' => 'on', 'billing_day' => '1', 'min_invoice' => '0.50', 'invoice_due_days' => '7',
            'tax_usage' => '', 'price_per_gb_storage' => '0.02', 'warn_percent' => '80', 'overage_action' => 'suspend', 'auto_unsuspend' => 'on',
            'included_products' => '2', 'included_mode' => 'CNAME only', 'included_limit_gb' => '50', 'included_bill' => ''] as $k => $v) {
            DB::table('tbladdonmodules')->insert(['module' => 'seekahostcdn', 'setting' => $k, 'value' => $v]);
        }
    }

    function sht_log($file, $line)
    {
        file_put_contents(SHT_TMP . '/' . $file, $line . "\n", FILE_APPEND);
    }
    function logActivity($m, $u = 0) { sht_log('activity.log', $m); }
    function logModuleCall($mod, $action, $req, $resp, $proc = '', $mask = [])
    {
        sht_log('modulecall.log', $action . ' | ' . str_replace($mask, '***', is_string($resp) ? $resp : json_encode($resp)));
    }
    function generate_token($t = 'form') { return 'tok123'; }
    function localAPI($cmd, $data)
    {
        sht_log('localapi.log', $cmd . ' ' . json_encode($data));
        if ($cmd === 'ModuleSuspend') {
            DB::table('tblhosting')->where('id', $data['serviceid'])->update(['domainstatus' => 'Suspended', 'suspendreason' => $data['suspendreason'] ?? '']);
            return ['result' => seekahostcdn_SuspendAccount(params($data['serviceid'])) === 'success' ? 'success' : 'error'];
        }
        if ($cmd === 'ModuleUnsuspend') {
            DB::table('tblhosting')->where('id', $data['serviceid'])->update(['domainstatus' => 'Active', 'suspendreason' => '']);
            return ['result' => seekahostcdn_UnsuspendAccount(params($data['serviceid'])) === 'success' ? 'success' : 'error'];
        }
        if ($cmd === 'CreateInvoice' && file_exists(SHT_TMP . '/fail-invoice')) {
            return ['result' => 'error', 'message' => 'Simulated invoice failure'];
        }
        if ($cmd === 'CreateInvoice') {
            $n = (int) @file_get_contents(SHT_TMP . '/invoice-seq') ?: 500;
            file_put_contents(SHT_TMP . '/invoice-seq', ++$n);
            return ['result' => 'success', 'invoiceid' => $n];
        }
        return ['result' => 'success'];
    }

    $GLOBALS['sht_hooks'] = [];
    function add_hook($name, $prio, $fn) { $GLOBALS['sht_hooks'][$name][] = $fn; }
    function run_hook($name, $vars)
    {
        $out = [];
        foreach ($GLOBALS['sht_hooks'][$name] ?? [] as $fn) {
            $out[] = $fn($vars);
        }
        return $out;
    }

    $root = dirname(__DIR__) . '/modules';
    require $root . '/servers/seekahostcdn/seekahostcdn.php';
    require $root . '/addons/seekahostcdn/seekahostcdn.php';
    require $root . '/addons/seekahostcdn/hooks.php';
    SeekahostCDN\BunnyClient::$baseUrl = 'http://127.0.0.1:8977';

    /** Module params as WHMCS would pass them for a service. */
    function params($sid, array $extra = [])
    {
        $h = DB::table('tblhosting')->where('id', $sid)->first();
        $product = [
            1 => ['configoption1' => '', 'configoption2' => '100'],
            2 => ['configoption1' => '203.0.113.9', 'configoption2' => '8', 'configoption4' => 'CNAME only'],
            3 => ['configoption2' => '0', 'configoption4' => 'CNAME only', 'configoption5' => 'Storage zone as origin',
                'configoption6' => 'NY (New York)', 'configoptions' => ['Bandwidth' => '5']],
            7 => ['configoption4' => 'CNAME only'],
        ];
        return array_merge([
            'serviceid' => $sid, 'userid' => $h->userid, 'pid' => $h->packageid, 'serverid' => 1, 'serveraccesshash' => 'test-key',
            'domain' => $h->domain, 'status' => $h->domainstatus, 'moduletype' => 'seekahostcdn',
            'configoption1' => '', 'configoption2' => '100', 'configoption3' => 'Standard', 'configoption4' => 'Nameservers (full DNS)',
            'configoption5' => 'None', 'configoption6' => 'DE (Falkenstein)', 'customfields' => [], 'configoptions' => [],
        ], $product[$sid] ?? [], $extra);
    }
}
