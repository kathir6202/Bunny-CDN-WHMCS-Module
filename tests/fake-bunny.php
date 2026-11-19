<?php
/**
 * Fake bunny.net API for local tests: php -S 127.0.0.1:8977 tests/fake-bunny.php
 * State lives in tests/.tmp/state.json. Only the endpoints the module uses are implemented.
 */
$f = __DIR__ . '/.tmp/state.json';
$s = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
$s += ['dns' => [], 'pz' => [], 'sz' => [], 'seq' => 100, 'log' => [], 'purged' => [], 'fail' => []];
$m = $_SERVER['REQUEST_METHOD'];
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b = json_decode(file_get_contents('php://input'), true) ?: [];
$s['log'][] = "$m $p";

function out($code, $data = null)
{
    global $s, $f;
    file_put_contents($f, json_encode($s));
    http_response_code($code);
    header('Content-Type: application/json');
    if ($data !== null) {
        echo json_encode($data);
    }
    exit;
}
function nid()
{
    global $s;
    return ++$s['seq'];
}

// ------------------------------------------- Edge Storage API (zone password)
if (preg_match('#^/__storage/([^/]+)(/.*)?$#', $p, $x)) {
    $zoneName = rawurldecode($x[1]);
    $path = rawurldecode(ltrim($x[2] ?? '/', '/'));
    $zone = null;
    foreach ($s['sz'] as $z) {
        if ($z['Name'] === $zoneName) {
            $zone = $z;
        }
    }
    if (!$zone || ($_SERVER['HTTP_ACCESSKEY'] ?? '') !== $zone['Password']) {
        out(401, ['Message' => 'Unauthorized']);
    }
    $files = &$s['files'][$zoneName];
    $files = $files ?? [];
    $isDir = $path === '' || substr($path, -1) === '/';
    $prefix = rtrim($path, '/');
    if ($m === 'PUT') {
        $files[$path] = ['data' => base64_encode(file_get_contents('php://input')), 'time' => gmdate('Y-m-d\TH:i:s')];
        out(201, ['HttpCode' => 201, 'Message' => 'File uploaded.']);
    }
    if ($m === 'DELETE') {
        $found = false;
        foreach (array_keys($files) as $file) {
            if ($file === $prefix || ($isDir && strpos($file, $prefix . '/') === 0)) {
                unset($files[$file]);
                $found = true;
            }
        }
        out($found ? 200 : 404, ['Message' => $found ? 'Deleted' : 'Object Not Found']);
    }
    if ($m === 'GET' && $isDir) {
        $list = [];
        foreach ($files as $file => $meta) {
            $rel = $prefix === '' ? $file : (strpos($file, $prefix . '/') === 0 ? substr($file, strlen($prefix) + 1) : null);
            if ($rel === null) {
                continue;
            }
            $parts = explode('/', $rel);
            $name = $parts[0];
            $list[$name] = count($parts) > 1
                ? ['ObjectName' => $name, 'IsDirectory' => true, 'Length' => 0, 'LastChanged' => $meta['time']]
                : ['ObjectName' => $name, 'IsDirectory' => false, 'Length' => strlen(base64_decode($meta['data'])), 'LastChanged' => $meta['time']];
        }
        out(200, array_values($list));
    }
    if ($m === 'GET') {
        if (!isset($files[$path])) {
            out(404, ['Message' => 'Object Not Found']);
        }
        file_put_contents($f, json_encode($s));
        header('Content-Type: application/octet-stream');
        echo base64_decode($files[$path]['data']);
        exit;
    }
}

if (($_SERVER['HTTP_ACCESSKEY'] ?? '') !== 'test-key') {
    out(401, ['Message' => 'The request authorization failed']);
}
// --------------------------------------------------------- Logging service
if (preg_match('#^/__logging/(\d\d-\d\d-\d\d)/(\d+)\.log$#', $p, $x)) {
    $z = $s['pz'][$x[2]] ?? null;
    if (!$z || empty($z['EnableLogging'])) {
        out(404, ['Message' => 'Not found']);
    }
    file_put_contents($f, json_encode($s));
    header('Content-Type: text/plain');
    echo "HIT|200|1700000000000|1024|{$x[2]}|203.0.113.1|-|https://example.com/a.css|DE|Mozilla|abc|DE\n";
    echo "MISS|404|1700000001000|512|{$x[2]}|203.0.113.2|-|https://example.com/missing|US|curl|def|US\n";
    exit;
}

