<?php
/**
 * SeekaHost CDN - WHMCS provisioning module for bunny.net.
 *
 * Works as a standalone product, as a product addon (sold on top of hosting)
 * and, through the addon's hooks, as a CDN included free with hosting.
 *
 * Install to: modules/servers/seekahostcdn/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Module.php';

use SeekahostCDN\BunnyClient;
use SeekahostCDN\Module as CDN;
use WHMCS\Database\Capsule;

function seekahostcdn_MetaData()
{
    return [
        'DisplayName' => 'SeekaHost CDN (bunny.net)',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function seekahostcdn_ConfigOptions()
{
    return [
        'Origin URL' => [
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional. Blank = the domain\'s current website IP. A custom field named "Origin Server" overrides this.',
        ],
        'Monthly Bandwidth Limit (GB)' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0',
            'Description' => '0 = unlimited. A configurable option named "Bandwidth" (e.g. 100 GB / 1 TB) overrides this.',
        ],
        'Pull Zone Tier' => [
            'Type' => 'dropdown',
            'Options' => 'Standard,Volume',
            'Default' => 'Standard',
            'Description' => 'bunny.net network tier (affects your bunny.net cost, not the client price).',
        ],
        'Setup Mode' => [
            'Type' => 'dropdown',
            'Options' => 'Nameservers (full DNS),CNAME only',
            'Default' => 'Nameservers (full DNS)',
            'Description' => 'Nameservers: we host the DNS and copy existing records. CNAME: the client keeps their DNS and adds one CNAME.',
        ],
        'Storage Zone' => [
            'Type' => 'dropdown',
            'Options' => 'None,Storage zone as origin',
            'Default' => 'None',
            'Description' => 'Create a bunny.net storage zone (FTP/API upload) and serve files from it.',
        ],
        'Storage Region' => [
            'Type' => 'dropdown',
            'Options' => 'DE (Falkenstein),UK (London),SE (Stockholm),NY (New York),LA (Los Angeles),SG (Singapore),SYD (Sydney),BR (Sao Paulo),JH (Johannesburg)',
            'Default' => 'DE (Falkenstein)',
            'Description' => 'Main storage region (only used with a storage zone).',
        ],
    ];
}

function seekahostcdn_TestConnection(array $params)
{
    try {
        CDN::clientForParams($params)->listPullZones();
        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function seekahostcdn_CreateAccount(array $params)
{
    try {
        $result = CDN::provision($params);
        return $result === 'already provisioned' ? 'success' : $result;
    } catch (Exception $e) {
        logModuleCall('seekahostcdn', __FUNCTION__, $params['domain'] ?? '', $e->getMessage());
        return $e->getMessage();
    }
}

function seekahostcdn_SuspendAccount(array $params)
{
    try {
        return CDN::suspend($params);
    } catch (Exception $e) {
        logModuleCall('seekahostcdn', __FUNCTION__, $params['serviceid'], $e->getMessage());
        return $e->getMessage();
    }
}

function seekahostcdn_UnsuspendAccount(array $params)
{
    try {
        return CDN::unsuspend($params);
    } catch (Exception $e) {
        logModuleCall('seekahostcdn', __FUNCTION__, $params['serviceid'], $e->getMessage());
        return $e->getMessage();
    }
}

function seekahostcdn_TerminateAccount(array $params)
{
    try {
        return CDN::terminate($params);
    } catch (Exception $e) {
        logModuleCall('seekahostcdn', __FUNCTION__, $params['serviceid'], $e->getMessage());
        return $e->getMessage();
    }
}

function seekahostcdn_ChangePackage(array $params)
{
    try {
        return CDN::changePackage($params);
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

/**
 * Called by the WHMCS daily cron per server ("Update Usage Statistics").
 */
