<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Db\Factory;
use Xne\Filelist;
use Xne\Ini;
use Xne\Proc;
use Xne\Version;
use Xne\XneException;

/**
 * Detect: what an install is. Root, version (lib/version.php, composer.lock), install type (git, Composer, plain
 * files), PHP, the databases from its own settings, the extensions (kernel, 7x, third-party), the owner and which
 * of the kernel's own upgrade tools it has. Read-only; nothing is connected to but the database (for its version).
 */
final class Detect
{
    /** Finds the install root at $start or above it (lib/version.php + kernel/ + settings/). */
    public static function findRoot(string $start): string
    {
        $d = realpath($start);
        if ($d === false) {
            throw XneException::usage("no such folder: $start");
        }
        while (true) {
            if (is_file($d . '/lib/version.php') && is_dir($d . '/kernel') && is_dir($d . '/settings')) {
                return $d;
            }
            $p = dirname($d);
            if ($p === $d) {
                throw XneException::usage("no Exponential install at or above $start (looked for lib/version.php, kernel/, settings/)");
            }
            $d = $p;
        }
    }


    /**
     * The install root of a hosted domain on this server (Plesk layout): <vhosts>/<account>/doc/<domain> (the 7x
     * standard), then httpdocs and public_html of the domain's own account, then those of other accounts.
     * <vhosts> is /var/www/vhosts (XNE_VHOSTS_DIR in tests).
     */
    public static function rootForDomain(string $domain): string
    {
        $domain = strtolower($domain);
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw XneException::usage("not a domain name: $domain");
        }
        $v = rtrim((string) (getenv('XNE_VHOSTS_DIR') ?: '/var/www/vhosts'), '/');
        $cands = [$v . '/' . $domain . '/doc/' . $domain];
        foreach ((array) glob($v . '/*/doc/' . $domain) as $g) {
            $cands[] = (string) $g;
        }
        array_push($cands, $v . '/' . $domain . '/httpdocs', $v . '/' . $domain . '/public_html');
        foreach ($cands as $c) {
            if (is_file($c . '/lib/version.php') && is_dir($c . '/kernel')) {
                return (string) realpath($c);
            }
        }
        throw XneException::usage("no Exponential install found for $domain under $v");
    }
    /** @return array<string,mixed> */
    public static function run(string $root, bool $withDb = true): array
    {
        $r = ['root' => $root];
        $r['version'] = Version::fromTree($root);
        if ($r['version'] === null) {
            throw XneException::usage("$root has no readable lib/version.php");
        }
        $r['line'] = Version::line($r['version']);
        $r['install_type'] = self::installType($root, $r);
        $r['git'] = $r['install_type'] === 'git' ? self::git($root) : null;
        $r['php'] = ['cli' => PHP_VERSION, 'binary' => PHP_BINARY];
        $r['owner'] = self::owner($root);
        $r['tools'] = [
            'console' => is_file($root . '/bin/php/console'),
            'upgrade' => is_file($root . '/bin/php/upgrade.php'),
            'maintenance' => is_file($root . '/bin/php/maintenance.php'),
            'checkmanifest' => is_file($root . '/bin/php/checkmanifest.php'),
            'ezcache' => is_file($root . '/bin/php/ezcache.php'),
            'autoloads' => is_file($root . '/bin/php/ezpgenerateautoloads.php'),
            'velocity' => is_file($root . '/bin/php/velocity.php'),
        ];
        $mf = Filelist::read($root . '/share/filelist.md5');
        $r['manifest'] = ['present' => $mf !== null, 'entries' => $mf === null ? 0 : count($mf)];
        $r['databases'] = [];
        foreach (Factory::settings($root) as $s) {
            $d = ['engine' => $s['engine'], 'implementation' => $s['implementation'], 'database' => $s['database'], 'server' => $s['server'],
                'port' => $s['port'], 'siteaccesses' => $s['siteaccesses']];
            if (isset($s['file'])) {
                $d['file'] = $s['file'];
            }
            if ($withDb && in_array($s['engine'], ['mysql', 'postgresql', 'sqlite', 'mongodb', 'oracle'], true)) {
                try {
                    $d['site_version'] = Factory::driver($s)->siteVersion();
                    $d['reachable'] = true;
                } catch (XneException $e) {
                    $d['reachable'] = false;
                    $d['error'] = $e->getMessage();
                }
            }
            $r['databases'][] = $d;
        }
        $r['extensions'] = self::extensions($root, $mf ?? []);
        $site = Ini::load(array_merge([$root . '/settings/site.ini'], Ini::candidates($root . '/settings/override', 'site.ini')));
        $r['siteaccesses'] = array_values(array_map('strval', (array) $site->get('SiteAccessSettings', 'AvailableSiteAccessList', [])));
        $r['site_url'] = (string) $site->get('SiteSettings', 'SiteURL', '');
        return $r;
    }

    /** @param array<string,mixed> $r */
    private static function installType(string $root, array &$r): string
    {
        if (is_dir($root . '/.git') || is_file($root . '/.git')) {
            return 'git';
        }
        $cj = $root . '/composer.json';
        if (is_file($cj)) {
            $j = json_decode((string) file_get_contents($cj), true);
            if (is_array($j) && ($j['name'] ?? '') !== 'se7enxweb/exponential' && isset($j['require']['se7enxweb/exponential'])) {
                return 'composer';
            }
        }
        $lock = $root . '/composer.lock';
        if (is_file($lock)) {
            $j = json_decode((string) file_get_contents($lock), true);
            foreach ((array) ($j['packages'] ?? []) as $p) {
                if (($p['name'] ?? '') === 'se7enxweb/exponential') {
                    $r['composer_version'] = (string) ($p['version'] ?? '');
                    return 'composer';
                }
            }
        }
        return 'files';
    }

    /** @return array<string,mixed> */
    public static function git(string $root): array
    {
        $g = static function (array $args) use ($root): string {
            $x = Proc::run(array_merge(\Xne\Method\Git::command($root), $args), null, ['GIT_TERMINAL_PROMPT' => '0'], 60);
            return $x['exit'] === 0 ? trim($x['out']) : '';
        };
        // porcelain lines start with a status column that may be a space (" M path"): trim() would cut the first
        // line's path by one character, so only line ends are trimmed here
        $st = Proc::run(array_merge(\Xne\Method\Git::command($root), ['status', '--porcelain', '--untracked-files=no']), null, ['GIT_TERMINAL_PROMPT' => '0'], 60);
        $dirty = $st['exit'] === 0 ? rtrim($st['out']) : '';
        return [
            'available' => Proc::which('git') !== null,
            'head' => $g(['rev-parse', 'HEAD']),
            'branch' => $g(['rev-parse', '--abbrev-ref', 'HEAD']),
            'describe' => $g(['describe', '--tags', '--always']),
            'remote' => $g(['remote']),
            'dirty' => $dirty === '' ? [] : array_values(array_filter(array_map(static function ($l) {
                return trim(substr($l, 3));
            }, explode("\n", $dirty)), 'strlen')),
        ];
    }

    /** @return array{user: string, group: string, uid: int, gid: int} */
    public static function owner(string $root): array
    {
        $uid = (int) @fileowner($root . '/index.php');
        $gid = (int) @filegroup($root . '/index.php');
        $user = (string) $uid;
        $group = (string) $gid;
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows has no numeric owners in PHP (fileowner() is 0): the account xne runs under owns what it writes
            $user = (string) (getenv('USERNAME') ?: $user);
            $group = (string) (getenv('USERDOMAIN') ?: $group);
        } elseif (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($uid);
            $gr = posix_getgrgid($gid);
            $user = is_array($pw) ? $pw['name'] : $user;
            $group = is_array($gr) ? $gr['name'] : $group;
        }
        return ['user' => $user, 'group' => $group, 'uid' => $uid, 'gid' => $gid];
    }

    /**
     * @param array<string,string> $manifest
     * @return list<array<string,mixed>>
     */
    public static function extensions(string $root, array $manifest): array
    {
        $site = Ini::load(array_merge([$root . '/settings/site.ini'], Ini::candidates($root . '/settings/override', 'site.ini')));
        $active = array_map('strval', (array) $site->get('ExtensionSettings', 'ActiveExtensions', []));
        $composer = [];
        $inst = $root . '/vendor/composer/installed.json';
        if (is_file($inst)) {
            $j = json_decode((string) file_get_contents($inst), true);
            $pk = isset($j['packages']) ? $j['packages'] : (is_array($j) ? $j : []);
            foreach ((array) $pk as $p) {
                $ip = (string) ($p['install-path'] ?? '');
                if (preg_match('#extension/([^/]+)/?$#', $ip, $m)) {
                    $composer[$m[1]] = ['name' => (string) ($p['name'] ?? ''), 'version' => (string) ($p['version'] ?? '')];
                }
            }
        }
        // composer.lock names packages that installed.json does not know yet: the lock was refreshed without an
        // install (installs whose extensions are live checkouts, or copied into place). The folder is the one the
        // legacy installer uses: extra.ezpublish-legacy-extension-name, else the package name after the vendor.
        $lock = $root . '/composer.lock';
        if (is_file($lock)) {
            $j = json_decode((string) file_get_contents($lock), true);
            foreach (array_merge((array) ($j['packages'] ?? []), (array) ($j['packages-dev'] ?? [])) as $p) {
                if (!is_array($p) || ($p['type'] ?? '') !== 'ezpublish-legacy-extension') {
                    continue;
                }
                $name = (string) ($p['name'] ?? '');
                $dir = (string) ($p['extra']['ezpublish-legacy-extension-name'] ?? substr((string) strrchr('/' . $name, '/'), 1));
                if ($dir !== '' && !isset($composer[$dir])) {
                    $composer[$dir] = ['name' => $name, 'version' => (string) ($p['version'] ?? '')];
                }
            }
        }
        $kernelExt = [];
        foreach (array_keys($manifest) as $p) {
            if (strncmp($p, 'extension/', 10) === 0) {
                $kernelExt[explode('/', $p)[1]] = true;
            }
        }
        $out = [];
        foreach ((array) @scandir($root . '/extension') as $e) {
            $e = (string) $e;
            if ($e === '' || $e[0] === '.' || !is_dir($root . '/extension/' . $e)) {
                continue;
            }
            $dir = $root . '/extension/' . $e;
            $version = '';
            if (is_file($dir . '/extension.xml') && preg_match('#<version>\s*([^<]+?)\s*</version>#', (string) file_get_contents($dir . '/extension.xml'), $m)) {
                $version = $m[1];
            }
            if ($version === '' && is_file($dir . '/ezinfo.php') && preg_match('#[\'"]Version[\'"]\s*=>\s*[\'"]([^\'"]+)#', (string) file_get_contents($dir . '/ezinfo.php'), $m)) {
                $version = $m[1];
            }
            $cp = $composer[$e] ?? null;
            $kind = 'third-party';
            if ($cp !== null && strncmp($cp['name'], 'se7enxweb/', 10) === 0) {
                $kind = '7x';
            } elseif (isset($kernelExt[$e]) && $cp === null) {
                $kind = 'kernel';
            }
            $out[] = [
                'name' => $e,
                'active' => in_array($e, $active, true),
                'version' => $version !== '' ? $version : ($cp['version'] ?? ''),
                'kind' => $kind,
                'composer' => $cp['name'] ?? null,
                'git' => is_dir($dir . '/.git') || is_file($dir . '/.git'),
                'link' => is_link($dir) ? \Xne\Fs::readlink($dir) : null,
            ];
        }
        return $out;
    }
}
