<?php
/**
 * SeekaHost CDN - admin addon: pricing, billing, and bunny.net cost vs price reporting.
 *
 * Install to: modules/addons/seekahostcdn/
 * Requires the server module in modules/servers/seekahostcdn/.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

$seekahostcdnLib = __DIR__ . '/../../servers/seekahostcdn/lib/Module.php';
if (file_exists($seekahostcdnLib)) {
    require_once $seekahostcdnLib;
}

function seekahostcdn_config()
{
    $days = [];
    for ($i = 1; $i <= 28; $i++) {
        $days[$i] = (string) $i;
    }
    return [
        'name' => 'SeekaHost CDN',
        'description' => 'bunny.net CDN reselling: per-GB pricing, monthly usage invoices and cost/margin reports.',
        'version' => '2.0.1',
        'author' => 'SeekaHost',
        'language' => 'english',
        'fields' => [
            'price_per_gb' => [
                'FriendlyName' => 'Price per GB',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '0.05',
                'Description' => 'What clients pay per GB of bandwidth, in your default currency. Converted for clients in other currencies.',
            ],
            'enable_billing' => [
                'FriendlyName' => 'Monthly Usage Invoices',
                'Type' => 'yesno',
                'Description' => 'Invoice every CDN service for the previous month\'s bandwidth.',
            ],
            'billing_day' => [
                'FriendlyName' => 'Billing Day',
                'Type' => 'dropdown',
                'Options' => $days,
                'Default' => '1',
                'Description' => 'Day of the month on which last month\'s usage is invoiced.',
            ],
            'min_invoice' => [
                'FriendlyName' => 'Minimum Invoice',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '0.50',
                'Description' => 'Skip invoices below this amount (default currency).',
            ],
            'invoice_due_days' => [
                'FriendlyName' => 'Invoice Due (days)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '7',
            ],
            'tax_usage' => [
                'FriendlyName' => 'Apply Tax',
                'Type' => 'yesno',
                'Description' => 'Mark usage invoice lines as taxable.',
            ],
        ],
    ];
}

function seekahostcdn_activate()
{
    if (!class_exists('SeekahostCDN\\Module')) {
        return ['status' => 'error', 'description' => 'Upload the server module to modules/servers/seekahostcdn/ first.'];
    }
    try {
        SeekahostCDN\Module::ensureSchema();
        return ['status' => 'success', 'description' => 'SeekaHost CDN activated. Set your price per GB under Configure.'];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => 'Could not create tables: ' . $e->getMessage()];
    }
}

function seekahostcdn_upgrade($vars)
{
    if (class_exists('SeekahostCDN\\Module')) {
        SeekahostCDN\Module::ensureSchema();
    }
}

function seekahostcdn_deactivate()
{
    // Tables are kept: they hold billing history.
    return ['status' => 'success', 'description' => 'SeekaHost CDN deactivated. Data has been kept.'];
}

function seekahostcdn_output($vars)
{
    if (!class_exists('SeekahostCDN\\Module')) {
        echo '<div class="alert alert-danger">The server module is missing. Upload it to modules/servers/seekahostcdn/.</div>';
        return;
    }
    $cdn = 'SeekahostCDN\\Module';
    $cdn::ensureSchema();

    $link = $vars['modulelink'];
    $tab = $_GET['tab'] ?? 'dashboard';
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $token = function_exists('generate_token') ? generate_token('plain') : '';
    $currency = $cdn::defaultCurrency();
    $money = function ($v) use ($cdn, $currency) { return $cdn::formatMoney($v, $currency); };

    // ---------------------------------------------------------------- actions
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['shcdn_admin'])) {
        if (!$token || !hash_equals($token, (string) ($_POST['token'] ?? ''))) {
            echo '<div class="alert alert-danger">Invalid security token. Please try again.</div>';
        } else {
            try {
                switch ($_POST['shcdn_admin']) {
                    case 'sync':
                        list($synced, $errors) = $cdn::syncAllUsage();
                        echo '<div class="alert alert-success">Synced ' . (int) $synced . ' zone(s).</div>';
                        foreach ($errors as $err) {
                            echo '<div class="alert alert-warning">' . $e($err) . '</div>';
                        }
                        break;
                    case 'bill':
                        $month = preg_match('/^\d{4}-\d{2}$/', $_POST['month'] ?? '') ? $_POST['month'] : null;
                        if (!$month || $month >= date('Y-m')) {
                            echo '<div class="alert alert-danger">Choose a completed month.</div>';
                            break;
                        }
                        $s = $cdn::runMonthlyBilling($month);
                        echo '<div class="alert alert-success">Billing for ' . $e($month) . ': ' . (int) $s['invoiced'] . ' invoiced, ' . (int) $s['skipped'] . ' skipped.</div>';
                        foreach ($s['errors'] as $err) {
                            echo '<div class="alert alert-warning">' . $e($err) . '</div>';
                        }
                        break;
                    case 'retry':
                        $id = (int) ($_POST['billing_id'] ?? 0);
                        $bill = Capsule::table('mod_seekahostcdn_billing')->where('id', $id)->where('status', 'failed')->first();
                        $row = $bill ? $cdn::zoneForService($bill->service_id) : null;
                        if (!$bill || !$row) {
                            echo '<div class="alert alert-danger">This billing record cannot be retried (the zone no longer exists).</div>';
                            break;
                        }
                        Capsule::table('mod_seekahostcdn_billing')->where('id', $id)->delete();
                        $res = $cdn::billServiceMonth($row, $bill->billing_month);
                        echo '<div class="alert alert-info">Retry result: ' . $e($res) . '</div>';
                        break;
                }
            } catch (Exception $ex) {
                echo '<div class="alert alert-danger">' . $e($ex->getMessage()) . '</div>';
            }
        }
    }

    $tabs = ['dashboard' => 'Zones & Margin', 'billing' => 'Billing History', 'help' => 'Setup'];
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        echo '<li class="' . ($tab === $key ? 'active' : '') . '"><a href="' . $e($link . '&tab=' . $key) . '">' . $e($label) . '</a></li>';
    }
    echo '</ul>';

    if ($tab === 'billing') {
        seekahostcdn_adminBilling($link, $token, $e, $money);
    } elseif ($tab === 'help') {
        seekahostcdn_adminHelp($cdn, $e, $money);
    } else {
        seekahostcdn_adminDashboard($link, $token, $cdn, $e, $money);
    }
}

function seekahostcdn_adminDashboard($link, $token, $cdn, $e, $money)
{
    $rows = Capsule::table('mod_seekahostcdn_dns_zones as z')
        ->join('tblhosting as h', 'h.id', '=', 'z.service_id')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->select('z.*', 'h.userid', 'h.domainstatus', 'c.firstname', 'c.lastname', 'c.companyname')
        ->orderBy('z.service_id', 'desc')->get();

    // Latest month-to-date snapshot per service for the current month
    $snapshots = [];
    $latest = Capsule::table('mod_seekahostcdn_usage')
        ->where('usage_date', '>=', date('Y-m-01'))
        ->orderBy('usage_date')->get();
    foreach ($latest as $s) {
        $snapshots[$s->service_id] = $s;
    }

    $totBytes = 0;
    $totCost = 0.0;
    $totPrice = 0.0;
    $body = '';
    foreach ($rows as $r) {
        $snap = $snapshots[$r->service_id] ?? null;
        $bytes = $snap ? (int) $snap->bandwidth_bytes : 0;
        $cost = $snap ? (float) $snap->cost : 0.0;
        $price = $cdn::priceForBytes($bytes);
        $margin = $price - $cost;
        $totBytes += $bytes;
        $totCost += $cost;
        $totPrice += $price;
        $client = trim($r->firstname . ' ' . $r->lastname) . ($r->companyname ? ' (' . $r->companyname . ')' : '');
        $status = $r->suspended ? 'Suspended' : $r->domainstatus;
        $body .= '<tr>'
            . '<td><a href="clientsservices.php?userid=' . (int) $r->userid . '&id=' . (int) $r->service_id . '">#' . (int) $r->service_id . '</a></td>'
            . '<td><a href="clientssummary.php?userid=' . (int) $r->userid . '">' . $e($client) . '</a></td>'
            . '<td>' . $e($r->domain) . '</td>'
            . '<td><span class="label label-' . ($status === 'Active' ? 'success' : 'warning') . '">' . $e($status) . '</span> '
            . ($r->nameservers_detected ? '<span class="label label-info">NS pointed</span>' : '<span class="label label-default">NS pending</span>') . '</td>'
            . '<td class="text-right">' . $e($cdn::formatBytes($bytes)) . '</td>'
            . '<td class="text-right">' . $e($money($cost)) . '</td>'
            . '<td class="text-right">' . $e($money($price)) . '</td>'
            . '<td class="text-right" style="color:' . ($margin >= 0 ? '#3c763d' : '#a94442') . '">' . $e($money($margin)) . '</td>'
            . '<td class="text-right">' . ($price > 0 ? round($margin / $price * 100) . '%' : '-') . '</td>'
            . '<td>' . ($snap ? $e(date('j M H:i', strtotime($snap->updated_at ?: $snap->usage_date))) : '<span class="text-muted">never</span>') . '</td>'
            . '</tr>';
    }

    $margin = $totPrice - $totCost;
    echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px">';
    foreach ([
        ['CDN services', count($rows)],
        ['Bandwidth this month', $cdn::formatBytes($totBytes)],
        ['bunny.net cost (MTD)', $money($totCost)],
        ['Our price (MTD)', $money($totPrice)],
        ['Margin (MTD)' . ($totPrice > 0 ? ' · ' . round($margin / $totPrice * 100) . '%' : ''), $money($margin)],
    ] as $tile) {
        echo '<div style="flex:1 1 170px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px">'
            . '<div class="text-muted" style="font-size:12px">' . $e($tile[0]) . '</div>'
            . '<div style="font-size:22px;font-weight:600;white-space:nowrap">' . $e($tile[1]) . '</div></div>';
    }
    echo '</div>';

    echo '<form method="post" action="' . $e($link) . '" style="margin-bottom:12px">'
        . '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="shcdn_admin" value="sync">'
        . '<button class="btn btn-default"><i class="fa fa-refresh"></i> Sync usage from bunny.net now</button>'
        . ' <small class="text-muted">Usage syncs automatically with the daily cron. Price per GB: ' . $e($money($cdn::pricePerGb())) . '. bunny.net costs are in USD.</small>'
        . '</form>';

    echo '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>'
        . '<th>Service</th><th>Client</th><th>Domain</th><th>Status</th><th class="text-right">Bandwidth (MTD)</th>'
        . '<th class="text-right">bunny.net cost</th><th class="text-right">Our price</th><th class="text-right">Margin</th><th class="text-right">Margin %</th><th>Last sync</th>'
        . '</tr></thead><tbody>' . ($body ?: '<tr><td colspan="10" class="text-muted">No CDN services yet.</td></tr>') . '</tbody></table></div>';
}

function seekahostcdn_adminBilling($link, $token, $e, $money)
{
    $lastMonth = date('Y-m', strtotime('first day of last month'));
    echo '<form method="post" action="' . $e($link . '&tab=billing') . '" class="form-inline" style="margin-bottom:15px">'
        . '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="shcdn_admin" value="bill">'
        . '<label>Run usage billing for month&nbsp;</label><input type="month" name="month" class="form-control" value="' . $e($lastMonth) . '" max="' . $e($lastMonth) . '"> '
        . '<button class="btn btn-primary" onclick="return confirm(\'Create usage invoices for this month? Services already billed are skipped.\')">Run now</button>'
        . ' <small class="text-muted">Safe to re-run: each service is billed at most once per month.</small></form>';

    $bills = Capsule::table('mod_seekahostcdn_billing as b')
        ->leftJoin('tblhosting as h', 'h.id', '=', 'b.service_id')
        ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
        ->select('b.*', 'h.domain', 'h.userid as huserid', 'c.firstname', 'c.lastname')
        ->orderBy('b.billing_month', 'desc')->orderBy('b.id', 'desc')->limit(500)->get();

    $byMonth = [];
    foreach ($bills as $b) {
        $byMonth[$b->billing_month][] = $b;
    }
    if (!$byMonth) {
        echo '<p class="text-muted">No billing records yet.</p>';
        return;
    }

    foreach ($byMonth as $month => $list) {
        $sumCost = 0;
        $sumPrice = 0;
        $sumGb = 0;
        $rowsHtml = '';
        foreach ($list as $b) {
            $sumCost += (float) $b->bunny_cost;
            $sumPrice += $b->status === 'invoiced' ? (float) $b->cost : 0;
            $sumGb += (float) $b->bandwidth_gb;
            $uid = (int) ($b->userid ?: $b->huserid);
            $statusLabel = ['invoiced' => 'success', 'below_minimum' => 'default', 'failed' => 'danger', 'pending' => 'warning'][$b->status] ?? 'default';
            $action = '';
            if ($b->status === 'failed') {
                $action = '<form method="post" action="' . $e($link . '&tab=billing') . '" style="display:inline">'
                    . '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="shcdn_admin" value="retry">'
                    . '<input type="hidden" name="billing_id" value="' . (int) $b->id . '"><button class="btn btn-xs btn-default">Retry</button></form>';
            }
            $rowsHtml .= '<tr>'
                . '<td><a href="clientssummary.php?userid=' . $uid . '">' . $e(trim($b->firstname . ' ' . $b->lastname) ?: 'Client #' . $uid) . '</a></td>'
                . '<td>' . $e($b->domain ?: 'service #' . $b->service_id) . '</td>'
                . '<td class="text-right">' . $e(number_format((float) $b->bandwidth_gb, 2)) . ' GB</td>'
                . '<td class="text-right">' . $e($money($b->bunny_cost)) . '</td>'
                . '<td class="text-right">' . $e($money($b->cost)) . '</td>'
                . '<td class="text-right">' . ($b->client_amount > 0 ? $e(number_format((float) $b->client_amount, 2) . ' ' . $b->currency_code) : '-') . '</td>'
                . '<td>' . ($b->invoice_id ? '<a href="invoices.php?action=edit&id=' . (int) $b->invoice_id . '">#' . (int) $b->invoice_id . '</a>' : '-') . '</td>'
                . '<td><span class="label label-' . $statusLabel . '">' . $e(str_replace('_', ' ', $b->status)) . '</span>'
                . ($b->notes ? ' <small class="text-danger">' . $e($b->notes) . '</small>' : '') . ' ' . $action . '</td>'
                . '</tr>';
        }
        $margin = $sumPrice - $sumCost;
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . $e(date('F Y', strtotime($month . '-01'))) . '</strong>'
            . ' &nbsp; ' . $e(number_format($sumGb, 2)) . ' GB &nbsp;|&nbsp; bunny.net cost ' . $e($money($sumCost))
            . ' &nbsp;|&nbsp; invoiced ' . $e($money($sumPrice))
            . ' &nbsp;|&nbsp; margin <span style="color:' . ($margin >= 0 ? '#3c763d' : '#a94442') . '">' . $e($money($margin)) . '</span></div>'
            . '<div class="table-responsive"><table class="table table-condensed" style="margin:0"><thead><tr>'
            . '<th>Client</th><th>Domain</th><th class="text-right">Bandwidth</th><th class="text-right">bunny.net cost</th>'
            . '<th class="text-right">Our price</th><th class="text-right">Client amount</th><th>Invoice</th><th>Status</th>'
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></div></div>';
    }
    echo '<p class="text-muted small">bunny.net cost per month comes from the last daily usage snapshot of that month, so the final day may be slightly under-counted.</p>';
}

function seekahostcdn_adminHelp($cdn, $e, $money)
{
    $settings = $cdn::settings();
    $servers = Capsule::table('tblservers')->where('type', 'seekahostcdn')->count();
    $check = function ($ok, $text) use ($e) {
        return '<li><span class="label label-' . ($ok ? 'success' : 'danger') . '">' . ($ok ? 'OK' : 'TODO') . '</span> ' . $e($text) . '</li>';
    };
    echo '<div class="panel panel-default"><div class="panel-body">'
        . '<h4>Setup checklist</h4><ul class="list-unstyled" style="line-height:2">'
        . $check($servers > 0, 'Add a server: System Settings > Servers, module "SeekaHost CDN (bunny.net)", put your bunny.net account API key in "Access Hash", then Test Connection.')
        . $check(true, 'Create a product with module "SeekaHost CDN (bunny.net)" and set the bandwidth limit on its Module Settings tab.')
        . $check((float) $settings['price_per_gb'] > 0, 'Price per GB is ' . $money($cdn::pricePerGb()) . ' (Configure this addon to change it).')
        . $check($cdn::isOn($settings['enable_billing']), 'Monthly usage invoices are ' . ($cdn::isOn($settings['enable_billing']) ? 'enabled (day ' . (int) $settings['billing_day'] . ')' : 'disabled') . '.')
        . '</ul>'
        . '<h4>How it works</h4><ul>'
        . '<li>On activation the module copies the domain\'s current DNS into bunny.net DNS, creates a pull zone using the current website IP as origin, and points the root and www records at the CDN.</li>'
        . '<li>The client changes nameservers at their registrar. SSL is issued automatically by the daily cron once the nameservers are detected.</li>'
        . '<li>The daily cron syncs usage and bunny.net cost. On the billing day each service gets an invoice for the previous month\'s bandwidth at your price per GB.</li>'
        . '<li>Clients only ever see your price. bunny.net costs are visible to admins only.</li>'
        . '</ul></div></div>';
}
