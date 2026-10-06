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

    public function purgeCache($pullZoneId)
    {
        return $this->request('POST', '/pullzone/' . (int) $pullZoneId . '/purgeCache');
    }

    /** Light call used by "Test Connection". */
    public function listPullZones()
    {
        return $this->request('GET', '/pullzone', null, ['page' => 1, 'perPage' => 5]);
    }

    // ----------------------------------------------------------------- Statistics

    /**
     * Traffic statistics for a pull zone over a date range (Y-m-d, inclusive).
     */
    public function getStatistics($pullZoneId, $dateFrom, $dateTo, $hourly = false)
    {
        return $this->request('GET', '/statistics', null, [
            'pullZone' => (int) $pullZoneId,
            'dateFrom' => $dateFrom . 'T00:00:00Z',
            'dateTo' => $dateTo . 'T23:59:59Z',
            'hourly' => $hourly ? 'true' : 'false',
        ]);
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
            logModuleCall('seekahostcdn', $method . ' ' . $path, ['query' => $query, 'body' => $body],
                $raw === false ? $curlError : $raw, 'HTTP ' . $code, [$this->apiKey]);
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
