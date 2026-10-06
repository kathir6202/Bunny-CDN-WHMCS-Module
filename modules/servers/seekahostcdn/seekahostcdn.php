<?php
/**
 * SeekaHost CDN - WHMCS provisioning module for bunny.net (DNS + Pull Zone).
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
            'Description' => 'Optional. Leave blank to use the domain\'s current A record. A client custom field named "Origin Server" overrides this.',
        ],
        'Monthly Bandwidth Limit (GB)' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0',
            'Description' => '0 = unlimited. bunny.net stops serving the zone once the limit is reached for the month.',
        ],
        'Pull Zone Tier' => [
            'Type' => 'dropdown',
            'Options' => 'Standard,Volume',
            'Default' => 'Standard',
            'Description' => 'bunny.net network tier (affects your bunny.net cost, not the client price).',
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
            ->join('tblhosting', 'tblhosting.id', '=', 'mod_seekahostcdn_dns_zones.service_id')
            ->where('tblhosting.server', (int) $params['serverid'])
            ->select('mod_seekahostcdn_dns_zones.*')->get();
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
        return $fn($row, CDN::clientForParams($params));
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function seekahostcdn_importdns(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        $records = CDN::mergeRecords(CDN::discoverLocalDns($row->domain), CDN::discoverViaBunny($bunny, $row->dns_zone_id));
        $res = CDN::importRecords($bunny, $row->dns_zone_id, $records, true);
        return $res['failed'] ? 'Imported ' . $res['added'] . ' record(s). Failed: ' . implode('; ', $res['failed']) : 'success';
    });
}

function seekahostcdn_repointdns(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        CDN::pointToCdn($bunny, $row->dns_zone_id, $row->pull_zone_id, $row->cdn_url);
        CDN::ensureHostname($bunny, $row, '');
        CDN::ensureHostname($bunny, CDN::zoneForService($row->service_id), 'www');
        return 'success';
    });
}

function seekahostcdn_issuessl(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        $res = CDN::issueSsl($row, $bunny);
        if (!$res['detected']) {
            return 'The domain is not using the bunny.net nameservers yet, so SSL cannot be issued.';
        }
        return $res['errors'] ? implode('; ', $res['errors']) : 'success';
    });
}

function seekahostcdn_purgecache(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        $bunny->purgeCache($row->pull_zone_id);
        return 'success';
    });
}

function seekahostcdn_syncusage(array $params)
{
    return seekahostcdn_withZone($params, function ($row, BunnyClient $bunny) {
        CDN::syncUsage($row, $bunny);
        return 'success';
    });
}

/**
 * Admin-only service tab: bunny.net cost vs our price.
 */
function seekahostcdn_AdminServicesTabFields(array $params)
{
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    try {
        CDN::ensureSchema();
        $row = CDN::zoneForService($params['serviceid']);
        if (!$row) {
            return ['CDN' => '<span class="label label-warning">Not provisioned</span>'];
        }
        $bunny = CDN::clientForParams($params);
        $zone = $bunny->getPullZone($row->pull_zone_id);
        $bytes = (int) ($zone['MonthlyBandwidthUsed'] ?? 0);
        $bunnyCost = (float) ($zone['MonthlyCharges'] ?? 0);
        $ourPrice = CDN::priceForBytes($bytes);
        $cur = CDN::defaultCurrency();
        $margin = $ourPrice - $bunnyCost;
        $limit = (int) ($zone['MonthlyBandwidthLimit'] ?? 0);
        $ssl = CDN::decodeList($row->ssl_hostnames);

        return [
            'Bunny Zones' => 'Pull zone <code>' . $e($row->pull_zone_id) . '</code> &nbsp; DNS zone <code>' . $e($row->dns_zone_id) . '</code> &nbsp; CDN host <code>' . $e($row->cdn_url) . '</code>',
            'Origin' => '<code>' . $e($row->origin_url) . '</code>',
            'Nameservers' => $e($row->nameserver_1) . ', ' . $e($row->nameserver_2) . ' &nbsp; '
                . ($row->nameservers_detected ? '<span class="label label-success">Pointed</span>' : '<span class="label label-warning">Not pointed yet</span>'),
            'Hostnames / SSL' => implode('<br>', array_map(function ($h) use ($ssl, $e) {
                return $e($h) . ' ' . (in_array($h, $ssl, true) ? '<span class="label label-success">SSL</span>' : '<span class="label label-default">no SSL yet</span>');
            }, CDN::decodeList($row->hostnames))),
            'Usage This Month' => $e(CDN::formatBytes($bytes)) . ' (' . number_format(CDN::bytesToGb($bytes), 2) . ' GB)'
                . ' &nbsp; Limit: ' . ($row->suspended ? '<span class="label label-danger">Suspended</span>' : ($limit > 0 ? $e(CDN::formatBytes($limit)) : 'Unlimited')),
            'Cost vs Price (MTD)' => 'bunny.net cost: <strong>' . $e(CDN::formatMoney($bunnyCost, $cur)) . '</strong>'
                . ' &nbsp; Our price: <strong>' . $e(CDN::formatMoney($ourPrice, $cur)) . '</strong>'
                . ' &nbsp; Margin: <strong style="color:' . ($margin >= 0 ? '#3c763d' : '#a94442') . '">' . $e(CDN::formatMoney($margin, $cur)) . '</strong>'
                . ' <small class="text-muted">(bunny.net cost is billed in USD)</small>',
        ];
    } catch (Exception $ex) {
        return ['CDN' => '<span class="text-danger">' . $e($ex->getMessage()) . '</span>'];
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

/**
 * Stats for the client area. Our price only, never the bunny.net cost.
 */
function seekahostcdn_clientStats($row, BunnyClient $bunny, array $params, $range)
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
    $currency = CDN::clientCurrency($params['userid']);

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

    $limit = CDN::limitBytes($params);
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
        'charge' => CDN::formatMoney(CDN::toClientCurrency(CDN::priceForBytes($bytes), $currency), $currency),
        'limit' => $limit > 0 ? CDN::formatBytes($limit) : 'Unlimited',
        'limitPercent' => $limit > 0 ? min(100, round($monthBytes / $limit * 100, 1)) : null,
        'chart' => $chart,
    ];
}

