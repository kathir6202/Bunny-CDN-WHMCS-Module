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

if (($_SERVER['HTTP_ACCESSKEY'] ?? '') !== 'test-key') {
    out(401, ['Message' => 'The request authorization failed']);
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
    $z = ['Id' => nid(), 'Name' => $b['Name'], 'Region' => $b['Region'], 'Password' => 'pw-' . substr(md5(mt_rand()), 0, 12),
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
        'BlockedCountries' => [], 'AllowedReferrers' => [], 'BlockNoneReferrer' => false, 'BlockedIps' => []];
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
        foreach ($s['pz'][$pid]['Hostnames'] as $h) {
            if ($h['Value'] === $b['Hostname']) {
                out(400, ['Message' => 'The hostname is already registered']);
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
    out(200, ['TotalBandwidthUsed' => 12500000000, 'TotalRequestsServed' => 123456, 'CacheHitRate' => 91.27, 'BandwidthUsedChart' => $chart]);
}

out(404, ['Message' => "No route $m $p"]);