function seekahostcdn_UsageUpdate(array $params)
{
    try {
        CDN::ensureSchema();
        $bunny = CDN::clientForParams($params);
        $rows = Capsule::table('mod_seekahostcdn_dns_zones')
            ->where(function ($q) use ($params) {
                $q->where('server_id', (int) $params['serverid'])
                    ->orWhereIn('service_id', function ($sub) use ($params) {
                        $sub->select('id')->from('tblhosting')->where('server', (int) $params['serverid']);
                    });
            })->get();
        foreach ($rows as $row) {
            try {
                CDN::syncUsage($row, $bunny);
            } catch (Exception $e) {
                logActivity("SeekaHost CDN usage sync failed for service #{$row->service_id}: " . $e->getMessage());
            }
        }
        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// ======================================================================= Admin

function seekahostcdn_AdminCustomButtonArray()
{
    return [
        'Import Existing DNS' => 'importdns',
        'Point Domain to CDN' => 'repointdns',
        'Issue / Retry SSL' => 'issuessl',
        'Purge Cache' => 'purgecache',
        'Sync Usage Now' => 'syncusage',
    ];
}

function seekahostcdn_withZone(array $params, callable $fn)
{
    try {
        $row = CDN::zoneForService($params['serviceid']);
        if (!$row) {
            return 'This service has not been provisioned yet. Run Create first.';
        }
        return $fn($row, CDN::clientForRow($row));
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function seekahostcdn_importdns(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        return seekahostcdn_adminAction($row, $bunny, 'importdns');
    });
}

function seekahostcdn_repointdns(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        return seekahostcdn_adminAction($row, $bunny, 'repointdns');
    });
}

function seekahostcdn_issuessl(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        return seekahostcdn_adminAction($row, $bunny, 'issuessl');
    });
}

function seekahostcdn_purgecache(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        return seekahostcdn_adminAction($row, $bunny, 'purgecache');
    });
}

function seekahostcdn_syncusage(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        return seekahostcdn_adminAction($row, $bunny, 'syncusage');
    });
}

/** Admin actions shared by the module buttons and the service tab (all selling modes). */
function seekahostcdn_adminAction($row, BunnyClient $bunny, $action)
{
    $dnsOnly = in_array($action, ['importdns', 'repointdns'], true);
    if ($dnsOnly && ($row->mode ?? 'dns') !== 'dns') {
        return 'This CDN uses CNAME mode, so its DNS is managed by the client\'s own DNS provider.';
    }
    switch ($action) {
        case 'importdns':
            $records = CDN::mergeRecords(CDN::discoverLocalDns($row->domain), CDN::discoverViaBunny($bunny, $row->dns_zone_id));
            $res = CDN::importRecords($bunny, $row->dns_zone_id, $records, true);
            return $res['failed'] ? 'Imported ' . $res['added'] . ' record(s). Failed: ' . implode('; ', $res['failed']) : 'success';
        case 'repointdns':
            CDN::pointToCdn($bunny, $row->dns_zone_id, $row->pull_zone_id, $row->cdn_url);
            CDN::ensureHostname($bunny, $row, '');
            CDN::ensureHostname($bunny, CDN::zoneForService($row->service_id), 'www');
            return 'success';
        case 'issuessl':
            $res = CDN::issueSsl($row, $bunny);
            if (!$res['detected']) {
                return ($row->mode ?? 'dns') === 'cname'
                    ? 'The CNAME record for ' . (CDN::decodeList($row->hostnames)[0] ?? $row->domain) . ' does not point to ' . $row->cdn_url . ' yet.'
                    : 'The domain is not using the bunny.net nameservers yet, so SSL cannot be issued.';
            }
            return $res['errors'] ? implode('; ', $res['errors']) : 'success';
        case 'purgecache':
            $bunny->purgeCache($row->pull_zone_id);
            return 'success';
        case 'syncusage':
            CDN::syncUsage($row, $bunny);
            return 'success';
    }
    return 'Unknown action';
}

/**
 * Admin-only service fields: zones, bunny.net cost vs our price, hostname
 * management and quick actions. Also used by the addon hook for CDNs that are
 * an addon on, or included with, a hosting service.
 */