function seekahostcdn_handleClientAjax(array $params, $row)
{
    if (!seekahostcdn_csrfValid()) {
        seekahostcdn_json(['success' => false, 'error' => 'Your session has expired. Please reload the page.']);
    }

    $action = (string) $_POST['shcdn_action'];
    $readOnly = in_array($action, ['records', 'stats'], true);
    if (!$readOnly && (($params['status'] ?? '') !== 'Active' || $row->suspended)) {
        seekahostcdn_json(['success' => false, 'error' => 'This service is not active.']);
    }

    try {
        $bunny = CDN::clientForParams($params);
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

            case 'ssl':
                $res = CDN::issueSsl($row, $bunny);
                if (!$res['detected']) {
                    seekahostcdn_json(['success' => false, 'error' => 'Your domain is not using our nameservers yet. SSL is issued automatically once it is.']);
                }
                seekahostcdn_json(['success' => !$res['errors'], 'message' => 'SSL is active for: ' . implode(', ', $res['issued']), 'error' => $res['errors'] ? 'SSL could not be issued yet for some hostnames. Please try again in a few minutes.' : '']);

            case 'stats':
                seekahostcdn_json(seekahostcdn_clientStats($row, $bunny, $params, (string) ($_POST['range'] ?? 'month')));
        }
        seekahostcdn_json(['success' => false, 'error' => 'Unknown action.']);
    } catch (InvalidArgumentException $e) {
        seekahostcdn_json(['success' => false, 'error' => $e->getMessage()]);
    } catch (Exception $e) {
        logModuleCall('seekahostcdn', 'ClientArea:' . $action, $_POST, $e->getMessage());
        seekahostcdn_json(['success' => false, 'error' => 'The request could not be completed: ' . $e->getMessage()]);
    }
}

function seekahostcdn_ClientArea(array $params)
{
    try {
        CDN::ensureSchema();
        $row = CDN::zoneForService($params['serviceid']);
    } catch (Exception $e) {
        $row = null;
    }

    if (!$row) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['shcdn_action'])) {
            seekahostcdn_json(['success' => false, 'error' => 'Your CDN is not set up yet.']);
        }
        return [
            'templatefile' => 'templates/client/pending',
            'vars' => ['status' => $params['status'] ?? ''],
        ];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['shcdn_action'])) {
        seekahostcdn_handleClientAjax($params, $row);
    }

    $records = [];
    $loadError = '';
    $detected = (bool) $row->nameservers_detected;
    $ns1 = $row->nameserver_1;
    $ns2 = $row->nameserver_2;
    try {
        $bunny = CDN::clientForParams($params);
        $zone = $bunny->getDnsZone($row->dns_zone_id);
        $records = CDN::presentRecords(is_array($zone['Records'] ?? null) ? $zone['Records'] : []);
        $detected = !empty($zone['NameserversDetected']);
        $ns1 = $zone['Nameserver1'] ?? $ns1;
        $ns2 = $zone['Nameserver2'] ?? $ns2;
        if ($detected != $row->nameservers_detected || $ns1 !== $row->nameserver_1 || $ns2 !== $row->nameserver_2) {
            Capsule::table('mod_seekahostcdn_dns_zones')->where('service_id', $row->service_id)
                ->update(['nameservers_detected' => $detected ? 1 : 0, 'nameserver_1' => $ns1, 'nameserver_2' => $ns2]);
        }
    } catch (Exception $e) {
        $loadError = 'DNS records could not be loaded right now. Please try again shortly.';
    }

    $currency = CDN::clientCurrency($params['userid']);
    $hostnames = CDN::decodeList($row->hostnames);
    $ssl = CDN::decodeList($row->ssl_hostnames);

    $config = [
        'serviceId' => (int) $params['serviceid'],
        'endpoint' => 'clientarea.php?action=productdetails&id=' . (int) $params['serviceid'],
        'token' => function_exists('generate_token') ? generate_token('plain') : '',
        'domain' => $row->domain,
        'records' => $records,
        'active' => ($params['status'] ?? '') === 'Active' && !$row->suspended,
        'typeNames' => BunnyClient::$recordTypeNames,
    ];

    return [
        'templatefile' => 'templates/client/overview',
        'vars' => [
            'shcdnDomain' => $row->domain,
            'shcdnNs1' => $ns1,
            'shcdnNs2' => $ns2,
            'shcdnDetected' => $detected,
            'shcdnCdnHost' => $row->cdn_url,
            'shcdnSuspended' => (bool) $row->suspended || ($params['status'] ?? '') !== 'Active',
            'shcdnPricePerGb' => CDN::formatMoney(CDN::toClientCurrency(CDN::pricePerGb(), $currency), $currency),
            'shcdnLimit' => CDN::limitBytes($params) > 0 ? CDN::formatBytes(CDN::limitBytes($params)) : 'Unlimited',
            'shcdnHostnames' => array_map(function ($h) use ($ssl) {
                return ['host' => $h, 'ssl' => in_array($h, $ssl, true)];
            }, $hostnames),
            'shcdnLoadError' => $loadError,
            'shcdnConfig' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ],
    ];
}
