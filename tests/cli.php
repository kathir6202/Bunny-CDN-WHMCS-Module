<?php
/**
 * Runs one request-like step in its own process (AJAX handlers end with exit).
 *   php cli.php ajax <serviceId> "<query string>" [clientId]
 *   php cli.php render <serviceId> [bootstrap3|bootstrap4]
 *   php cli.php admin <tab> ["<post query string>"]
 */
require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$cmd = $argv[1] ?? '';
$sid = (int) ($argv[2] ?? 0);

function sht_smarty()
{
    $sm = new Smarty();
    $sm->setCompileDir(SHT_TMP . '/tplc');
    return $sm;
}

if ($cmd === 'ajax') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    parse_str($argv[3] ?? '', $_POST);
    $row = SeekahostCDN\Module::zoneForService($sid);
    if ($row && $row->source !== 'product') {
        $_SESSION['uid'] = (int) ($argv[4] ?? DB::table('tblhosting')->where('id', $sid)->value('userid'));
        run_hook('ClientAreaPageProductDetails', ['serviceid' => $sid]);
        echo json_encode(['success' => false, 'error' => 'HOOK DID NOT HANDLE']);
    } else {
        seekahostcdn_ClientArea(params($sid));
        echo json_encode(['success' => false, 'error' => 'MODULE DID NOT HANDLE']);
    }
    exit;
}

if ($cmd === 'render') {
    $row = SeekahostCDN\Module::zoneForService($sid);
    $html = '';
    if ($row && $row->source !== 'product') {
        $html = implode('', run_hook('ClientAreaProductDetailsOutput', ['service' => (object) ['id' => $sid]]));
    } else {
        $r = seekahostcdn_ClientArea(params($sid));
        $sm = sht_smarty();
        $sm->assign($r['vars']);
        $html = $sm->fetch(dirname(__DIR__) . '/modules/servers/seekahostcdn/' . $r['templatefile'] . '.tpl');
    }
    $bs = ($argv[3] ?? '') === 'bootstrap4' ? 'bootstrap-4.6.2' : 'bootstrap-3.4.1';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<link rel="stylesheet" href="/ui/' . $bs . '/package/dist/css/bootstrap.min.css">'
        . '<style>body{background:#f6f7f9;padding:20px}</style></head><body><div class="container"><h2>Product Details</h2>'
        . $html . '</div></body></html>';
    exit;
}

if ($cmd === 'admin') {
    $_GET['tab'] = $argv[2] ?? 'dashboard';
    if (!empty($argv[3])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        parse_str($argv[3], $_POST);
        $_POST['token'] = $_POST['token'] ?? 'tok123';
    }
    seekahostcdn_output(['modulelink' => 'addonmodules.php?module=seekahostcdn']);
    exit;
}

fwrite(STDERR, "Unknown command\n");
exit(1);