// Tests can make one endpoint fail: state "fail" => ["POST /pullzone" => 500]
if (isset($s['fail']["$m $p"])) {
    out($s['fail']["$m $p"], ['Message' => 'Simulated failure']);
}

// ------------------------------------------------------------------ DNS zones
if ($m === 'POST' && $p === '/dnszone') {
    foreach ($s['dns'] as $z) {
        if ($z['Domain'] === $b['Domain']) {
            out(400, ['Message' => 'The zone already exists']);
        }
    }
    $z = ['Id' => nid(), 'Domain' => $b['Domain'], 'Nameserver1' => 'kiki.bunny.net', 'Nameserver2' => 'coco.bunny.net',
        'NameserversDetected' => true, 'Records' => []];
    $s['dns'][$z['Id']] = $z;
    out(201, $z);
}
if ($m === 'GET' && $p === '/dnszone') {
    $q = $_GET['search'] ?? '';
    out(200, ['Items' => array_values(array_filter($s['dns'], function ($z) use ($q) { return strpos($z['Domain'], $q) !== false; }))]);
}
if ($m === 'POST' && $p === '/dnszone/records/scan') {
    out(200, []);
}
if (preg_match('#^/dnszone/(\d+)/records/scan$#', $p)) {
    out(200, ['Status' => 2, 'Records' => [['Type' => 'TXT', 'Name' => '@', 'Value' => 'scan-found-txt', 'Ttl' => 300]]]);
}
if (preg_match('#^/dnszone/(\d+)/dnssec$#', $p, $x)) {
    if (!isset($s['dns'][$x[1]])) {
        out(404, ['Message' => 'Zone not found']);
    }
    $s['dns'][$x[1]]['DnsSecEnabled'] = $m === 'POST';
    out(200, $m === 'POST' ? ['Enabled' => true, 'KeyTag' => 12345, 'Algorithm' => 13, 'DigestType' => 2, 'Digest' => 'ABCDEF0123456789', 'Flags' => 257, 'PublicKey' => 'mdsswUyr3DPW132mOi8V9xESWE8jTo0d'] : []);
}
if (preg_match('#^/dnszone/(\d+)(/records(?:/(\d+))?)?$#', $p, $x)) {
    $zid = $x[1];
    if (!isset($s['dns'][$zid])) {
        out(404, ['Message' => 'Zone not found']);
    }
    if (empty($x[2])) {
        if ($m === 'GET') {
            out(200, $s['dns'][$zid]);
        }
        if ($m === 'DELETE') {
            unset($s['dns'][$zid]);
            out(204);
        }
    }
    if ($m === 'GET' && empty($x[3])) {
        out(405, ['Message' => 'Method not allowed']); // like the real API
    }
    if ($m === 'PUT' && empty($x[3])) {
        if (!isset($b['Type'], $b['Value'])) {
            out(400, ['Message' => 'Invalid record']);
        }
        if ((int) $b['Type'] === 7 && empty($b['PullZoneId'])) {
            out(400, ['Message' => 'PullZoneId is required']);
        }
        $r = $b + ['Id' => nid()];
        $s['dns'][$zid]['Records'][] = $r;
        out(201, $r);
    }
    $rid = (int) ($x[3] ?? 0);
    foreach ($s['dns'][$zid]['Records'] as $i => $r) {
        if ((int) $r['Id'] === $rid) {
            if ($m === 'DELETE') {
                array_splice($s['dns'][$zid]['Records'], $i, 1);
                out(204);
            }
            if ($m === 'POST') {
                $s['dns'][$zid]['Records'][$i] = $b + ['Id' => $rid];
                out(204);
            }
        }
    }
    out(404, ['Message' => 'Record not found']);
}