function seekahostcdn_adminFields($row)
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    try {
        $bunny = CDN::clientForRow($row);
        $zone = $bunny->getPullZone($row->pull_zone_id);
        $bytes = (int) ($zone['MonthlyBandwidthUsed'] ?? 0);
        $bunnyCost = (float) ($zone['MonthlyCharges'] ?? 0);
        $billable = CDN::isBillable($row);
        $ourPrice = $billable ? CDN::priceForBytes($bytes) : 0;
        $cur = CDN::defaultCurrency();
        $margin = $ourPrice - $bunnyCost;
        $limit = (int) $row->limit_bytes;
        $ssl = CDN::decodeList($row->ssl_hostnames);
        $cname = ($row->mode ?? 'dns') === 'cname';
        $sourceLabel = ['product' => 'CDN product', 'addon' => 'Paid addon #' . (int) $row->addon_id, 'included' => 'Included with hosting'][$row->source ?? 'product'] ?? $row->source;

        $hostRows = '';
        foreach (CDN::decodeList($row->hostnames) as $h) {
            $hostRows .= '<label style="font-weight:normal;display:block;margin:2px 0">'
                . '<input type="checkbox" name="shcdn_remove_host[]" value="' . $e($h) . '"> ' . $e($h) . ' '
                . (in_array($h, $ssl, true) ? '<span class="label label-success">SSL</span>' : '<span class="label label-default">no SSL yet</span>')
                . '</label>';
        }

        $fields = [
            'CDN' => $e($sourceLabel) . ' &middot; ' . ($cname ? 'CNAME mode' : 'Nameserver mode')
                . ' &middot; ' . ($row->nameservers_detected ? '<span class="label label-success">' . ($cname ? 'CNAME detected' : 'NS pointed') . '</span>' : '<span class="label label-warning">' . ($cname ? 'CNAME not detected' : 'NS not pointed') . '</span>')
                . ($row->suspended ? ' <span class="label label-danger">CDN suspended' . ($row->overage_month ? ' (bandwidth)' : '') . '</span>' : ''),
            'Bunny Zones' => 'Pull zone <code>' . $e($row->pull_zone_id) . '</code>'
                . ($row->dns_zone_id ? ' &nbsp; DNS zone <code>' . $e($row->dns_zone_id) . '</code>' : '')
                . ($row->storage_zone_id ? ' &nbsp; Storage zone <code>' . $e($row->storage_zone_name) . '</code> (' . $e($row->storage_region) . ')' : '')
                . ' &nbsp; CDN host <code>' . $e($row->cdn_url) . '</code>',
            'Origin' => '<code>' . $e($row->origin_url) . '</code>',
        ];
        if (!$cname) {
            $fields['Nameservers'] = $e($row->nameserver_1) . ', ' . $e($row->nameserver_2);
        }
        $fields['Hostnames / SSL'] = ($hostRows ?: '<span class="text-muted">none</span>')
            . '<div style="margin-top:6px"><input type="text" name="shcdn_add_host" class="form-control input-sm" style="display:inline-block;width:260px" placeholder="Add hostname, e.g. cdn.example.com">'
            . ' <small class="text-muted">Tick to remove, then Save Changes.</small></div>';
        $fields['Usage This Month'] = $e(CDN::formatBytes($bytes)) . ' &nbsp; Limit: ' . ($limit > 0 ? $e(CDN::formatBytes($limit)) . ' (' . round($bytes / $limit * 100) . '%)' : 'Unlimited');
        $fields['Cost vs Price (MTD)'] = 'bunny.net cost: <strong>' . $e(CDN::formatMoney($bunnyCost, $cur)) . '</strong>'
            . ' &nbsp; Our price: <strong>' . ($billable ? $e(CDN::formatMoney($ourPrice, $cur)) : 'not billed (included)') . '</strong>'
            . ' &nbsp; Margin: <strong style="color:' . ($margin >= 0 ? '#3c763d' : '#a94442') . '">' . $e(CDN::formatMoney($margin, $cur)) . '</strong>'
            . ' <small class="text-muted">(bunny.net cost is in USD)</small>';
        $fields['CDN Action'] = '<select name="shcdn_admin_action" class="form-control input-sm" style="width:260px;display:inline-block">'
            . '<option value="">- none -</option><option value="issuessl">Issue / retry SSL</option><option value="purgecache">Purge whole cache</option>'
            . '<option value="syncusage">Sync usage now</option>'
            . ($cname ? '' : '<option value="importdns">Re-import existing DNS</option><option value="repointdns">Point @ and www to CDN</option>')
            . '</select> <small class="text-muted">Runs when you click Save Changes.</small>';
        return $fields;
    } catch (Exception $ex) {
        return ['CDN' => '<span class="text-danger">' . $e($ex->getMessage()) . '</span>'];
    }
}

