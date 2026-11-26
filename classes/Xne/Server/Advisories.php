<?php
declare(strict_types=1);

namespace Xne\Server;

use Xne\Version;

/**
 * Security and upgrade advisories per version range, kept by 7x in advisories.json:
 * [{"id": "XNE-2026-001", "title": "...", "severity": "high", "affected": ">=6.0.0 <6.0.14", "fixed_in": "6.0.14",
 *   "url": "https://..."}]. "affected" is a space-separated list of conditions (>=, >, <=, <, =), all must hold.
 */
final class Advisories
{
    public static function matches(string $range, string $version): bool
    {
        $range = trim($range);
        if ($range === '' || !Version::valid($version)) {
            return false;
        }
        foreach (preg_split('/\s+/', $range) as $cond) {
            if (!preg_match('/^(>=|<=|>|<|=)?(.+)$/', $cond, $m) || !Version::valid($m[2])) {
                return false;
            }
            $c = Version::compare($version, $m[2]);
            $op = $m[1] === '' ? '=' : $m[1];
            $ok = ($op === '>=' && $c >= 0) || ($op === '>' && $c > 0) || ($op === '<=' && $c <= 0) || ($op === '<' && $c < 0) || ($op === '=' && $c === 0);
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $all @return list<array<string,mixed>> */
    public static function forVersion(array $all, string $version): array
    {
        return array_values(array_filter($all, static function ($a) use ($version) {
            return self::matches((string) ($a['affected'] ?? ''), $version);
        }));
    }
}
