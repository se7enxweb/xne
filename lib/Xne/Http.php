<?php
declare(strict_types=1);

namespace Xne;

/**
 * A small HTTPS client: curl when the extension is loaded, PHP streams otherwise. TLS is always verified. Header values
 * (tokens) are never written to a log or an error message.
 */
final class Http
{
    /** @var string */
    public static $userAgent = 'xne (Exponential Network client)';
    /** @var int */
    public static $timeout = 60;

    /**
     * @param array<string,string> $headers
     * @return array{status: int, body: string, headers: array<string,string>}
     */
    public static function get(string $url, array $headers = []): array
    {
        if (function_exists('curl_init')) {
            return self::curl($url, $headers, null);
        }
        return self::stream($url, $headers, null);
    }

    /** @param array<string,string> $headers */
    public static function getJson(string $url, array $headers = []): array
    {
        $r = self::get($url, $headers + ['Accept' => 'application/json']);
        if ($r['status'] !== 200) {
            throw new XneException('HTTP ' . $r['status'] . ' from ' . self::redact($url));
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d)) {
            throw new XneException('no JSON from ' . self::redact($url));
        }
        return $d;
    }

    /**
     * Downloads $url into $file (atomically: a temporary file renamed at the end).
     * @param array<string,string> $headers
     */
    public static function download(string $url, string $file, array $headers = []): int
    {
        Fs::mkdir(dirname($file));
        $tmp = Fs::tmpName($file);
        $r = function_exists('curl_init') ? self::curl($url, $headers, $tmp) : self::stream($url, $headers, $tmp);
        if ($r['status'] !== 200) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException('HTTP ' . $r['status'] . ' downloading ' . self::redact($url));
        }
        if (!@rename($tmp, $file)) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException("cannot store the download in $file");
        }
        return (int) filesize($file);
    }


    /**
     * POST a JSON body; returns the status and the decoded JSON answer (null when the answer is not JSON).
     * @param array<string,string> $headers
     * @return array{status: int, json: array<mixed>|null}
     */
    public static function postJson(string $url, string $body, array $headers = []): array
    {
        $headers = $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if (function_exists('curl_init')) {
            $h = curl_init($url);
            $hdr = ['User-Agent: ' . self::$userAgent];
            foreach ($headers as $k => $v) {
                $hdr[] = $k . ': ' . $v;
            }
            curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => self::$timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $out = curl_exec($h);
            $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $err = curl_errno($h) !== 0 ? curl_error($h) : '';
            if (PHP_VERSION_ID < 80000) {
                curl_close($h);
            }
            if ($err !== '') {
                throw new XneException('network error for ' . self::redact($url) . ': ' . $err);
            }
        } else {
            $hdr = 'User-Agent: ' . self::$userAgent . "\r\n";
            foreach ($headers as $k => $v) {
                $hdr .= $k . ': ' . $v . "\r\n";
            }
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => $hdr, 'content' => $body, 'timeout' => self::$timeout, 'ignore_errors' => true]]);
            $out = @file_get_contents($url, false, $ctx);
            if ($out === false) {
                throw new XneException('network error for ' . self::redact($url));
            }
            $status = 0;
            foreach ((array) ($http_response_header ?? []) as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }
        $j = json_decode((string) $out, true);
        return ['status' => $status, 'json' => is_array($j) ? $j : null];
    }
    public static function redact(string $url): string
    {
        return (string) preg_replace('/([?&](?:private_token|access_token|token)=)[^&]+/i', '$1***', $url);
    }

    /** @param array<string,string> $headers */
    private static function curl(string $url, array $headers, ?string $toFile): array
    {
        $h = curl_init($url);
        $hdr = ['User-Agent: ' . self::$userAgent];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        $fh = null;
        curl_setopt_array($h, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $toFile !== null ? 3600 : self::$timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($toFile !== null) {
            $fh = fopen($toFile, 'wb');
            if ($fh === false) {
                throw new XneException("cannot write $toFile");
            }
            curl_setopt($h, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        }
        $body = curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $errNo = curl_errno($h);
        $err = curl_error($h);
        if (PHP_VERSION_ID < 80000) {
            curl_close($h);
        }
        if ($fh !== null) {
            fclose($fh);
        }
        if ($errNo !== 0) {
            throw new XneException('network error for ' . self::redact($url) . ': ' . $err);
        }
        return ['status' => $status, 'body' => $toFile === null ? (string) $body : '', 'headers' => $respHeaders];
    }

    /** @param array<string,string> $headers */
    private static function stream(string $url, array $headers, ?string $toFile): array
    {
        $hdr = 'User-Agent: ' . self::$userAgent . "\r\n";
        foreach ($headers as $k => $v) {
            $hdr .= $k . ': ' . $v . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => ['header' => $hdr, 'timeout' => $toFile !== null ? 3600 : self::$timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 8],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            throw new XneException('network error for ' . self::redact($url));
        }
        $meta = stream_get_meta_data($in);
        $status = 0;
        $respHeaders = [];
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m)) {
                $status = (int) $m[1];
                $respHeaders = [];
            } elseif (strpos((string) $line, ':') !== false) {
                [$k, $v] = explode(':', (string) $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        if ($toFile !== null) {
            $out = fopen($toFile, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
            return ['status' => $status, 'body' => '', 'headers' => $respHeaders];
        }
        $body = (string) stream_get_contents($in);
        fclose($in);
        return ['status' => $status, 'body' => $body, 'headers' => $respHeaders];
    }
}