// -------------------------------------------------------------- Storage zones
if ($m === 'POST' && $p === '/storagezone') {
    foreach ($s['sz'] as $z) {
        if ($z['Name'] === $b['Name']) {
            out(400, ['Message' => 'The storage zone name is already taken']);
        }
    }
    $z = ['Id' => nid(), 'Name' => $b['Name'], 'Region' => $b['Region'], 'ReplicationRegions' => $b['ReplicationRegions'] ?? [], 'Password' => 'pw-' . substr(md5(mt_rand()), 0, 12),
        'ReadOnlyPassword' => 'ro-' . substr(md5(mt_rand()), 0, 12),
        'StorageHostname' => $b['Region'] === 'DE' ? 'storage.bunnycdn.com' : strtolower($b['Region']) . '.storage.bunnycdn.com',
        'StorageUsed' => 3000000000, 'FilesStored' => 42];
    $s['sz'][$z['Id']] = $z;
    out(201, $z);
}
if ($p === '/storagezone/resetPassword') {
    $sid = $_GET['id'] ?? 0;
    if (!isset($s['sz'][$sid])) {
        out(404, ['Message' => 'Storage zone not found']);
    }
    $s['sz'][$sid]['Password'] = 'pw-' . substr(md5(mt_rand()), 0, 12);
    out(204);
}
if (preg_match('#^/storagezone/(\d+)$#', $p, $x)) {
    if (!isset($s['sz'][$x[1]])) {
        out(404, ['Message' => 'Storage zone not found']);
    }
    if ($m === 'POST') {
        $s['sz'][$x[1]]['ReplicationRegions'] = $b['ReplicationZones'] ?? [];
        out(204);
    }
    if ($m === 'GET') {
        out(200, $s['sz'][$x[1]]);
    }
    if ($m === 'DELETE') {
        unset($s['sz'][$x[1]]);
        out(204);
    }
}

// ------------------------------------------------------------------- Purging
if ($m === 'POST' && $p === '/purge') {
    $s['purged'][] = $_GET['url'] ?? '';
    out(200);
}

