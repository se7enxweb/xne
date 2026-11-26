<?php
declare(strict_types=1);

namespace Xne;

/**
 * Exponential version strings: 6.0.14, v6.0.15, 6.0.15alpha9, 6.0.15beta1, 6.0.15rc2, 6.0.15stable. Compared
 * numerically position by position (6.0.4.10 after 6.0.4.9), pre-releases before the release.
 */
final class Version
{
    private const STATES = ['alpha' => 1, 'beta' => 2, 'rc' => 3, 'stable' => 9, '' => 9];

    /** @return array{nums: list<int>, state: string, stateNum: int, pre: int}|null */
    public static function parse(string $v): ?array
    {
        $v = strtolower(trim($v));
        if (!preg_match('/^v?(\d+(?:\.\d+){1,3})[-.]?(alpha|beta|rc|stable)?\.?(\d*)$/', $v, $m)) {
            return null;
        }
        $state = $m[2] ?? '';
        return [
            'nums' => array_map('intval', explode('.', $m[1])),
            'state' => $state === '' ? 'stable' : $state,
            'stateNum' => self::STATES[$state],
            'pre' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0,
        ];
    }

    public static function valid(string $v): bool
    {
        return self::parse($v) !== null;
    }

    public static function isStable(string $v): bool
    {
        $p = self::parse($v);
        return $p !== null && $p['state'] === 'stable';
    }

    /** -1, 0, 1; an invalid version sorts first. */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa === null || $pb === null) {
            return ($pa === null ? 0 : 1) <=> ($pb === null ? 0 : 1);
        }
        $n = max(count($pa['nums']), count($pb['nums']));
        for ($i = 0; $i < $n; $i++) {
            $c = ($pa['nums'][$i] ?? 0) <=> ($pb['nums'][$i] ?? 0);
            if ($c !== 0) {
                return $c;
            }
        }
        return [$pa['stateNum'], $pa['pre']] <=> [$pb['stateNum'], $pb['pre']];
    }

    /** "6.0.15alpha9" -> "6.0.15"; "v6.0.14" -> "6.0.14". */
    public static function base(string $v): string
    {
        $p = self::parse($v);
        return $p === null ? $v : implode('.', $p['nums']);
    }

    /** "6.0" for 6.0.14. */
    public static function line(string $v): string
    {
        $p = self::parse($v);
        return $p === null ? '' : $p['nums'][0] . '.' . ($p['nums'][1] ?? 0);
    }

    public static function major(string $v): int
    {
        $p = self::parse($v);
        return $p === null ? 0 : $p['nums'][0];
    }

    /** The version an Exponential tree declares in lib/version.php (eZPublishSDK constants), or null. */
    public static function fromTree(string $root): ?string
    {
        $f = $root . '/lib/version.php';
        if (!is_file($f)) {
            return null;
        }
        $src = (string) file_get_contents($f);
        $get = static function (string $c) use ($src): ?string {
            if (preg_match('/const\s+' . $c . '\s*=\s*([^;]+);/', $src, $m)) {
                return trim($m[1], " '\"");
            }
            return null;
        };
        $maj = $get('VERSION_MAJOR');
        $min = $get('VERSION_MINOR');
        $rel = $get('VERSION_RELEASE');
        if ($maj === null || $min === null || $rel === null) {
            return null;
        }
        $state = (string) $get('VERSION_STATE');
        $v = "$maj.$min.$rel";
        if ($state !== '' && $state !== 'stable' && $state !== 'false') {
            $v .= $state;
        }
        return $v;
    }
}
