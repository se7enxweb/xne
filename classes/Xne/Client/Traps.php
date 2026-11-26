<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Fs;
use Xne\Ini;
use Xne\Version;

/**
 * The known upgrade traps (design §6.1), each a detector with a level and the fix. Levels: info (shown), warn (shown,
 * the upgrade goes on), block (the upgrade is refused unless --accept=<id>). Every trap below cost a failed attempt
 * in a hand-run upgrade before xne existed.
 */
final class Traps
{
    public const IDS = [
        'public-lib-copy', 'composer-files-entry', 'shared-extension-folder', 'litespeed-htaccess-php', 'clear-id-one-at-a-time',
        'valkey-view-cache', 'installation-hash', 'kernel-overrides', 'live-manifest', 'database-implementation', 'several-databases', 'unsupported-database',
    ];

    /**
     * @param array<string,mixed> $det Detect::run()
     * @param array<string,mixed>|null $diff the file plan, when there is one
     * @param string|null $targetTree the target release tree
     * @return list<array{id: string, level: string, message: string, fix: string}>
     */
    public static function run(array $det, ?array $diff = null, ?string $targetTree = null): array
    {
        $root = (string) $det['root'];
        $t = [];
        $add = static function (string $id, string $level, string $message, string $fix = '') use (&$t): void {
            $t[] = ['id' => $id, 'level' => $level, 'message' => $message, 'fix' => $fix];
        };

        // 1. a public_html/lib copy: PHP resolves the kernel's relative lib/... includes from the document root.
        foreach ([dirname($root), dirname($root, 2)] as $up) {
            $lib = $up . '/lib';
            if ($up !== $root && is_dir($lib) && is_file($lib . '/version.php') && realpath($lib) !== realpath($root . '/lib')) {
                $v = Version::fromTree($up);
                $add('public-lib-copy', 'block', "$lib is a separate copy of the kernel's lib/ (version " . ($v ?? '?') . '); it is not upgraded, and PHP can load it instead of the install\'s own lib/',
                    "replace it with a link to $root/lib (keep the copy aside), or --accept=public-lib-copy when it belongs to another install");
            }
        }

        // 2. a Composer "files" entry that loads a file of another tree.
        $af = $root . '/vendor/composer/autoload_files.php';
        if (is_file($af)) {
            $src = (string) file_get_contents($af);
            if (preg_match_all('/\$(vendorDir|baseDir)\s*\.\s*\'([^\']+)\'/', $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $path = ($x[1] === 'vendorDir' ? $root . '/vendor' : $root) . $x[2];
                    $real = realpath($path);
                    if ($real !== false && !Fs::inside($root, $real)) {
                        $add('composer-files-entry', 'block', "vendor/composer/autoload_files.php loads $real, outside the install: every tree that shares this vendor/ runs that file",
                            'regenerate the autoloader inside the install (composer dump-autoload) so the entry points at its own autoload.php');
                    }
                }
            }
        }

        // 3. extension folders shared with another clone (symlinks out of the install).
        $shared = [];
        foreach ((array) ($det['extensions'] ?? []) as $e) {
            if (($e['link'] ?? null) !== null && !Fs::inside($root, $root . '/extension/' . $e['name'] . '/.')) {
                $shared[] = $e['name'];
            }
        }
        if (is_link($root . '/extension') && !Fs::inside($root, $root . '/extension/.')) {
            $shared[] = 'extension/ (the whole folder)';
        }
        if ($shared) {
            $writes = $diff !== null && !empty($diff['shared']);
            $add('shared-extension-folder', $writes ? 'block' : 'info', 'extension folders link out of the install (shared with another clone): ' . implode(', ', $shared)
                . ($writes ? '; the upgrade would change ' . count($diff['shared']) . ' file(s) there' : ''),
                'give the install its own copy of each shared folder, or --shared=skip (leave them) / --shared=write (change them for every clone)');
        }

        // 4. LiteSpeed (or cPanel EA) takes the PHP from an AddHandler line in .htaccess, not from the panel.
        foreach ([$root . '/.htaccess', dirname($root) . '/.htaccess'] as $ht) {
            if (is_file($ht) && preg_match('/AddHandler\s+application\/x-httpd-(?:ea-php|lsphp|alt-php|php)(\d)(\d+)/i', (string) file_get_contents($ht), $m)) {
                $webPhp = $m[1] . '.' . $m[2];
                $need = self::minPhp($targetTree);
                $low = $need !== null && version_compare($webPhp, $need, '<');
                $add('litespeed-htaccess-php', $low ? 'block' : 'info', "the web server takes PHP $webPhp from an AddHandler line in $ht" . ($low ? ", below the PHP $need the target needs" : ''),
                    'switch the AddHandler line (and the panel, for consistency) to a PHP the target supports; after the upgrade restart the PHP workers (LiteSpeed: pkill -x -u <owner> lsphp)');
            }
        }

        // 5. --clear-id takes one id per call: built into the finish step (one --clear-tag per call).
        $add('clear-id-one-at-a-time', 'info', 'cache clears run one tag per ezcache.php call (a comma list is ignored by --clear-id)', '');

        // 6 + 7. sevenx_valkey_cache: the content view cache in Valkey (ezcache leaves it) and its InstallationHash.
        $vdir = $root . '/extension/sevenx_valkey_cache';
        if (is_dir($vdir)) {
            $ini = Ini::load(Ini::candidates($vdir . '/settings', 'valkeycache.ini'));
            $hash = '';
            foreach (['ValkeyCacheSettings', 'CacheSettings', 'Settings'] as $sec) {
                $hash = $hash !== '' ? $hash : (string) $ini->get($sec, 'InstallationHash', '');
            }
            $active = false;
            foreach ((array) ($det['extensions'] ?? []) as $e) {
                if ($e['name'] === 'sevenx_valkey_cache' && $e['active']) {
                    $active = true;
                }
            }
            if ($active) {
                $add('valkey-view-cache', 'warn', 'sevenx_valkey_cache keeps the content view cache and the INI cache in Valkey; ezcache.php does not clear those keys',
                    'after the upgrade flush the keys of this install (prefix sevenx_valkey_cache:' . ($hash !== '' && $hash !== 'auto' ? $hash : '<hash>') . ':) through the extension, then reload PHP');
            }
            if ($hash === '' || $hash === 'auto') {
                $add('installation-hash', $active ? 'warn' : 'info', 'InstallationHash is "auto" (built from path and host): a tree swapped in by a rename reads the old tree\'s INI cache',
                    'fix InstallationHash in extension/sevenx_valkey_cache/settings/valkeycache.ini.append.php');
            } else {
                $add('installation-hash', 'info', "InstallationHash is fixed ($hash)", '');
            }
        }

        // 8. kernel overrides: the autoload generator maps kernel-class copies from every extension folder.
        $over = $root . '/var/autoload/ezp_override.php';
        $cfgPhp = is_file($root . '/config.php') ? (string) file_get_contents($root . '/config.php') : '';
        $allowed = (bool) preg_match('/^\s*define\s*\(\s*[\'"]EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE[\'"]\s*,\s*true\s*\)/mi', $cfgPhp);
        if (is_file($over)) {
            preg_match_all('/[\'"](eZ\w+|exp\w+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($over), $m, PREG_SET_ORDER);
            if ($m) {
                $list = array_map(static function ($x) {
                    return $x[1] . ' (' . $x[2] . ')';
                }, array_slice($m, 0, 8));
                $add('kernel-overrides', $allowed ? 'block' : 'warn', count($m) . ' kernel class override(s) in var/autoload/ezp_override.php' . ($allowed ? ', and EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE is on' : '') . ': ' . implode(', ', $list),
                    'check each copy against the target kernel (an old copy breaks the upgraded site); the generator maps copies from every extension folder, active or not; --accept=kernel-overrides when they are current');
            }
        }

        // 9. the live manifest: share/filelist.md5 must describe the files (it lands with every upgrade).
        if (empty($det['manifest']['present'])) {
            $add('live-manifest', 'info', 'the install has no share/filelist.md5; the base of the file patch is the release tree of its version', '');
        } else {
            $add('live-manifest', 'info', 'share/filelist.md5 is replaced with the target\'s own copy in the same upgrade, and checked afterwards', '');
        }


        // the database implementation must be known to the target kernel ([DatabaseSettings] ImplementationAlias):
        // an unknown name gives eZNullDB, and the site and every kernel upgrade step lose the database.
        if ($targetTree !== null && is_file($targetTree . '/settings/site.ini')) {
            // the kernel's aliases, those an active extension registers in its own settings (ezoracle adds ezoracle and
            // oracle), and the install's override
            $files = [$targetTree . '/settings/site.ini'];
            foreach ((array) ($det['extensions'] ?? []) as $e) {
                if (!empty($e['active'])) {
                    $files = array_merge($files, Ini::candidates($root . '/extension/' . $e['name'] . '/settings', 'site.ini'));
                }
            }
            $aliases = (array) Ini::load(array_merge($files, Ini::candidates($root . '/settings/override', 'site.ini')))
                ->get('DatabaseSettings', 'ImplementationAlias', []);
            foreach ((array) ($det['databases'] ?? []) as $d) {
                $impl = (string) ($d['implementation'] ?? '');
                if ($aliases && $impl !== '' && !isset($aliases[$impl])) {
                    $add('database-implementation', 'block', "DatabaseImplementation=$impl is not an ImplementationAlias of the target kernel (" . implode(', ', array_keys($aliases)) . '): the upgraded site would have no database',
                        'set DatabaseImplementation in settings/override/site.ini.append.php to one of those names (for SQLite: sqlite3)');
                }
            }
        }
        // databases xne cannot back up and restore yet, and installs with more than one database.
        $dbs = (array) ($det['databases'] ?? []);
        if (count($dbs) > 1) {
            $add('several-databases', 'block', 'the install uses ' . count($dbs) . ' databases (siteaccesses with their own): phase 1 upgrades installs with one database', 'upgrade it by hand with the kernel\'s exp:upgrade, or wait for xne phase 3');
        }
        foreach ($dbs as $d) {
            if (!in_array($d['engine'], ['mysql', 'postgresql', 'sqlite', 'mongodb', 'oracle'], true)) {
                $add('unsupported-database', 'block', 'the ' . $d['engine'] . ' database has no verified xne backup yet', 'use the kernel\'s exp:upgrade with its own captions');
            } elseif (isset($d['reachable']) && !$d['reachable']) {
                $add('unsupported-database', 'block', 'the database cannot be reached with the install\'s settings: ' . ($d['error'] ?? ''), 'check [DatabaseSettings] and run xne with a PHP that has the PDO driver');
            }
        }
        return $t;
    }

    /** The lowest PHP the target's composer.json allows ("^8.1 || ^8.2" -> 8.1), or null. */
    public static function minPhp(?string $tree): ?string
    {
        if ($tree === null || !is_file($tree . '/composer.json')) {
            return null;
        }
        $j = json_decode((string) file_get_contents($tree . '/composer.json'), true);
        $c = (string) ($j['require']['php'] ?? '');
        if (preg_match_all('/(\d+\.\d+)/', $c, $m)) {
            $v = $m[1];
            usort($v, 'version_compare');
            return $v[0];
        }
        return null;
    }

    /** @param list<array{id: string, level: string}> $traps @param list<string> $accepted @return list<array<string,string>> */
    public static function blocking(array $traps, array $accepted): array
    {
        return array_values(array_filter($traps, static function ($t) use ($accepted) {
            return $t['level'] === 'block' && !in_array($t['id'], $accepted, true);
        }));
    }
}
