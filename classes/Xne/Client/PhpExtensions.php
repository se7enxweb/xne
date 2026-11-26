<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Proc;

/**
 * The PHP extensions the target release requires (ext-* of its composer.json and of every package its composer.lock
 * installs), checked against the PHP the kernel's tools and Composer will run with, before anything changes.
 * Composer refuses an install with a missing extension half way through the libraries stage, and the platform check
 * of the new vendor/ stops every request after it; the plan blocks instead and names the php.ini line to add.
 */
final class PhpExtensions
{
    /** Extensions that are part of PHP itself on every build (Composer still lets packages require them). */
    private const CORE = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    /**
     * ext name (lower case, Composer's spelling without "ext-") => who requires it ("composer.json" or a package).
     * Only what an install without dev packages needs: composer.json "require", composer.lock "packages" and the
     * lock's "platform"; require-dev and packages-dev are left out.
     * @return array<string, list<string>>
     */
    public static function required(string $tree): array
    {
        $req = [];
        $add = static function (array $list, string $who) use (&$req): void {
            foreach ($list as $name => $c) {
                $n = strtolower((string) $name);
                if (strpos($n, 'ext-') === 0 && strlen($n) > 4) {
                    $e = substr($n, 4);
                    if (!in_array($who, $req[$e] ?? [], true)) {
                        $req[$e][] = $who;
                    }
                }
            }
        };
        $json = self::readJson($tree . '/composer.json');
        if ($json !== null) {
            $add((array) ($json['require'] ?? []), 'composer.json');
        }
        $lock = self::readJson($tree . '/composer.lock');
        if ($lock !== null) {
            $add((array) ($lock['platform'] ?? []), 'composer.json');
            foreach ((array) ($lock['packages'] ?? []) as $p) {
                if (is_array($p) && isset($p['name'])) {
                    $add((array) ($p['require'] ?? []), (string) $p['name']);
                }
            }
        }
        ksort($req);
        return $req;
    }

    /**
     * The extensions a PHP binary loads with its own php.ini (Composer's spelling: lower case, spaces as dashes,
     * so "Zend OPcache" is "zend-opcache"), or null when the binary does not run.
     * @return list<string>|null
     */
    public static function loaded(string $php): ?array
    {
        if (realpath($php) !== false && realpath($php) === realpath(PHP_BINARY)) {
            $names = get_loaded_extensions();
            $names = array_merge($names, get_loaded_extensions(true));
        } else {
            $r = Proc::run([$php, '-m'], null, null, 60);
            if ($r['exit'] !== 0) {
                return null;
            }
            $names = preg_split('/\R/', (string) $r['out']) ?: [];
        }
        return self::normalise($names);
    }

    /** @param list<string> $names @return list<string> */
    public static function normalise(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n === '' || $n[0] === '[') {
                continue;   // "[PHP Modules]", "[Zend Modules]"
            }
            $out[] = str_replace(' ', '-', strtolower($n));
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * @param array<string, list<string>> $required
     * @param list<string> $loaded
     * @return array<string, list<string>> the required extensions not loaded (core ones never count)
     */
    public static function missing(array $required, array $loaded): array
    {
        $have = array_flip($loaded);
        // aliases Composer accepts for the same module
        if (isset($have['zend-opcache'])) {
            $have['opcache'] = true;
        }
        if (isset($have['opcache'])) {
            $have['zend-opcache'] = true;
        }
        $miss = [];
        foreach ($required as $e => $who) {
            if (!isset($have[$e]) && !in_array($e, self::CORE, true)) {
                $miss[$e] = $who;
            }
        }
        return $miss;
    }

    /**
     * The php.ini line that enables an extension, on Linux and on Windows (the same line since PHP 7.2; on Windows
     * the DLL ext\php_<name>.dll must exist and extension_dir point at the ext folder).
     * @return array{linux: string, windows: string}
     */
    public static function iniLines(string $ext): array
    {
        $module = $ext === 'zend-opcache' ? 'opcache' : $ext;
        $line = ($module === 'opcache' || $module === 'xdebug' ? 'zend_extension=' : 'extension=') . $module;
        return [
            'linux' => $line . ' (php.ini or a file in its conf.d folder; when the module is not installed, the distribution\'s package first, e.g. php-' . $module . ' or php8.x-' . $module . ')',
            'windows' => $line . ' (php.ini; ext\\php_' . $module . '.dll must exist and extension_dir = "ext")',
        ];
    }

    /** The php.ini a binary loads ("" when none or unknown). */
    public static function iniFile(string $php): string
    {
        if (realpath($php) !== false && realpath($php) === realpath(PHP_BINARY)) {
            return (string) php_ini_loaded_file();
        }
        $r = Proc::run([$php, '--ini'], null, null, 30);
        if ($r['exit'] === 0 && preg_match('/^Loaded Configuration File:\s*(.+)$/m', (string) $r['out'], $m)) {
            $f = trim($m[1]);
            return $f === '(none)' ? '' : $f;
        }
        return '';
    }

    /**
     * The check the plan runs. @return array{required: array<string, list<string>>, missing: array<string, array{required_by: list<string>, linux: string, windows: string}>, php: ?string, ini: string, checked: bool}
     */
    public static function check(string $tree, ?string $php): array
    {
        $req = self::required($tree);
        $out = ['required' => $req, 'missing' => [], 'php' => $php, 'ini' => '', 'checked' => false];
        if ($php === null || $req === []) {
            return $out;
        }
        $loaded = self::loaded($php);
        if ($loaded === null) {
            return $out;
        }
        $out['checked'] = true;
        foreach (self::missing($req, $loaded) as $e => $who) {
            $out['missing'][$e] = ['required_by' => $who] + self::iniLines($e);
        }
        if ($out['missing'] !== []) {
            $out['ini'] = self::iniFile($php);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $f): ?array
    {
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }
}
