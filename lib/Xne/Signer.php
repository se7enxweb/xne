<?php
declare(strict_types=1);

namespace Xne;

/**
 * HMAC-SHA256 request signing between xne clients and the Exponential Network server. The signed text is
 * "<method>\n<path>\n<unix time>\n<sha256 of the body>"; a signature older than 300 seconds is refused (replay).
 * Header: X-Xne-Key: <key id>, X-Xne-Time: <unix time>, X-Xne-Signature: <hex>. The secret never leaves its 0600 file.
 */
final class Signer
{
    public const MAX_AGE = 300;

    public static function sign(string $secret, string $method, string $path, int $time, string $body): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $time . "\n" . hash('sha256', $body), $secret);
    }

    /** @return array<string,string> */
    public static function headers(string $keyId, string $secret, string $method, string $path, string $body, ?int $time = null): array
    {
        $t = $time ?? time();
        return ['X-Xne-Key' => $keyId, 'X-Xne-Time' => (string) $t, 'X-Xne-Signature' => self::sign($secret, $method, $path, $t, $body)];
    }

    public static function verify(string $secret, string $method, string $path, string $time, string $body, string $sig, ?int $now = null): bool
    {
        if (!ctype_digit($time) || abs(($now ?? time()) - (int) $time) > self::MAX_AGE) {
            return false;
        }
        return hash_equals(self::sign($secret, $method, $path, (int) $time, $body), strtolower($sig));
    }

    /** A new key: id "xk_<16 hex>" and a 64-hex secret. @return array{id: string, secret: string} */
    public static function newKey(): array
    {
        return ['id' => 'xk_' . bin2hex(random_bytes(8)), 'secret' => bin2hex(random_bytes(32))];
    }

    /** A short fingerprint of a secret, safe to show. */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 12);
    }
}
