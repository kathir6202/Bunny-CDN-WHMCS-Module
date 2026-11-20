<?php
/**
 * bunny.net Edge Storage API client (file manager). Authenticates with the
 * storage zone password, not the account API key.
 */

namespace SeekahostCDN;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/BunnyClient.php';

class StorageClient
{
    /** Tests point this at a fake server; null = https://{storage hostname} */
    public static $baseOverride = null;

    private $base;
    private $zone;
    private $password;

    public function __construct($hostname, $zoneName, $password)
    {
        if ($password === '') {
            throw new BunnyException('The storage zone password is not available.');
        }
        $this->base = self::$baseOverride ?: 'https://' . $hostname;
        $this->zone = $zoneName;
        $this->password = $password;
    }

    /** URL for a path made of already validated segments. */
    private function url($path, $isDir = false)
    {
        $segments = array_filter(explode('/', $path), function ($s) {
            return $s !== '';
        });
        $encoded = implode('/', array_map('rawurlencode', $segments));
        // Folders end with "/"; the root is ".../{zone}/"
        return $this->base . '/' . rawurlencode($this->zone) . '/' . $encoded . ($isDir && $encoded !== '' ? '/' : '');
    }

    private function call($method, $url, array $curlOpts = [])
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOpts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => ['AccessKey: ' . $this->password, 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        if (function_exists('logModuleCall')) {
            logModuleCall('seekahostcdn', 'Storage ' . $method . ' ' . parse_url($url, PHP_URL_PATH), [],
                $raw === false ? $err : substr((string) $raw, 0, 2000), 'HTTP ' . $code, [$this->password]);
        }
        if ($raw === false) {
            throw new BunnyException('Could not reach bunny.net storage: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode((string) $raw, true);
            $msg = is_array($decoded) ? ($decoded['Message'] ?? '') : '';
            throw new BunnyException($msg !== '' ? $msg : ($code === 404 ? 'Not found.' : 'Storage returned HTTP ' . $code), $code);
        }
        return $raw;
    }

    /** Files and folders directly inside $path ('' = root). */
    public function listFolder($path)
    {
        $list = json_decode((string) $this->call('GET', $this->url($path, true)), true);
        return is_array($list) ? $list : [];
    }

    /** Upload a local file to $path (folders are created automatically). */
    public function upload($path, $localFile)
    {
        $fh = fopen($localFile, 'rb');
        try {
            $this->call('PUT', $this->url($path), [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fh,
                CURLOPT_INFILESIZE => filesize($localFile),
                CURLOPT_HTTPHEADER => ['AccessKey: ' . $this->password, 'Content-Type: application/octet-stream'],
            ]);
        } finally {
            fclose($fh);
        }
    }

    /** Delete a file, or a folder and everything in it. */
    public function delete($path, $isDir)
    {
        $this->call('DELETE', $this->url($path, $isDir));
    }

    public function stream($path, callable $onStart, callable $onChunk)
    {
        return BunnyClient::streamUrl($this->url($path), ['AccessKey: ' . $this->password], $onStart, $onChunk, 'Storage GET ' . $path);
    }
}
