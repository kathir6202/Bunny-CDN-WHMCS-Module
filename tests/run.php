<?php
/**
 * End-to-end test of the SeekaHost CDN module against the fake bunny.net API.
 * Run with tests/run.sh (starts the fake API). Prints PASS/FAIL per check.
 */

$tmp = __DIR__ . '/.tmp';
if (is_dir($tmp)) {
    shell_exec('rm -rf ' . escapeshellarg($tmp));
}
mkdir($tmp . '/tplc', 0777, true);
touch($tmp . '/whmcs.sqlite');

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;
use SeekahostCDN\Module as CDN;

$results = ['pass' => 0, 'fail' => []];
function ok($cond, $name, $detail = '')
{
    global $results;
    if ($cond) {
        $results['pass']++;
        echo "  PASS  {$name}\n";
    } else {
        $results['fail'][] = $name;
        echo "  FAIL  {$name}" . ($detail !== '' ? "  -> " . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
    }
}
function section($t) { echo "\n== {$t}\n"; }
function ajax($sid, $query, $uid = null)
{
    $out = shell_exec('php ' . escapeshellarg(__DIR__ . '/cli.php') . ' ajax ' . (int) $sid . ' ' . escapeshellarg($query . '&token=tok123')
        . ($uid !== null ? ' ' . (int) $uid : '') . ' 2>&1');
    $json = json_decode((string) $out, true);
    return is_array($json) ? $json : ['success' => false, 'error' => 'NOT JSON: ' . substr((string) $out, 0, 300)];
}
function ajaxRaw($sid, $query)
{
    return json_decode((string) shell_exec('php ' . escapeshellarg(__DIR__ . '/cli.php') . ' ajax ' . (int) $sid . ' ' . escapeshellarg($query) . ' 2>&1'), true);
}
function render($sid)
{
    return (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/cli.php') . ' render ' . (int) $sid . ' 2>&1');
}
function adminPage($tab, $post = '')
{
    return (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/cli.php') . ' admin ' . escapeshellarg($tab) . ' ' . escapeshellarg($post) . ' 2>&1');
}
function fake() { return json_decode(file_get_contents(SHT_TMP . '/state.json'), true); }
function fakeEdit(callable $fn)
{
    $s = fake();
    $s = $fn($s);
    file_put_contents(SHT_TMP . '/state.json', json_encode($s));
}
function row($sid) { return CDN::zoneForService($sid); }
function phpErrors($text) { return preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $text); }
function jsValid($html)
{
    preg_match_all('#<script>(.*?)</script>#s', $html, $m);
    $file = SHT_TMP . '/check.js';
    file_put_contents($file, implode("\n", $m[1]));
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $o, $code);
    return $code === 0;
}
function logHas($file, $needle) { return strpos((string) @file_get_contents(SHT_TMP . '/' . $file), $needle) !== false; }
function clearLog($file) { @unlink(SHT_TMP . '/' . $file); }
function recordsOf($zoneId) { return fake()['dns'][$zoneId]['Records'] ?? []; }
function hasRecord($records, $type, $name, $value = null)
{
    foreach ($records as $r) {
        if ((int) $r['Type'] === $type && $r['Name'] === $name && ($value === null || $r['Value'] === $value)) {
            return true;
        }
    }
    return false;
}

// ============================================================== Installation
section('Install');
$act = seekahostcdn_activate();
ok($act['status'] === 'success', 'addon activates');
foreach (['mod_seekahostcdn_dns_zones', 'mod_seekahostcdn_usage', 'mod_seekahostcdn_billing', 'mod_seekahostcdn_alerts'] as $t) {
    ok(DB::schema()->hasTable($t), "table {$t} exists");
}
ok(DB::table('tblemailtemplates')->where('type', 'product')->count() === 2, 'two bandwidth email templates created');
seekahostcdn_activate();
ok(DB::table('tblemailtemplates')->count() === 2, 're-activating does not duplicate templates');
ok(seekahostcdn_TestConnection(params(1))['success'] === true, 'Test Connection succeeds with valid key');
ok(seekahostcdn_TestConnection(params(1, ['serveraccesshash' => 'wrong']))['success'] === false, 'Test Connection fails with wrong key');
ok(count(seekahostcdn_ConfigOptions()) === 6, 'product has 6 module settings');

// ============================================================== Provisioning
section('Provisioning: nameserver mode (example.com)');
$r = seekahostcdn_CreateAccount(params(1));
ok($r === 'success', 'create succeeds', $r);
$z1 = row(1);
ok($z1 && $z1->mode === 'dns' && $z1->dns_zone_id !== '', 'DNS zone created and stored');
ok($z1 && $z1->origin_url === 'http://93.184.215.14', 'origin = current A record of the domain', $z1->origin_url ?? '');
ok($z1 && $z1->nameserver_1 === 'kiki.bunny.net' && $z1->nameserver_2 === 'coco.bunny.net', 'bunny nameservers stored');
$rec = recordsOf($z1->dns_zone_id);
ok(hasRecord($rec, 4, '', 'mail.example.com'), 'MX record imported');
ok(hasRecord($rec, 3, '', 'v=spf1 -all'), 'SPF TXT imported');
ok(hasRecord($rec, 3, '_dmarc'), 'DMARC TXT imported');
ok(hasRecord($rec, 0, 'mail', '93.184.215.20'), 'mail A record imported');
ok(hasRecord($rec, 3, '', 'scan-found-txt'), 'record from bunny scan merged');
ok(hasRecord($rec, 7, '') && hasRecord($rec, 7, 'www'), '@ and www point to the pull zone');
ok(!hasRecord($rec, 0, '', '93.184.215.14') && !hasRecord($rec, 2, 'www'), 'old @ A and www CNAME replaced, not duplicated');
$h = DB::table('tblhosting')->find(1);
ok($h->dedicatedip === $z1->pull_zone_id && $h->ns1 === 'kiki.bunny.net', 'service shows pull zone ID and nameservers (welcome email fields)');
ok(CDN::decodeList($z1->hostnames) === ['example.com', 'www.example.com'], 'hostnames example.com + www added');
ok((int) $z1->limit_bytes === 100000000000, 'limit 100 GB from product setting');
$pz1 = fake()['pz'][$z1->pull_zone_id];
ok($pz1['MonthlyBandwidthLimit'] === 100000000000 && $pz1['AddHostHeader'] === true, 'pull zone limit and Host header set');
ok(seekahostcdn_CreateAccount(params(1)) === 'success' && count(fake()['pz']) === 1, 're-running create is safe (no duplicates)');

section('Provisioning: CNAME mode (www.shop-test.co.uk)');
ok(seekahostcdn_CreateAccount(params(2)) === 'success', 'create succeeds');
$z2 = row(2);
ok($z2->mode === 'cname' && $z2->dns_zone_id === '', 'no DNS zone in CNAME mode');
ok(CDN::decodeList($z2->hostnames) === ['www.shop-test.co.uk'], 'only the given hostname is added (www kept)');
ok($z2->origin_url === 'http://203.0.113.9', 'origin from product setting');
ok((int) $z2->limit_bytes === 8000000000, 'limit 8 GB');

section('Provisioning: CNAME + storage zone (static.example.org)');
ok(seekahostcdn_CreateAccount(params(3)) === 'success', 'create succeeds');
$z3 = row(3);
$sz = fake()['sz'][$z3->storage_zone_id] ?? null;
ok($sz && $sz['Region'] === 'NY', 'storage zone created in NY');
ok((int) fake()['pz'][$z3->pull_zone_id]['StorageZoneId'] === (int) $z3->storage_zone_id, 'pull zone serves from the storage zone');
ok((int) $z3->limit_bytes === 5000000000, 'limit 5 GB from "Bandwidth" configurable option');
ok(CDN::decodeList($z3->hostnames) === ['static.example.org'], 'subdomain: single hostname, no www');

section('Provisioning: errors and rollback');
fakeEdit(function ($s) { $s['fail']['POST /pullzone'] = 500; return $s; });
$before = fake();
$res = seekahostcdn_CreateAccount(params(7, ['configoption4' => 'Nameservers (full DNS)', 'configoption5' => 'Storage zone as origin']));
$after = fake();
fakeEdit(function ($s) { $s['fail'] = []; return $s; });
ok($res !== 'success', 'create reports the bunny.net error', $res);
ok(count($after['dns']) === count($before['dns']) && count($after['sz']) === count($before['sz']), 'DNS and storage zones rolled back after failure');
ok(row(7) === null, 'nothing saved for the failed service');
$res = seekahostcdn_CreateAccount(params(5, ['domain' => 'example.com']));
ok(strpos($res, 'already used') !== false, 'same domain on two services is refused', $res);
$res = seekahostcdn_CreateAccount(params(5, ['domain' => 'not a domain']));
ok(strpos($res, 'not a valid domain') !== false, 'invalid domain refused', $res);

section('Selling modes: included with hosting / paid addon');
run_hook('AfterModuleCreate', ['params' => params(4, ['moduletype' => 'cpanel'])]);
$z4 = row(4);
ok($z4 && $z4->source === 'included' && $z4->mode === 'cname', 'hosting product in the list gets an included CDN');
ok($z4 && $z4->origin_url === 'http://198.51.100.20', 'included CDN origin = hosting server IP');
ok($z4 && (int) $z4->server_id === 1, 'included CDN uses the bunny server, not the cPanel token');
ok($z4 && CDN::decodeList($z4->hostnames) === ['iana.org', 'www.iana.org'], 'root domain gets root + www hostnames');
ok(DB::table('tblhosting')->find(4)->dedicatedip === '', "hosting service's own fields untouched");
run_hook('AfterModuleCreate', ['params' => params(8, ['moduletype' => 'cpanel'])]);
ok(row(8) === null, 'hosting product not in the list gets no CDN');
$r = seekahostcdn_CreateAccount(params(6, ['addonId' => 7, 'configoption1' => '203.0.113.66', 'configoption4' => 'CNAME only', 'configoption2' => '20']));
$z6 = row(6);
ok($r === 'success' && $z6->source === 'addon' && (int) $z6->addon_id === 7, 'paid addon provisions with addon source');
ok(DB::table('tblhosting')->find(6)->dedicatedip === '', 'paid addon leaves the hosting service fields alone');

// ================================================================ Client area
section('Client area pages');
foreach ([1 => 'nameserver', 2 => 'CNAME', 3 => 'storage', 4 => 'included', 6 => 'paid addon'] as $sid => $label) {
    $html = render($sid);
    ok(!phpErrors($html) && strpos($html, 'id="shcdn"') !== false, "{$label} page renders without PHP errors");
    ok(jsValid($html), "{$label} page JavaScript is valid");
    ok(stripos($html, 'MonthlyCharges') === false && strpos($html, '0.075') === false, "{$label} page never shows bunny.net cost");
}
$html = render(1);
ok(strpos($html, 'kiki.bunny.net') !== false && strpos($html, 'Waiting for nameservers') !== false, 'nameserver page shows NS and waiting status');
ok(strpos($html, 'data-tab="dns"') !== false, 'nameserver page has DNS tab');
$html = render(2);
ok(strpos($html, 'Connect your domain') !== false && strpos($html, $z2->cdn_url) !== false, 'CNAME page shows the record to add');
ok(strpos($html, 'data-tab="dns"') === false, 'CNAME page has no DNS tab');
ok(strpos(render(3), 'data-tab="storage"') !== false, 'storage page has storage tab');
ok(strpos(render(4), 'ALIAS') !== false, 'root domain in CNAME mode gets ALIAS guidance');
$pending = render(7);
ok(strpos($pending, 'Setup has not completed yet') !== false, 'unprovisioned active service shows a pending message');

section('Client actions: DNS editor (nameserver mode)');
$res = ajax(1, 'shcdn_action=records');
ok($res['success'] && count($res['records']) >= 6, 'list records');
$res = ajax(1, 'shcdn_action=add&type=0&name=blog&value=192.0.2.10&ttl=300');
ok($res['success'] && hasRecord(recordsOf($z1->dns_zone_id), 0, 'blog', '192.0.2.10'), 'add A record');
ok(!ajax(1, 'shcdn_action=add&type=0&name=blog&value=999.1.1.1')['success'], 'invalid IPv4 refused');
ok(!ajax(1, 'shcdn_action=add&type=2&name=@&value=x.example.com')['success'], 'CNAME on root refused');
ok(!ajax(1, 'shcdn_action=add&type=12&name=x&value=ns.evil.com')['success'], 'NS record type not allowed');
ok(!ajax(1, 'shcdn_action=add&type=5&name=x&value=https://evil.com')['success'], 'Redirect type not allowed');
ok(!ajax(1, 'shcdn_action=add&type=0&name=bad..name&value=192.0.2.1')['success'], 'invalid record name refused');
ok(ajax(1, 'shcdn_action=add&type=0&name=blog.example.com&value=192.0.2.11')['success'] && hasRecord(recordsOf($z1->dns_zone_id), 0, 'blog', '192.0.2.11'), 'full hostname is shortened to the label');
ok(ajax(1, 'shcdn_action=add&type=4&name=@&value=mx2.example.net&priority=20')['success'], 'add MX with priority');
ok(ajax(1, 'shcdn_action=add&type=8&name=_sip._tcp&value=sip.example.com&priority=10&weight=5&port=5060')['success'], 'add SRV');
ok(ajax(1, 'shcdn_action=add&type=9&name=@&value=letsencrypt.org&flags=0&tag=issue')['success'], 'add CAA');
ok(ajax(1, 'shcdn_action=add&type=7&name=shop')['success'] && in_array('shop.example.com', CDN::decodeList(row(1)->hostnames), true), 'CDN record adds the hostname to the pull zone');
ok(ajax(1, 'shcdn_action=add&type=3&name=xss&value=' . urlencode('<script>alert(1)</script>'))['success'], 'TXT with HTML is stored as text');
$html = render(1);
ok(strpos($html, '<script>alert(1)</script>') === false, 'stored HTML is not rendered raw (no XSS)');
$blog = null;
foreach (recordsOf($z1->dns_zone_id) as $rr) {
    if ($rr['Name'] === 'blog') { $blog = $rr; }
}
ok(ajax(1, 'shcdn_action=update&record_id=' . $blog['Id'] . '&type=0&name=blog&value=192.0.2.99')['success'] && hasRecord(recordsOf($z1->dns_zone_id), 0, 'blog', '192.0.2.99'), 'update record');
ok(!ajax(1, 'shcdn_action=update&record_id=99999&type=0&name=blog&value=192.0.2.1')['success'], 'update of unknown record refused');
ok(ajax(1, 'shcdn_action=delete&record_id=' . $blog['Id'])['success'] && !hasRecord(recordsOf($z1->dns_zone_id), 0, 'blog', '192.0.2.99')
    && hasRecord(recordsOf($z1->dns_zone_id), 0, 'blog', '192.0.2.10'), 'delete record (only the chosen one)');
$res = ajax(1, 'shcdn_action=import');
ok($res['success'], 'import existing DNS runs', $res);

section('Client actions: security');
$bad = ajaxRaw(1, 'shcdn_action=purge&token=wrong');
ok(isset($bad['success']) && !$bad['success'] && strpos($bad['error'], 'session') !== false, 'wrong CSRF token refused');
ok(!ajax(2, 'shcdn_action=records')['success'], 'DNS actions blocked in CNAME mode');
ok(ajax(4, 'shcdn_action=purge', 2)['error'] === 'Access denied.', "another client cannot use someone else's included CDN");
DB::table('tblhosting')->where('id', 2)->update(['domainstatus' => 'Suspended']);
ok(!ajax(2, 'shcdn_action=purge')['success'], 'suspended service cannot make changes');
ok(ajax(2, 'shcdn_action=stats')['success'], 'suspended service can still view stats');
DB::table('tblhosting')->where('id', 2)->update(['domainstatus' => 'Active']);
DB::table('tblhostingaddons')->where('id', 7)->update(['status' => 'Suspended']);
ok(!ajax(6, 'shcdn_action=purge')['success'], 'suspended paid addon cannot make changes');
DB::table('tblhostingaddons')->where('id', 7)->update(['status' => 'Active']);

section('Client actions: CDN settings, purge, storage, stats');
$res = ajax(2, 'shcdn_action=settings');
ok($res['success'] && $res['settings']['origin'] === 'http://203.0.113.9', 'read CDN settings');
ok(!ajax(2, 'shcdn_action=settings_save&origin=www.shop-test.co.uk&cacheTime=-1&browserCacheTime=-1')['success'], 'origin pointing at the CDN itself refused');
ok(!ajax(2, 'shcdn_action=settings_save&origin=10.0.0.5&cacheTime=-1&browserCacheTime=-1')['success'], 'private origin IP refused');
ok(!ajax(2, 'shcdn_action=settings_save&origin=203.0.113.9&cacheTime=123&browserCacheTime=-1')['success'], 'cache time outside the list refused');
ok(!ajax(2, 'shcdn_action=settings_save&origin=203.0.113.9&cacheTime=-1&browserCacheTime=-1&blockedCountries=CHN')['success'], '3-letter country code refused');
ok(!ajax(2, 'shcdn_action=settings_save&origin=203.0.113.9&cacheTime=-1&browserCacheTime=-1&blockedIps=1.2.3')['success'], 'invalid blocked IP refused');
$res = ajax(2, 'shcdn_action=settings_save&origin=' . urlencode('https://203.0.113.50:8443') . '&cacheTime=86400&browserCacheTime=3600&forceSsl=1&ignoreQueryStrings=1&blockedCountries=' . urlencode('cn, ru') . '&allowedReferrers=' . urlencode("example.com\n*.shop-test.co.uk") . '&blockNoneReferrer=1&blockedIps=198.51.100.7');
$pz2 = fake()['pz'][$z2->pull_zone_id];
ok($res['success'] && $pz2['OriginUrl'] === 'https://203.0.113.50:8443' && $pz2['CacheControlMaxAgeOverride'] === 86400
    && $pz2['BlockedCountries'] === ['CN', 'RU'] && $pz2['AllowedReferrers'] === ['example.com', '*.shop-test.co.uk']
    && $pz2['BlockNoneReferrer'] === true && $pz2['BlockedIps'] === ['198.51.100.7'] && $pz2['IgnoreQueryStrings'] === true, 'save CDN settings', $res);
ok(row(2)->origin_url === 'https://203.0.113.50:8443', 'new origin stored for admins');
ok(ajax(2, 'shcdn_action=purge')['success'] && in_array('ALL:' . $z2->pull_zone_id, fake()['purged'], true), 'purge whole cache');
$res = ajax(2, 'shcdn_action=purge_urls&urls=' . urlencode("https://www.shop-test.co.uk/a.css\nhttps://www.shop-test.co.uk/img/*"));
ok($res['success'] && in_array('https://www.shop-test.co.uk/img/*', fake()['purged'], true), 'purge specific URLs');
ok(!ajax(2, 'shcdn_action=purge_urls&urls=' . urlencode('https://evil.com/x'))['success'], "purging another site's URL refused");
ok(!ajax(2, 'shcdn_action=purge_urls&urls=' . urlencode(implode("\n", array_map(function ($i) { return "https://www.shop-test.co.uk/{$i}"; }, range(1, 31)))))['success'], 'more than 30 URLs refused');
$res = ajax(3, 'shcdn_action=storage');
ok($res['success'] && $res['storage']['ftpHost'] === 'ny.storage.bunnycdn.com' && $res['storage']['password'] !== '', 'storage details');
$old = $res['storage']['password'];
$res = ajax(3, 'shcdn_action=storage_reset');
ok($res['success'] && $res['storage']['password'] !== $old, 'storage password reset');
ok(!ajax(1, 'shcdn_action=storage')['success'], 'storage action refused when there is no storage zone');
$res = ajax(2, 'shcdn_action=stats');
ok($res['success'] && $res['charge'] === '£1.00 GBP', 'GBP client sees charge in GBP (12.5 GB x $0.10 x 0.8)', $res['charge'] ?? $res);
ok(ajax(1, 'shcdn_action=stats')['charge'] === '$1.25 USD', 'USD client sees charge in USD');
ok(ajax(4, 'shcdn_action=stats')['charge'] === 'Included', 'included CDN shows "Included" instead of a charge');
$res = ajax(1, 'shcdn_action=stats&range=last_month');
ok($res['success'] && $res['from'] === date('Y-m-01', strtotime('first day of last month')), 'last month range');

section('SSL and connection detection');
$res = ajax(2, 'shcdn_action=ssl');
ok(!$res['success'] && strpos($res['error'], 'CNAME') !== false, 'SSL waits until the CNAME exists');
file_put_contents(SHT_TMP . '/dns-extra.json', json_encode([
    'www.shop-test.co.uk' => [['CNAME', $z2->cdn_url]],
    'example.com' => [['A', '93.184.215.14'], ['NS', 'kiki.bunny.net'], ['NS', 'coco.bunny.net'], ['SOA', '']],
]));
$res = ajax(2, 'shcdn_action=ssl');
ok($res['success'] && in_array('www.shop-test.co.uk', fake()['certs'] ?? [], true), 'SSL issued once the CNAME points to the CDN');
ok(fake()['pz'][$z2->pull_zone_id]['Hostnames'][1]['ForceSSL'] === true, 'HTTPS forced after the certificate');
$res = seekahostcdn_issuessl(params(1));
ok($res === 'success' && count(CDN::decodeList(row(1)->ssl_hostnames)) >= 2, 'nameserver mode: SSL issued once NS point to bunny.net');
ok((int) row(1)->nameservers_detected === 1 && strpos(render(1), 'CDN live') !== false, 'status changes to "CDN live"');
ok(CDN::nameserversPointed('iana.org') === false, 'a domain on other nameservers is not reported as pointed');

// =================================================================== Lifecycle
section('Suspend / unsuspend / upgrade');
seekahostcdn_SuspendAccount(params(2));
ok(fake()['pz'][$z2->pull_zone_id]['MonthlyBandwidthLimit'] === 1 && (int) row(2)->suspended === 1, 'suspend stops the CDN');
ok(count(recordsOf($z1->dns_zone_id)) > 0, 'DNS untouched by suspend');
seekahostcdn_UnsuspendAccount(params(2));
ok(fake()['pz'][$z2->pull_zone_id]['MonthlyBandwidthLimit'] === 8000000000 && (int) row(2)->suspended === 0, 'unsuspend restores the limit');
seekahostcdn_ChangePackage(params(1, ['configoptions' => ['Bandwidth' => '1000']]));
ok((int) row(1)->limit_bytes === 1000000000000 && fake()['pz'][$z1->pull_zone_id]['MonthlyBandwidthLimit'] === 1000000000000, 'bandwidth upgrade to 1 TB via configurable option');
seekahostcdn_ChangePackage(params(1, ['configoptions' => ['Bandwidth' => '0']]));
ok((int) row(1)->limit_bytes === 0, '"Unlimited" option (0) removes the limit');

section('Usage sync, alerts and overage');
clearLog('localapi.log');
list($synced, $errors) = CDN::syncAllUsage();
ok($synced === 5 && !$errors, 'sync all zones', $errors);
ok(logHas('localapi.log', '"messagename":"SeekaHost CDN Bandwidth Warning","id":2'), 'warning email at 94% (7.5 of 8 GB)');
ok(logHas('localapi.log', '"messagename":"SeekaHost CDN Bandwidth Limit Reached","id":3'), 'limit email at 150% (7.5 of 5 GB)');
ok(DB::table('tbltodolist')->where('title', 'like', '%static.example.org%')->exists(), 'admin To-Do created');
ok(DB::table('tblhosting')->find(3)->domainstatus === 'Suspended' && (int) row(3)->suspended === 1, 'over-limit service suspended (overage action = suspend)');
ok(DB::table('tblhosting')->find(2)->domainstatus === 'Active', 'warned service stays active');
ok(DB::table('tblhosting')->find(1)->bwusage === 7153, 'WHMCS bandwidth usage updated');
ok(DB::table('mod_seekahostcdn_usage')->where('service_id', 3)->value('storage_bytes') == 3000000000, 'storage usage recorded');
clearLog('localapi.log');
CDN::syncAllUsage();
ok(!logHas('localapi.log', 'SendEmail'), 'alerts are not repeated in the same month');
$html = render(3);
ok(strpos($html, 'bandwidth limit has been reached') !== false, 'client sees the bandwidth-paused notice');
DB::table('mod_seekahostcdn_dns_zones')->where('service_id', 3)->update(['overage_month' => date('Y-m', strtotime('first day of last month'))]);
CDN::syncAllUsage();
ok(DB::table('tblhosting')->find(3)->domainstatus === 'Active' && (int) row(3)->suspended === 0, 'new month: bandwidth suspension lifted automatically');
DB::table('tblhosting')->where('id', 2)->update(['domainstatus' => 'Suspended', 'suspendreason' => 'Overdue']);
DB::table('mod_seekahostcdn_dns_zones')->where('service_id', 2)->update(['suspended' => 1, 'overage_month' => '2000-01']);
CDN::resetOverages();
ok(DB::table('tblhosting')->find(2)->domainstatus === 'Suspended', 'non-payment suspension is NOT lifted by the bandwidth reset');
DB::table('tblhosting')->where('id', 2)->update(['domainstatus' => 'Active', 'suspendreason' => '']);
seekahostcdn_UnsuspendAccount(params(2));
ok(seekahostcdn_UsageUpdate(['serverid' => 1, 'serveraccesshash' => 'test-key']) === 'success', 'WHMCS UsageUpdate runs');

section('Billing');
DB::table('mod_seekahostcdn_usage')->update(['usage_date' => date('Y-m-t', strtotime('first day of last month'))]);
// The addon was ordered today: it must not be billed for last month...
ok(CDN::billServiceMonth(row(6), date('Y-m', strtotime('first day of last month'))) === 'not active in period', 'service created this month is not billed for last month');
// ...then pretend it existed last month for the rest of the billing checks
DB::table('mod_seekahostcdn_dns_zones')->where('service_id', 6)->update(['created_at' => date('Y-m-01 00:00:00', strtotime('first day of last month'))]);
$month = date('Y-m', strtotime('first day of last month'));
clearLog('localapi.log');
$sum = CDN::runMonthlyBilling($month);
ok($sum['invoiced'] === 4 && !$sum['errors'], 'invoices created for the 4 billable services', $sum);
$bills = DB::table('mod_seekahostcdn_billing')->get()->keyBy('service_id');
ok(!isset($bills[4]), 'included CDN not billed');
ok(isset($bills[2]) && $bills[2]->currency_code === 'GBP' && (float) $bills[2]->client_amount === 1.0, 'GBP client invoiced £1.00');
ok(isset($bills[3]) && (float) $bills[3]->storage_gb === 3.0 && (float) $bills[3]->storage_cost === 0.06, 'storage line: 3 GB x $0.02');
ok(logHas('localapi.log', 'CDN storage for static.example.org'), 'invoice has a storage line');
ok(isset($bills[6]), 'paid addon CDN billed');
ok((float) $bills[1]->bunny_cost === 0.075, 'bunny.net cost recorded for margin');
$sum = CDN::runMonthlyBilling($month);
ok($sum['invoiced'] === 0, 'running billing again creates no duplicates');
DB::table('mod_seekahostcdn_billing')->truncate();
touch(SHT_TMP . '/fail-invoice');
CDN::runMonthlyBilling($month);
@unlink(SHT_TMP . '/fail-invoice');
$failed = DB::table('mod_seekahostcdn_billing')->where('status', 'failed')->first();
ok($failed !== null, 'failed invoice is recorded as failed');
$page = adminPage('billing', 'shcdn_admin=retry&billing_id=' . $failed->id);
ok(strpos($page, 'Retry result: invoiced') !== false, 'admin can retry a failed invoice');
DB::table('mod_seekahostcdn_billing')->truncate();
DB::table('tbladdonmodules')->where('setting', 'min_invoice')->update(['value' => '5']);
CDN::runMonthlyBilling($month);
ok(DB::table('mod_seekahostcdn_billing')->where('status', 'below_minimum')->count() === 4, 'amounts under the minimum are skipped');
DB::table('tbladdonmodules')->where('setting', 'min_invoice')->update(['value' => '0.50']);
DB::table('mod_seekahostcdn_billing')->truncate();
DB::table('tbladdonmodules')->where('setting', 'included_bill')->update(['value' => 'on']);
DB::table('mod_seekahostcdn_dns_zones')->where('service_id', 4)->update(['created_at' => date('Y-m-01 00:00:00', strtotime('first day of last month'))]);
CDN::runMonthlyBilling($month);
ok(DB::table('mod_seekahostcdn_billing')->where('service_id', 4)->exists(), 'included CDN billed when "Bill Included CDN Usage" is on');
DB::table('tbladdonmodules')->where('setting', 'included_bill')->update(['value' => '']);

// ======================================================================= Admin
section('Admin');
foreach (['dashboard', 'billing', 'import', 'help'] as $tab) {
    $page = adminPage($tab);
    ok(!phpErrors($page) && strlen($page) > 500, "addon {$tab} page renders");
}
$page = adminPage('dashboard');
ok(strpos($page, 'Included') !== false && strpos($page, 'CNAME') !== false && strpos($page, 'Margin %') !== false, 'dashboard shows source, mode and margin %');
$fields = seekahostcdn_AdminServicesTabFields(params(2));
ok(isset($fields['Cost vs Price (MTD)']) && strpos($fields['Cost vs Price (MTD)'], 'Margin') !== false, 'service tab shows cost vs price');
$_POST = ['shcdn_add_host' => 'assets.shop-test.co.uk', 'shcdn_admin_action' => 'purgecache'];
seekahostcdn_AdminServicesTabFieldsSave(params(2));
ok(in_array('assets.shop-test.co.uk', CDN::decodeList(row(2)->hostnames), true), 'admin adds a hostname');
$_POST = ['shcdn_remove_host' => ['assets.shop-test.co.uk']];
seekahostcdn_AdminServicesTabFieldsSave(params(2));
ok(!in_array('assets.shop-test.co.uk', CDN::decodeList(row(2)->hostnames), true), 'admin removes a hostname');
$_POST = ['shcdn_add_host' => 'www.iana.org'];
seekahostcdn_AdminServicesTabFieldsSave(params(2));
ok(!in_array('www.iana.org', CDN::decodeList(row(2)->hostnames), true) && logHas('activity.log', 'already used by CDN service #4'), "hostname of another service refused");
$_POST = [];
$hookFields = array_merge(...array_filter(run_hook('AdminClientServicesTabFields', ['id' => 4])));
ok(isset($hookFields['CDN']) && isset($hookFields['CDN: Hostnames / SSL']), 'included CDN shows on the hosting service admin page');
ok(strpos(seekahostcdn_importdns(params(2)), 'CNAME mode') !== false, 'DNS-only admin buttons explain CNAME mode');

section('Import existing zones');
$legacy = json_decode(shell_exec("curl -s -X POST -H 'AccessKey: test-key' -d '{\"Name\":\"legacy-zone\",\"OriginUrl\":\"http://203.0.113.77\"}' http://127.0.0.1:8977/pullzone"), true);
shell_exec("curl -s -o /dev/null -X POST -H 'AccessKey: test-key' -d '{\"Hostname\":\"imported.example.net\"}' http://127.0.0.1:8977/pullzone/{$legacy['Id']}/addHostname");
$page = adminPage('import');
ok(strpos($page, 'legacy-zone') !== false && preg_match('/name="service_id"[^>]*value="5"/', $page), 'unlinked zone listed with the matching service suggested');
$page = adminPage('import', "shcdn_admin=link&service_id=5&pull_zone_id={$legacy['Id']}&server_id=1");
ok(strpos($page, 'is now linked') !== false && row(5) && row(5)->mode === 'cname', 'zone linked to service');
$page = adminPage('import', "shcdn_admin=link&service_id=5&pull_zone_id={$legacy['Id']}&server_id=1");
ok(strpos($page, 'already') !== false, 'linking twice refused');
ok(strpos(adminPage('import', "shcdn_admin=link&service_id=5&pull_zone_id=1&server_id=1&token=bad"), 'Invalid security token') !== false, 'admin forms check the CSRF token');

section('Included CDN lifecycle hooks');
run_hook('AfterModuleSuspend', ['params' => params(4, ['moduletype' => 'cpanel'])]);
ok((int) row(4)->suspended === 1, 'hosting suspended -> CDN suspended');
run_hook('AfterModuleUnsuspend', ['params' => params(4, ['moduletype' => 'cpanel'])]);
ok((int) row(4)->suspended === 0, 'hosting unsuspended -> CDN unsuspended');
run_hook('AfterModuleTerminate', ['params' => params(4, ['moduletype' => 'cpanel'])]);
ok(row(4) === null && !isset(fake()['pz'][$z4->pull_zone_id]), 'hosting terminated -> CDN deleted');
$page = adminPage('help', 'shcdn_admin=include_existing');
ok(strpos($page, 'Created 1 included CDN') !== false && row(4) !== null, 'backfill adds the CDN to existing hosting services');

section('Terminate');
DB::table('mod_seekahostcdn_billing')->truncate();
ok(seekahostcdn_TerminateAccount(params(3)) === 'success', 'terminate succeeds');
$s = fake();
ok(!isset($s['pz'][$z3->pull_zone_id]) && !isset($s['sz'][$z3->storage_zone_id]), 'pull zone and storage zone deleted');
ok(DB::table('mod_seekahostcdn_billing')->where('service_id', 3)->where('billing_month', date('Y-m'))->exists(), 'final invoice for this month created');
ok(seekahostcdn_TerminateAccount(params(1)) === 'success' && !isset(fake()['dns'][$z1->dns_zone_id]), 'nameserver mode: DNS zone deleted too');
ok(seekahostcdn_TerminateAccount(params(6, ['addonId' => 7])) === 'success' && row(6) === null, 'paid addon terminated');
fakeEdit(function ($s) use ($z2) { unset($s['pz'][$z2->pull_zone_id]); return $s; });
ok(seekahostcdn_TerminateAccount(params(2)) === 'success', 'terminate works when the zone was already deleted at bunny.net');
ok(seekahostcdn_TerminateAccount(params(8)) === 'success', 'terminate of a never-provisioned service is a no-op');

// ===================================================================== Summary
$total = $results['pass'] + count($results['fail']);
echo "\n" . str_repeat('=', 60) . "\n{$results['pass']} / {$total} checks passed\n";
if ($results['fail']) {
    echo "Failed:\n  - " . implode("\n  - ", $results['fail']) . "\n";
    exit(1);
}
