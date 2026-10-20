<?php
/**
 * SeekaHost CDN hooks:
 *  - daily cron: usage/cost sync, SSL, bandwidth alerts and monthly usage invoices
 *  - "included with hosting": provision/suspend/terminate the CDN with the hosting service
 *  - client and admin pages for CDNs that live on a hosting service (paid addon or included)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use SeekahostCDN\Module as SeekahostCDNModule;
use WHMCS\Database\Capsule;

/** Load the shared library (and optionally the server module's UI functions). */
function seekahostcdn_hookLoad($withServerModule = false)
{
    $base = __DIR__ . '/../../servers/seekahostcdn/';
    if (!file_exists($base . 'lib/Module.php')) {
        return false;
    }
    require_once $base . 'lib/Module.php';
    if ($withServerModule && !function_exists('seekahostcdn_clientVars')) {
        require_once $base . 'seekahostcdn.php';
    }
    return true;
}

/** CDN row that belongs to a hosting service (not a standalone CDN product). */
function seekahostcdn_hookRow($serviceId)
{
    if (!$serviceId || !seekahostcdn_hookLoad()) {
        return null;
    }
    try {
        $row = SeekahostCDNModule::zoneForService((int) $serviceId);
    } catch (Exception $e) {
        return null;
    }
    return ($row && ($row->source ?? 'product') !== 'product') ? $row : null;
}

// ------------------------------------------------------------------ Daily cron

add_hook('DailyCronJob', 1, function ($vars) {
    if (!seekahostcdn_hookLoad()) {
        return;
    }
    try {
        list($synced, $errors) = SeekahostCDNModule::syncAllUsage();
        foreach ($errors as $err) {
            logActivity('SeekaHost CDN usage sync: ' . $err);
        }

        $settings = SeekahostCDNModule::settings();
        if (SeekahostCDNModule::isOn($settings['enable_billing'])
            && (int) date('j') >= (int) $settings['billing_day']) {
            // Runs every day from the billing day on, so a missed cron day still bills.
            // Services already billed for last month are skipped without an API call.
            SeekahostCDNModule::runMonthlyBilling();
        }
    } catch (Exception $e) {
        logActivity('SeekaHost CDN cron error: ' . $e->getMessage());
    }
});

// ------------------------------------------------- Included-with-hosting CDN

add_hook('AfterModuleCreate', 1, function ($vars) {
    $p = $vars['params'] ?? [];
    if (($p['moduletype'] ?? '') === 'seekahostcdn' || !empty($p['addonId']) || !seekahostcdn_hookLoad()) {
        return;
    }
    $pid = (int) ($p['pid'] ?? $p['packageid'] ?? 0);
    if (!$pid || !in_array($pid, SeekahostCDNModule::includedProductIds(), true)) {
        return;
    }
    try {
        SeekahostCDNModule::provision(SeekahostCDNModule::includedParams((int) $p['serviceid']));
    } catch (Exception $e) {
        logActivity('SeekaHost CDN: included CDN for service #' . (int) $p['serviceid'] . ' failed: ' . $e->getMessage());
    }
});

add_hook('AfterModuleSuspend', 1, function ($vars) {
    $p = $vars['params'] ?? [];
    if (!empty($p['addonId']) || ($p['moduletype'] ?? '') === 'seekahostcdn') {
        return;
    }
    $row = seekahostcdn_hookRow($p['serviceid'] ?? 0);
    if ($row && $row->source === 'included' && !$row->suspended) {
        try {
            SeekahostCDNModule::suspendRow($row);
        } catch (Exception $e) {
            logActivity('SeekaHost CDN: suspend for service #' . $row->service_id . ' failed: ' . $e->getMessage());
        }
    }
});