/** Handle the hostname and action inputs from seekahostcdn_adminFields(). */
function seekahostcdn_adminFieldsSave($row)
{
    $bunny = CDN::clientForRow($row);
    $messages = [];
    foreach ((array) ($_POST['shcdn_remove_host'] ?? []) as $host) {
        CDN::removeHostnameFromRow(CDN::zoneForService($row->service_id), $bunny, (string) $host);
        $messages[] = "removed hostname {$host}";
    }
    $add = trim((string) ($_POST['shcdn_add_host'] ?? ''));
    if ($add !== '') {
        CDN::addHostnameToRow(CDN::zoneForService($row->service_id), $bunny, $add);
        $messages[] = "added hostname {$add}";
        try {
            CDN::issueSsl(CDN::zoneForService($row->service_id), $bunny);
        } catch (Exception $e) {
            // retried by cron
        }
    }
    $action = (string) ($_POST['shcdn_admin_action'] ?? '');
    if ($action !== '') {
        $messages[] = $action . ': ' . seekahostcdn_adminAction(CDN::zoneForService($row->service_id), $bunny, $action);
    }
    if ($messages) {
        logActivity('SeekaHost CDN service #' . $row->service_id . ': ' . implode(', ', $messages));
    }
}

function seekahostcdn_AdminServicesTabFields(array $params)
{
    try {
        CDN::ensureSchema();
        $row = CDN::zoneForService($params['serviceid']);
    } catch (Exception $e) {
        $row = null;
    }
    return $row ? seekahostcdn_adminFields($row) : ['CDN' => '<span class="label label-warning">Not provisioned</span>'];
}

function seekahostcdn_AdminServicesTabFieldsSave(array $params)
{
    $row = CDN::zoneForService($params['serviceid']);
    if ($row) {
        try {
            seekahostcdn_adminFieldsSave($row);
        } catch (Exception $e) {
            logActivity('SeekaHost CDN service #' . $row->service_id . ' admin change failed: ' . $e->getMessage());
        }
    }
}

// ====================================================================== Client

function seekahostcdn_json(array $data)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function seekahostcdn_csrfValid()
{
    $sent = (string) ($_POST['token'] ?? '');
    if ($sent === '' || !function_exists('generate_token')) {
        return false;
    }
    return hash_equals((string) generate_token('plain'), $sent);
}

/** Client-area context for a CDN row, independent of how it was sold. */
function seekahostcdn_clientContext($row, $userId, $limitBytes = null)
{
    $status = CDN::serviceStatus($row);
    return [
        'status' => $status,
        'userid' => (int) $userId,
        'active' => $status === 'Active' && !$row->suspended,
        'limit' => $limitBytes !== null ? (int) $limitBytes : (int) $row->limit_bytes,
        'billable' => CDN::isBillable($row),
    ];
}

/**
 * Stats for the client area. Our price only, never the bunny.net cost.
 */
