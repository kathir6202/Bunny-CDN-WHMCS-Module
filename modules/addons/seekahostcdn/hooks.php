<?php
/**
 * SeekaHost CDN cron automation: usage/cost sync, SSL retries and monthly usage invoices.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('DailyCronJob', 1, function ($vars) {
    $lib = __DIR__ . '/../../servers/seekahostcdn/lib/Module.php';
    if (!file_exists($lib)) {
        return;
    }
    require_once $lib;

    try {
        list($synced, $errors) = SeekahostCDN\Module::syncAllUsage();
        foreach ($errors as $err) {
            logActivity('SeekaHost CDN usage sync: ' . $err);
        }

        $settings = SeekahostCDN\Module::settings();
        if (SeekahostCDN\Module::isOn($settings['enable_billing'])
            && (int) date('j') >= (int) $settings['billing_day']) {
            // Runs every day from the billing day on, so a missed cron day still bills.
            // Services already billed for last month are skipped without an API call.
            SeekahostCDN\Module::runMonthlyBilling();
        }
    } catch (Exception $e) {
        logActivity('SeekaHost CDN cron error: ' . $e->getMessage());
    }
});
