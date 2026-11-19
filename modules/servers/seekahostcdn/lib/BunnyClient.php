<?php
/**
 * Minimal bunny.net API client used by the SeekaHost CDN module.
 *
 * Every method throws SeekahostCDN\BunnyException on transport or HTTP errors,
 * so callers never have to guess whether an array is a result or an error.
 */

namespace SeekahostCDN;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class BunnyException extends \Exception
{
    public $httpCode;

    public function __construct($message, $httpCode = 0)
    {
        parent::__construct($message);
        $this->httpCode = (int) $httpCode;
    }
}

class BunnyClient
{
    public static $baseUrl = 'https://api.bunny.net';
    public static $logBaseUrl = 'https://logging.bunnycdn.com';

    // Bunny DNS record types
    const REC_A = 0;
    const REC_AAAA = 1;
    const REC_CNAME = 2;
    const REC_TXT = 3;
    const REC_MX = 4;
    const REC_REDIRECT = 5;
    const REC_FLATTEN = 6;
    const REC_PULLZONE = 7;
    const REC_SRV = 8;
    const REC_CAA = 9;
    const REC_PTR = 10;
    const REC_SCRIPT = 11;
    const REC_NS = 12;

    public static $recordTypeNames = [
        0 => 'A', 1 => 'AAAA', 2 => 'CNAME', 3 => 'TXT', 4 => 'MX', 5 => 'Redirect',
        6 => 'Flatten', 7 => 'CDN', 8 => 'SRV', 9 => 'CAA', 10 => 'PTR', 11 => 'Script', 12 => 'NS',
    ];

    private $apiKey;

    public function __construct($apiKey)
    {
        $this->apiKey = trim((string) $apiKey);
        if ($this->apiKey === '') {
            throw new BunnyException('Bunny API key is not configured on the server.');
        }
    }

    // ------------------------------------------------------------------ DNS zones

    public function createDnsZone($domain)
    {
        return $this->request('POST', '/dnszone', ['Domain' => $domain]);
    }

    public function getDnsZone($zoneId)
    {
        return $this->request('GET', '/dnszone/' . (int) $zoneId);
    }

    /** Find a zone in this account by exact domain name, or null. */
    public function findDnsZone($domain)
    {
        $res = $this->request('GET', '/dnszone', null, ['page' => 1, 'perPage' => 100, 'search' => $domain]);
        foreach (($res['Items'] ?? []) as $zone) {
            if (strcasecmp($zone['Domain'] ?? '', $domain) === 0) {
                return $zone;
            }
        }
        return null;
    }

    public function deleteDnsZone($zoneId)
    {
        return $this->request('DELETE', '/dnszone/' . (int) $zoneId);
    }

    public function getDnsRecords($zoneId)
    {
        $zone = $this->getDnsZone($zoneId);
        return is_array($zone['Records'] ?? null) ? $zone['Records'] : [];
    }

    public function addDnsRecord($zoneId, array $record)
    {
        return $this->request('PUT', '/dnszone/' . (int) $zoneId . '/records', $record);
    }

    public function updateDnsRecord($zoneId, $recordId, array $record)
    {
        return $this->request('POST', '/dnszone/' . (int) $zoneId . '/records/' . (int) $recordId, $record);
    }

    public function deleteDnsRecord($zoneId, $recordId)
    {
        return $this->request('DELETE', '/dnszone/' . (int) $zoneId . '/records/' . (int) $recordId);
    }

    /** Ask bunny to scan the domain's current public DNS. */
    public function startDnsScan($zoneId)
    {
        return $this->request('POST', '/dnszone/records/scan', ['ZoneId' => (int) $zoneId]);
    }

    public function getDnsScanResult($zoneId)
    {
        return $this->request('GET', '/dnszone/' . (int) $zoneId . '/records/scan');
    }

    // ----------------------------------------------------------------- Pull zones

    public function createPullZone(array $data)
    {
        return $this->request('POST', '/pullzone', $data);
    }

