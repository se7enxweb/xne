<?php
declare(strict_types=1);

namespace Xne;

/**
 * share/filelist.md5: "<md5>  <path>" lines, the kernel's manifest of a release, read by the admin's upgrade check
 * and bin/php/checkmanifest.php. xne reads it as the base of an install and writes it nowhere itself: the target
 * release's own copy lands with the other files (the "live manifest written on every landing" rule).
 */
final class Filelist
{
    /** @return array<string,string> path => md5 */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (preg_match('/^([0-9a-f]{32})\s+\*?(.+)$/', trim($line), $m)) {
                $out[$m[2]] = $m[1];
            }
        }
        return $out;
    }

    /** @return array<string,string>|null */
    public static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        return self::parse((string) file_get_contents($file));
    }

    /** @param array<string,string> $map */
    public static function format(array $map): string
    {
        ksort($map, SORT_STRING);
        $s = '';
        foreach ($map as $p => $m) {
            if (strncmp($m, 'link:', 5) === 0) {
                continue;
            }
            $s .= $m . '  ' . $p . "\n";
        }
        return $s;
    }
}