// ---------------------------------------------------------------- Pull zones
if ($m === 'POST' && $p === '/pullzone') {
    if (empty($b['OriginUrl']) && empty($b['StorageZoneId'])) {
        out(400, ['Message' => 'OriginUrl is required']);
    }
    foreach ($s['pz'] as $z) {
        if ($z['Name'] === $b['Name']) {
            out(400, ['Message' => 'The pull zone name is already taken']);
        }
    }
    $z = ['Id' => nid(), 'Name' => $b['Name'], 'OriginUrl' => $b['OriginUrl'] ?? '', 'StorageZoneId' => $b['StorageZoneId'] ?? 0,
        'Type' => $b['Type'] ?? 0, 'Enabled' => true,
        'Hostnames' => [['Value' => $b['Name'] . '.b-cdn.net', 'IsSystemHostname' => true, 'HasCertificate' => true, 'ForceSSL' => false]],
        'MonthlyBandwidthUsed' => 7500000000, 'MonthlyCharges' => 0.075, 'MonthlyBandwidthLimit' => 0,
        'CacheControlMaxAgeOverride' => -1, 'CacheControlBrowserMaxAgeOverride' => -1, 'IgnoreQueryStrings' => false,
        'BlockedCountries' => [], 'AllowedReferrers' => [], 'BlockNoneReferrer' => false, 'BlockedIps' => [],
        'ZoneSecurityEnabled' => false, 'ZoneSecurityKey' => md5(mt_rand()), 'EnableOriginShield' => false];
    $s['pz'][$z['Id']] = $z;
    out(201, $z);
}
if ($m === 'GET' && $p === '/pullzone') {
    out(200, ['Items' => array_values($s['pz']), 'HasMoreItems' => false]);
}
if ($p === '/pullzone/loadFreeCertificate') {
    $s['certs'][] = $_GET['hostname'] ?? '';
    out(200);
}
if (preg_match('#^/pullzone/(\d+)(/(\w+))?$#', $p, $x)) {
    $pid = $x[1];
    if (!isset($s['pz'][$pid])) {
        out(404, ['Message' => 'Pull zone not found']);
    }
    $op = $x[3] ?? '';
    if ($op === '' && $m === 'GET') {
        out(200, $s['pz'][$pid]);
    }
    if ($op === '' && $m === 'DELETE') {
        unset($s['pz'][$pid]);
        out(204);
    }
    if ($op === '' && $m === 'POST') {
        $s['pz'][$pid] = array_merge($s['pz'][$pid], $b);
        out(200, $s['pz'][$pid]);
    }
    if ($op === 'addHostname') {
        // Like bunny.net: a hostname can only be on one pull zone in the account
        foreach ($s['pz'] as $zone) {
            foreach ($zone['Hostnames'] as $h) {
                if ($h['Value'] === $b['Hostname']) {
                    out(400, ['Message' => 'The hostname is already registered']);
                }
            }
        }
        $s['pz'][$pid]['Hostnames'][] = ['Value' => $b['Hostname'], 'IsSystemHostname' => false, 'HasCertificate' => false, 'ForceSSL' => false];
        out(204);
    }
    if ($op === 'removeHostname') {
        $s['pz'][$pid]['Hostnames'] = array_values(array_filter($s['pz'][$pid]['Hostnames'], function ($h) use ($b) { return $h['Value'] !== $b['Hostname']; }));
        out(204);
    }
    if ($op === 'setForceSSL') {
        foreach ($s['pz'][$pid]['Hostnames'] as &$h) {
            if ($h['Value'] === $b['Hostname']) {
                $h['ForceSSL'] = $b['ForceSSL'];
            }
        }
        unset($h);
        out(204);
    }
    if ($op === 'resetSecurityKey') {
        $s['pz'][$pid]['ZoneSecurityKey'] = md5(mt_rand());
        out(204);
    }
    if ($op === 'addCertificate') {
        if (base64_decode($b['Certificate'] ?? '', true) === false || base64_decode($b['CertificateKey'] ?? '', true) === false) {
            out(400, ['Message' => 'Certificate must be base64']);
        }
        foreach ($s['pz'][$pid]['Hostnames'] as &$h) {
            if ($h['Value'] === $b['Hostname']) {
                $h['HasCertificate'] = true;
                $h['CustomCertificate'] = true;
            }
        }
        unset($h);
        out(204);
    }
    if ($op === 'purgeCache') {
        $s['purged'][] = 'ALL:' . $pid;
        out(204);
    }
}

// ---------------------------------------------------------------- Statistics
if ($p === '/statistics') {
    $chart = [];
    for ($d = strtotime(substr($_GET['dateFrom'], 0, 10)); $d <= strtotime(substr($_GET['dateTo'], 0, 10)); $d += 86400) {
        $chart[date('Y-m-d', $d) . 'T00:00:00Z'] = (int) (2e8 + abs(sin($d / 90000)) * 6e8);
    }
    $res = ['TotalBandwidthUsed' => 12500000000, 'TotalRequestsServed' => 123456, 'CacheHitRate' => 91.27, 'BandwidthUsedChart' => $chart,
        'GeoTrafficDistribution' => ['EU: Germany (DE)' => 5000000000, 'NA: United States (US)' => 6000000000, 'AS: Singapore (SG)' => 1500000000],
        'TotalOriginTraffic' => 1200000000, 'AverageOriginResponseTime' => 42];
    if (($_GET['loadErrors'] ?? '') === 'true') {
        $res += ['Error3xxChart' => ['2026-01-01T00:00:00Z' => 100], 'Error4xxChart' => ['2026-01-01T00:00:00Z' => 456], 'Error5xxChart' => ['2026-01-01T00:00:00Z' => 7]];
    }
    out(200, $res);
}

out(404, ['Message' => "No route $m $p"]);