add_hook('AfterModuleUnsuspend', 1, function ($vars) {
    $p = $vars['params'] ?? [];
    if (!empty($p['addonId']) || ($p['moduletype'] ?? '') === 'seekahostcdn') {
        return;
    }
    $row = seekahostcdn_hookRow($p['serviceid'] ?? 0);
    // A CDN paused for bandwidth stays paused until the new month
    if ($row && $row->source === 'included' && $row->suspended && $row->overage_month !== date('Y-m')) {
        try {
            SeekahostCDNModule::unsuspendRow($row);
        } catch (Exception $e) {
            logActivity('SeekaHost CDN: unsuspend for service #' . $row->service_id . ' failed: ' . $e->getMessage());
        }
    }
});

add_hook('AfterModuleTerminate', 1, function ($vars) {
    $p = $vars['params'] ?? [];
    if (!empty($p['addonId']) || ($p['moduletype'] ?? '') === 'seekahostcdn') {
        return;
    }
    $row = seekahostcdn_hookRow($p['serviceid'] ?? 0);
    if ($row && $row->source === 'included') {
        try {
            SeekahostCDNModule::terminateRow($row);
        } catch (Exception $e) {
            logActivity('SeekaHost CDN: terminate for service #' . $row->service_id . ' failed: ' . $e->getMessage());
        }
    }
});

// ---------------------------------------- Client area on the hosting service

function seekahostcdn_hookClientId()
{
    if (class_exists('\WHMCS\Session')) {
        $uid = (int) \WHMCS\Session::get('uid');
        if ($uid) {
            return $uid;
        }
    }
    return (int) ($_SESSION['uid'] ?? 0);
}

// AJAX calls from the CDN panel post back to the product details page
add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['shcdn_action'])) {
        return;
    }
    $serviceId = (int) ($vars['serviceid'] ?? $vars['id'] ?? $_GET['id'] ?? 0);
    $row = seekahostcdn_hookRow($serviceId);
    if (!$row || !seekahostcdn_hookLoad(true)) {
        return;
    }
    $userId = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
    if (!$userId || $userId !== seekahostcdn_hookClientId()) {
        seekahostcdn_json(['success' => false, 'error' => 'Access denied.']);
    }
    seekahostcdn_handleClientAjax($row, seekahostcdn_clientContext($row, $userId));
});

add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
    $service = $vars['service'] ?? null;
    $serviceId = is_object($service) ? (int) $service->id : (int) ($vars['serviceid'] ?? 0);
    $row = seekahostcdn_hookRow($serviceId);
    if (!$row || !seekahostcdn_hookLoad(true)) {
        return '';
    }
    try {
        $userId = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
        $tplVars = seekahostcdn_clientVars($row, seekahostcdn_clientContext($row, $userId), $serviceId);
        $smarty = class_exists('\WHMCS\Smarty') ? new \WHMCS\Smarty() : new \Smarty();
        foreach ($tplVars as $k => $v) {
            $smarty->assign($k, $v);
        }
        return '<div style="margin-top:24px">' . $smarty->fetch(__DIR__ . '/../../servers/seekahostcdn/templates/client/overview.tpl') . '</div>';
    } catch (Exception $e) {
        logActivity('SeekaHost CDN: client panel for service #' . $serviceId . ' failed: ' . $e->getMessage());
        return '';
    }
});

// ------------------------------------------ Admin service page (addon/included)

add_hook('AdminClientServicesTabFields', 1, function ($vars) {
    $row = seekahostcdn_hookRow((int) ($vars['id'] ?? 0));
    if (!$row || !seekahostcdn_hookLoad(true)) {
        return [];
    }
    $fields = [];
    foreach (seekahostcdn_adminFields($row) as $label => $html) {
        $fields[$label === 'CDN' ? 'CDN' : 'CDN: ' . $label] = $html;
    }
    return $fields;
});

add_hook('AdminClientServicesTabFieldsSave', 1, function ($vars) {
    $row = seekahostcdn_hookRow((int) ($vars['id'] ?? 0));
    if (!$row || !seekahostcdn_hookLoad(true)) {
        return;
    }
    try {
        seekahostcdn_adminFieldsSave($row);
    } catch (Exception $e) {
        logActivity('SeekaHost CDN service #' . $row->service_id . ' admin change failed: ' . $e->getMessage());
    }
});