function seekahostcdn_clientStats($row, BunnyClient $bunny, array $ctx, $range)
{
    $today = date('Y-m-d');
    switch ($range) {
        case '7d':
            $from = date('Y-m-d', strtotime('-6 days'));
            $to = $today;
            break;
        case '30d':
            $from = date('Y-m-d', strtotime('-29 days'));
            $to = $today;
            break;
        case 'last_month':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to = date('Y-m-t', strtotime('first day of last month'));
            break;
        default:
            $range = 'month';
            $from = date('Y-m-01');
            $to = $today;
    }
    $stats = $bunny->getStatistics($row->pull_zone_id, $from, $to);
    $bytes = (int) ($stats['TotalBandwidthUsed'] ?? 0);
    $currency = CDN::clientCurrency($ctx['userid']);

    $chart = [];
    foreach ((array) ($stats['BandwidthUsedChart'] ?? []) as $date => $value) {
        $chart[] = ['date' => substr((string) $date, 0, 10), 'bytes' => (int) $value];
    }

    // Show the whole month so the chart fills up as the month goes on
    $axisTo = $to;
    if ($range === 'month') {
        $axisTo = date('Y-m-t');
        for ($d = strtotime($to . ' +1 day'); $d <= strtotime($axisTo); $d += 86400) {
            $chart[] = ['date' => date('Y-m-d', $d), 'bytes' => null];
        }
    }

    $limit = $ctx['limit'];
    $monthBytes = $bytes;
    if ($range !== 'month' && $limit > 0) {
        $monthStats = $bunny->getStatistics($row->pull_zone_id, date('Y-m-01'), $today);
        $monthBytes = (int) ($monthStats['TotalBandwidthUsed'] ?? 0);
    }

    return [
        'success' => true,
        'range' => $range,
        'from' => $from,
        'to' => $axisTo,
        'bandwidth' => CDN::formatBytes($bytes),
        'bandwidthGb' => number_format(CDN::bytesToGb($bytes), 2),
        'requests' => number_format((int) ($stats['TotalRequestsServed'] ?? 0)),
        'cacheHitRate' => round((float) ($stats['CacheHitRate'] ?? 0), 1),
        'charge' => $ctx['billable'] ? CDN::formatMoney(CDN::toClientCurrency(CDN::priceForBytes($bytes), $currency), $currency) : 'Included',
        'limit' => $limit > 0 ? CDN::formatBytes($limit) : 'Unlimited',
        'limitPercent' => $limit > 0 ? min(100, round($monthBytes / $limit * 100, 1)) : null,
        'chart' => $chart,
    ];
}

