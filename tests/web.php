<?php
/**
 * Minimal web front for tests that need a real HTTP request (file uploads):
 * POST /clientarea.php?id=N is handled like WHMCS's product details page.
 */
require __DIR__ . '/bootstrap.php';
$sid = (int) ($_GET['id'] ?? 0);
$row = SeekahostCDN\Module::zoneForService($sid);
if ($row && $row->source !== 'product') {
    $_SESSION['uid'] = (int) Illuminate\Database\Capsule\Manager::table('tblhosting')->where('id', $sid)->value('userid');
    run_hook('ClientAreaPageProductDetails', ['serviceid' => $sid]);
}
seekahostcdn_ClientArea(params($sid));
echo json_encode(['success' => false, 'error' => 'NOT HANDLED']);