    public function getPullZone($pullZoneId)
    {
        return $this->request('GET', '/pullzone/' . (int) $pullZoneId);
    }

    public function updatePullZone($pullZoneId, array $data)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId, $data);
    }

    public function deletePullZone($pullZoneId)
    {
        return $this->request('DELETE', '/pullzone/' . (int) $pullZoneId);
    }

    public function addHostname($pullZoneId, $hostname)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId . '/addHostname', ['Hostname' => $hostname]);
    }

    public function removeHostname($pullZoneId, $hostname)
    {
        return $this->request('DELETE', '/pullzone/' . (int) $pullZoneId . '/removeHostname', ['Hostname' => $hostname]);
    }

    public function loadFreeCertificate($hostname)
    {
        return $this->request('GET', '/pullzone/loadFreeCertificate', null, ['hostname' => $hostname]);
    }

    public function setForceSsl($pullZoneId, $hostname, $force = true)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId . '/setForceSSL', [
            'Hostname' => $hostname,
            'ForceSSL' => (bool) $force,
        ]);
    }

    /** Generate a new token-authentication key for signed URLs. */
    public function resetSecurityKey($pullZoneId)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId . '/resetSecurityKey');
    }

    /** Install a client-supplied certificate (PEM) for a hostname. */
    public function addCertificate($pullZoneId, $hostname, $certificatePem, $keyPem)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId . '/addCertificate', [
            'Hostname' => $hostname,
            'Certificate' => base64_encode($certificatePem),
            'CertificateKey' => base64_encode($keyPem),
        ]);
    }

    public function purgeCache($pullZoneId)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId . '/purgeCache');
    }

    /** Light call used by "Test Connection". */
    public function listPullZones()
    {
        return $this->request('GET', '/pullzone', null, ['page' => 1, 'perPage' => 5]);
    }

    /** One page of pull zones (for importing existing zones). */
    public function listPullZonePage($page = 1, $perPage = 100)
    {
        $res = $this->request('GET', '/pullzone', null, ['page' => (int) $page, 'perPage' => (int) $perPage]);
        if (isset($res['Items'])) {
            return $res;
        }
        // Older API versions return a plain list
        return ['Items' => $res, 'HasMoreItems' => false];
    }

    /** Purge one URL (wildcards allowed at the end, e.g. https://example.com/images/*). */
    public function purgeUrl($url)
    {
        return $this->request('POST', '/purge', null, ['url' => $url, 'async' => 'false']);
    }

    // -------------------------------------------------------------- Storage zones

    public function createStorageZone($name, $region, array $replicationRegions = [])
    {
        return $this->request('POST', '/storagezone', [
            'Name' => $name,
            'Region' => $region,
            'ReplicationRegions' => array_values($replicationRegions),
            'ZoneTier' => 0,
        ]);
    }

    public function getStorageZone($storageZoneId)
    {
        return $this->request('GET', '/storagezone/' . (int) $storageZoneId);
    }

    public function deleteStorageZone($storageZoneId)
    {
        return $this->request('DELETE', '/storagezone/' . (int) $storageZoneId);
    }

    public function resetStoragePassword($storageZoneId)
    {
        return $this->request('POST', '/storagezone/resetPassword', null, ['id' => (int) $storageZoneId]);
    }

    // ----------------------------------------------------------------- Statistics

    /**
     * Traffic statistics for a pull zone over a date range (Y-m-d, inclusive).
     */
    public function getStatistics($pullZoneId, $dateFrom, $dateTo, $hourly = false, $loadErrors = false)
    {
        $query = [
            'pullZone' => (int) $pullZoneId,
            'dateFrom' => $dateFrom . 'T00:00:00Z',
            'dateTo' => $dateTo . 'T23:59:59Z',
            'hourly' => $hourly ? 'true' : 'false',
        ];
        if ($loadErrors) {
            $query['loadErrors'] = 'true';
        }
        return $this->request('GET', '/statistics', null, $query);
    }

    // ------------------------------------------------------------------ DNSSEC

    /** Enable DNSSEC; returns the DS record details for the registrar. */
    public function enableDnssec($zoneId)
    {
        return $this->request('POST', '/dnszone/' . (int) $zoneId . '/dnssec');
    }

    public function disableDnssec($zoneId)
    {
        return $this->request('DELETE', '/dnszone/' . (int) $zoneId . '/dnssec');
    }

    public function updateStorageZone($storageZoneId, array $data)
    {
        return $this->request('POST', '/storagezone/' . (int) $storageZoneId, $data);
    }

    // ----------------------------------------------------------------- Logging

    /**
     * Stream one day's access log (Y-m-d) of a pull zone. $onStart runs once the
     * download is known to succeed, $onChunk receives the data. Returns bytes sent.
     */
    public function streamLog($pullZoneId, $date, callable $onStart, callable $onChunk)
    {
        $url = self::$logBaseUrl . '/' . date('m-d-y', strtotime($date)) . '/' . (int) $pullZoneId . '.log';
        return self::streamUrl($url, ['AccessKey: ' . $this->apiKey], $onStart, $onChunk, 'GET log ' . $date);
    }

    /** GET a URL and stream the body to callbacks; shared with the storage client. */
    public static function streamUrl($url, array $headers, callable $onStart, callable $onChunk, $logLabel)
    {
        $ch = curl_init($url);
        $started = false;
        $errorBody = '';
        $bytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$started, &$errorBody, &$bytes, $onStart, $onChunk) {
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code < 200 || $code >= 300) {
                    $errorBody .= substr($data, 0, 2000);
                    return strlen($data);
                }
                if (!$started) {
                    $started = true;
                    $onStart();
                }
                $onChunk($data);
                $bytes += strlen($data);
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (function_exists('logModuleCall')) {
            logModuleCall('seekahostcdn', $logLabel, [], $started ? "{$bytes} bytes streamed" : ($ok === false ? $err : $errorBody), 'HTTP ' . $code);
        }
        if ($ok === false && !$started) {
            throw new BunnyException('Could not reach bunny.net: ' . $err);
        }
        if (!$started && ($code < 200 || $code >= 300)) {
            throw new BunnyException($code === 404 ? 'Not found.' : 'bunny.net returned HTTP ' . $code, $code);
        }
        if (!$started) {
            $onStart(); // empty file
        }
        return $bytes;
    }

    // -------------------------------------------------------------------- Core

    private function request($method, $path, $body = null, array $query = [])
    {
        $url = self::$baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = $raw === false ? curl_error($ch) : '';
        curl_close($ch);

        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;

        if (function_exists('logModuleCall')) {
            // Never write passwords or keys (e.g. storage zone FTP passwords) to the module log
            $logged = $raw === false ? $curlError : preg_replace('/"(Password|ReadOnlyPassword|AccessKey|ApiKey)"\s*:\s*"[^"]*"/i', '"$1":"***"', $raw);
            $loggedBody = $body;
            if (is_array($loggedBody)) {
                foreach (['Certificate', 'CertificateKey', 'Password'] as $secret) {
                    if (isset($loggedBody[$secret])) {
                        $loggedBody[$secret] = '***';
                    }
                }
            }
            logModuleCall('seekahostcdn', $method . ' ' . $path, ['query' => $query, 'body' => $loggedBody],
                $logged, 'HTTP ' . $code, [$this->apiKey]);
        }

        if ($raw === false) {
            throw new BunnyException('Could not reach bunny.net: ' . $curlError);
        }
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) ? ($decoded['Message'] ?? $decoded['message'] ?? '') : '';
            if ($msg === '' && is_array($decoded) && !empty($decoded['errors'])) {
                $msg = json_encode($decoded['errors']);
            }
            throw new BunnyException($msg !== '' ? $msg : 'bunny.net API returned HTTP ' . $code, $code);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