function seekahostcdn_handleClientAjax($row, array $ctx)
{
    if (!seekahostcdn_csrfValid()) {
        seekahostcdn_json(['success' => false, 'error' => 'Your session has expired. Please reload the page.']);
    }

    $action = (string) $_POST['shcdn_action'];
    $readOnly = in_array($action, ['records', 'stats', 'settings'], true);
    if (!$readOnly && !$ctx['active']) {
        seekahostcdn_json(['success' => false, 'error' => 'This service is not active.']);
    }
    $dnsAction = in_array($action, ['records', 'add', 'update', 'delete', 'import'], true);
    if ($dnsAction && ($row->mode ?? 'dns') !== 'dns') {
        seekahostcdn_json(['success' => false, 'error' => 'DNS for this domain is managed at your own DNS provider.']);
    }

    try {
        $bunny = CDN::clientForRow($row);
        switch ($action) {
            case 'records':
                seekahostcdn_json(['success' => true, 'records' => CDN::presentRecords($bunny->getDnsRecords($row->dns_zone_id))]);

            case 'add':
            case 'update':
                $record = CDN::buildRecord($_POST, $row->domain, $row->pull_zone_id, $row->cdn_url);
                if ($action === 'update') {
                    $recordId = (int) ($_POST['record_id'] ?? 0);
                    $owned = false;
                    foreach ($bunny->getDnsRecords($row->dns_zone_id) as $r) {
                        if ((int) $r['Id'] === $recordId) {
                            $owned = in_array((int) $r['Type'], CDN::$clientRecordTypes, true);
                        }
                    }
                    if (!$owned) {
                        throw new InvalidArgumentException('Record not found.');
                    }
                    $bunny->updateDnsRecord($row->dns_zone_id, $recordId, $record);
                } else {
                    $bunny->addDnsRecord($row->dns_zone_id, $record);
                }
                if ($record['Type'] === BunnyClient::REC_PULLZONE) {
                    CDN::ensureHostname($bunny, $row, $record['Name']);
                }
                seekahostcdn_json(['success' => true, 'records' => CDN::presentRecords($bunny->getDnsRecords($row->dns_zone_id))]);

            case 'delete':
                $bunny->deleteDnsRecord($row->dns_zone_id, (int) ($_POST['record_id'] ?? 0));
                seekahostcdn_json(['success' => true, 'records' => CDN::presentRecords($bunny->getDnsRecords($row->dns_zone_id))]);

            case 'import':
                $records = CDN::mergeRecords(CDN::discoverLocalDns($row->domain), CDN::discoverViaBunny($bunny, $row->dns_zone_id));
                $res = CDN::importRecords($bunny, $row->dns_zone_id, $records, true);
                seekahostcdn_json([
                    'success' => true,
                    'message' => $res['added'] . ' record(s) imported.' . ($res['failed'] ? ' ' . count($res['failed']) . ' could not be imported.' : ''),
                    'records' => CDN::presentRecords($bunny->getDnsRecords($row->dns_zone_id)),
                ]);

            case 'purge':
                $bunny->purgeCache($row->pull_zone_id);
                seekahostcdn_json(['success' => true, 'message' => 'Cache purged. Fresh content will be fetched from your origin.']);

            case 'purge_urls':
                $n = CDN::purgeUrls($row, $bunny, (string) ($_POST['urls'] ?? ''));
                seekahostcdn_json(['success' => true, 'message' => $n . ' URL(s) purged.']);

            case 'ssl':
                $res = CDN::issueSsl($row, $bunny);
                if (!$res['detected']) {
                    seekahostcdn_json(['success' => false, 'error' => ($row->mode ?? 'dns') === 'cname'
                        ? 'We cannot see your CNAME record yet. DNS changes can take a while; SSL is issued automatically once it is detected.'
                        : 'Your domain is not using our nameservers yet. SSL is issued automatically once it is.']);
                }
                seekahostcdn_json(['success' => !$res['errors'], 'message' => 'SSL is active for: ' . implode(', ', $res['issued']),
                    'error' => $res['errors'] ? 'SSL could not be issued yet for some hostnames. Please try again in a few minutes.' : '']);

            case 'stats':
                seekahostcdn_json(seekahostcdn_clientStats($row, $bunny, $ctx, (string) ($_POST['range'] ?? 'month')));

            case 'settings':
                seekahostcdn_json(['success' => true, 'settings' => CDN::cdnSettings($row, $bunny)]);

            case 'settings_save':
                CDN::saveCdnSettings($row, $bunny, $_POST);
                seekahostcdn_json(['success' => true, 'message' => 'CDN settings saved. Changes apply within a few minutes.',
                    'settings' => CDN::cdnSettings(CDN::zoneForService($row->service_id), $bunny)]);

            case 'storage':
                if (empty($row->storage_zone_id)) {
                    throw new InvalidArgumentException('This CDN has no storage zone.');
                }
                seekahostcdn_json(['success' => true, 'storage' => CDN::storageDetails($row, $bunny)]);

            case 'storage_reset':
                if (empty($row->storage_zone_id)) {
                    throw new InvalidArgumentException('This CDN has no storage zone.');
                }
                $bunny->resetStoragePassword($row->storage_zone_id);
                seekahostcdn_json(['success' => true, 'message' => 'A new storage password has been generated.', 'storage' => CDN::storageDetails($row, $bunny)]);
        }
        seekahostcdn_json(['success' => false, 'error' => 'Unknown action.']);
    } catch (InvalidArgumentException $e) {
        seekahostcdn_json(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        logModuleCall('seekahostcdn', 'ClientArea:' . $action, array_diff_key($_POST, ['token' => 1]), $e->getMessage());
        seekahostcdn_json(['success' => false, 'error' => 'The request could not be completed: ' . $e->getMessage()]);
    }
}

/** Template variables for the client CDN page. */
function seekahostcdn_clientVars($row, array $ctx, $serviceId)
{
    $records = [];
    $loadError = '';
    $cname = ($row->mode ?? 'dns') === 'cname';
    $ns1 = $row->nameserver_1;
    $ns2 = $row->nameserver_2;
    $zone = [];
    try {
        $bunny = CDN::clientForRow($row);
        if (!$cname) {
            $zone = $bunny->getDnsZone($row->dns_zone_id);
            $records = CDN::presentRecords(is_array($zone['Records'] ?? null) ? $zone['Records'] : []);
            $ns1 = $zone['Nameserver1'] ?? $ns1;
            $ns2 = $zone['Nameserver2'] ?? $ns2;
        }
    } catch (Exception $e) {
        $loadError = 'DNS records could not be loaded right now. Please try again shortly.';
    }
    $detected = CDN::isConnected($row, $zone);
    if ($detected != $row->nameservers_detected || $ns1 !== $row->nameserver_1 || $ns2 !== $row->nameserver_2) {
        CDN::touchZone($row->service_id, ['nameservers_detected' => $detected ? 1 : 0, 'nameserver_1' => $ns1, 'nameserver_2' => $ns2]);
    }

    $currency = CDN::clientCurrency($ctx['userid']);
    $hostnames = CDN::decodeList($row->hostnames);
    $ssl = CDN::decodeList($row->ssl_hostnames);
    $cnameRows = [];
    if ($cname) {
        foreach ($hostnames as $h) {
            $cnameRows[] = ['host' => $h, 'target' => $row->cdn_url, 'ok' => CDN::hostPointsToCdn($h, $row->cdn_url), 'apex' => CDN::isZoneApex($h)];
        }
    }

    $config = [
        'serviceId' => (int) $serviceId,
        'endpoint' => 'clientarea.php?action=productdetails&id=' . (int) $serviceId,
        'token' => function_exists('generate_token') ? generate_token('plain') : '',
        'domain' => $row->domain,
        'records' => $records,
        'active' => $ctx['active'],
        'mode' => $cname ? 'cname' : 'dns',
        'hasStorage' => !empty($row->storage_zone_id),
        'hostnames' => $hostnames,
        'cacheChoices' => CDN::$cacheChoices,
        'typeNames' => BunnyClient::$recordTypeNames,
    ];

    return [
        'shcdnDomain' => $row->domain,
        'shcdnMode' => $cname ? 'cname' : 'dns',
        'shcdnNs1' => $ns1,
        'shcdnNs2' => $ns2,
        'shcdnCname' => $cnameRows,
        'shcdnDetected' => $detected,
        'shcdnCdnHost' => $row->cdn_url,
        'shcdnHasStorage' => !empty($row->storage_zone_id),
        'shcdnSuspended' => !$ctx['active'],
        'shcdnOverage' => !empty($row->overage_month),
        'shcdnBillable' => $ctx['billable'],
        'shcdnPricePerGb' => CDN::formatMoney(CDN::toClientCurrency(CDN::pricePerGb(), $currency), $currency),
        'shcdnLimit' => $ctx['limit'] > 0 ? CDN::formatBytes($ctx['limit']) : 'Unlimited',
        'shcdnHostnames' => array_map(function ($h) use ($ssl) {
            return ['host' => $h, 'ssl' => in_array($h, $ssl, true)];
        }, $hostnames),
        'shcdnLoadError' => $loadError,
        'shcdnConfig' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ];
}

function seekahostcdn_ClientArea(array $params)
{
    try {
        CDN::ensureSchema();
        $row = CDN::zoneForService($params['serviceid']);
    } catch (Exception $e) {
        $row = null;
    }
    $isAjax = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['shcdn_action']);

    if (!$row) {
        if ($isAjax) {
            seekahostcdn_json(['success' => false, 'error' => 'Your CDN is not set up yet.']);
        }
        return [
            'templatefile' => 'templates/client/pending',
            'vars' => ['status' => $params['status'] ?? ''],
        ];
    }

    $limit = (int) $row->limit_bytes ?: CDN::limitBytes($params);
    $ctx = seekahostcdn_clientContext($row, $params['userid'], $limit);
    if ($isAjax) {
        seekahostcdn_handleClientAjax($row, $ctx);
    }

    return [
        'templatefile' => 'templates/client/overview',
        'vars' => seekahostcdn_clientVars($row, $ctx, $params['serviceid']),
    ];
}
