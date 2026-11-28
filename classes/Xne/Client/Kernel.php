<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Proc;
use Xne\XneException;

/**
 * The overlay on the install's own tools (design §6.2): xne never re-implements what the kernel does, it runs it.
 * bin/php/upgrade.php (exp:upgrade: libraries, database and scripts stages, in the kernel's order), ezcache.php
 * (one --clear-tag per call: a comma list is ignored by --clear-id), ezpgenerateautoloads.php, maintenance.php,
 * checkmanifest.php, upgradestatus.php and velocity.php deploy. Each runs with the install's PHP, from the install
 * root, as the install's owner (runuser when xne runs as root), else as root with --allow-root-user.
 */
final class Kernel
{
    /** @var string */
    private $root;
    /** @var Config */
    private $cfg;
    /** @var array{user: string, uid: int} */
    private $owner;
    /** @var list<array<string,mixed>> */
    public $runs = [];

    /** @param array{user: string, uid: int, group: string, gid: int} $owner */
    public function __construct(string $root, Config $cfg, array $owner)
    {
        $this->root = $root;
        $this->cfg = $cfg;
        $this->owner = $owner;
    }

    /** @var array<string,mixed>|null */
    private $phpChoice = null;

    /**
     * The PHP that runs the install's tools (selectPhp()): the php setting, else the PHP the web server runs the site
     * with (.htaccess, the Plesk vhost's PHP-FPM pool, Velocity, .php-version), else this PHP, else php on PATH, else
     * the installed ones, panel PHPs newest first; the first that the install's vendor/composer/platform_check.php
     * and the PHP constraints of its composer.lock accept. Refused when there is none: the kernel's tools would stop in
     * Composer's platform check, after the files had changed (a hosted install, 2026-11-26: CLI PHP 8.0, vendor >= 8.4).
     */
    public function php(): string
    {
        if ($this->phpChoice === null) {
            $this->phpChoice = self::selectPhp($this->cfg, $this->root);
        }
        if ($this->phpChoice['binary'] === null) {
            throw XneException::refused((string) $this->phpChoice['reason']);
        }
        return (string) $this->phpChoice['binary'];
    }

    /** The lowest PHP_VERSION_ID that vendor/composer/platform_check.php of $tree accepts, or null (no check). */
    public static function platformMinimum(?string $tree): ?int
    {
        $f = $tree === null ? '' : $tree . '/vendor/composer/platform_check.php';
        if ($f === '' || !is_file($f)) {
            return null;
        }
        if (preg_match('/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', (string) file_get_contents($f), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Where the panels keep what the web server runs a site with. The tests point these at fixture trees.
     * plesk_system: Plesk's per-vhost configuration (<domain>/conf/httpd.conf, nginx.conf); plesk_php: Plesk's PHP
     * builds, each with its PHP-FPM pools (<ver>/etc/php-fpm.d/<domain>.conf); velocity: Velocity's enabled site
     * files (vcc names them vcc-<user>-php85-<hash>.conf).
     * @var array{plesk_system: string, plesk_php: string, velocity: list<string>}
     */
    public static $webPaths = ['plesk_system' => '/var/www/vhosts/system', 'plesk_php' => '/opt/plesk/php',
        'velocity' => ['/etc/vc/sites-enabled', '/etc/qbix/sites-enabled']];

    /** @var array<string, array<string, array<string, list<string>>>> the Plesk and Velocity scans per root, for one run */
    private static $webCache = [];

    /** The PHP version ("8.4") the web server runs the site with (webPhpInfo()), or null when unknown. */
    public static function webPhp(string $root): ?string
    {
        return self::webPhpInfo($root)['version'];
    }

    /**
     * The PHP versions the web server runs the site with, and where each was read, first found wins:
     * 1. an AddHandler line in .htaccess (LiteSpeed, cPanel: it decides the PHP on those servers);
     * 2. Plesk: every vhost whose DocumentRoot (httpd.conf, else nginx.conf) is the install root (same directory,
     *    whatever the path: doc/ is often a link to public_html), and the PHP of its PHP-FPM pool
     *    (/opt/plesk/php/<ver>/etc/php-fpm.d/<domain>.conf), else the Plesk PHP its configuration names;
     * 3. Velocity: an enabled site file whose host root is the install root and whose name carries the PHP (vcc);
     * 4. .php-version.
     * One glob and a string match per vhost; the scans are cached per root for the run.
     * version is the lowest found (the PHP every vhost of the site can run); versions lists them all with the vhosts.
     * @return array{version: ?string, versions: array<string, list<string>>, source: string}
     */
    public static function webPhpInfo(string $root): array
    {
        $found = static function (array $versions, string $source): array {
            uksort($versions, 'version_compare');
            return ['version' => $versions === [] ? null : (string) array_key_first($versions), 'versions' => $versions, 'source' => $source];
        };
        $res = $found([], '');
        $ht = $root . '/.htaccess';
        if (is_file($ht) && preg_match('/^\s*AddHandler\s+application\/x-httpd-(?:ea-php|lsphp|alt-php|php)(\d)(\d+)/mi', (string) file_get_contents($ht), $m)) {
            $res = $found([$m[1] . '.' . $m[2] => ['.htaccess']], 'an AddHandler line in .htaccess');
        } else {
            // the panel scans are the cost: once per root (and paths) for the run
            $key = $root . "\0" . json_encode(self::$webPaths);
            if (!isset(self::$webCache[$key])) {
                self::$webCache[$key] = ['Plesk' => self::pleskPhps($root), 'Velocity' => self::velocityPhps($root)];
            }
            foreach (self::$webCache[$key] as $src => $list) {
                if ($list !== []) {
                    return $found($list, $src);
                }
            }

            $pv = $root . '/.php-version';
            if (is_file($pv) && preg_match('/^\s*(\d+)\.(\d+)/', (string) file_get_contents($pv), $m)) {
                $res = $found([$m[1] . '.' . $m[2] => ['.php-version']], '.php-version');
            }
        }
        return $res;
    }

    /** "dev:inode" of a directory (symlinks followed), or null when it is not one. */
    private static function dirId(string $path): ?string
    {
        $s = @stat($path);
        return $s === false || !is_dir($path) ? null : $s['dev'] . ':' . $s['ino'];
    }

    /**
     * Plesk vhosts that serve $root, by PHP version: ["8.5" => ["doc.example.com (PHP-FPM pool)"]].
     * @return array<string, list<string>>
     */
    public static function pleskPhps(string $root): array
    {
        $system = self::$webPaths['plesk_system'];
        $phpDir = self::$webPaths['plesk_php'];
        $id = self::dirId($root);
        if ($id === null || !is_dir($system)) {
            return [];
        }
        $out = [];
        foreach (glob($system . '/*/conf', GLOB_ONLYDIR) ?: [] as $conf) {
            $domain = basename(dirname($conf));
            $text = '';
            $roots = [];
            foreach (['httpd.conf' => '/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', 'nginx.conf' => '/^\s*root\s+"?([^";\s]+)"?\s*;/mi'] as $f => $re) {
                $t = is_file("$conf/$f") ? (string) @file_get_contents("$conf/$f") : '';
                if ($t !== '' && preg_match_all($re, $t, $m)) {
                    $text = $t;
                    $roots = array_unique($m[1]);
                    break;
                }
            }
            $match = false;
            foreach ($roots as $d) {
                if (rtrim($d, '/') === rtrim($root, '/') || self::dirId($d) === $id) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
            $ver = null;
            $how = '';
            foreach (glob($phpDir . '/*/etc/php-fpm.d/' . $domain . '.conf') ?: [] as $pool) {
                $v = basename(dirname($pool, 3));
                if (preg_match('/^\d+\.\d+$/', $v) && ($ver === null || version_compare($v, $ver, '>'))) {
                    $ver = $v;
                    $how = 'PHP-FPM pool ' . $pool;
                }
            }
            if ($ver === null && preg_match('#' . preg_quote($phpDir, '#') . '/(\d+\.\d+)/#', $text, $m)) {
                $ver = $m[1];
                $how = 'Plesk PHP named in its configuration';
            }
            if ($ver !== null) {
                $out[$ver][] = "$domain ($how)";
            }
        }
        return $out;
    }

    /**
     * Velocity sites that serve $root, by the PHP their file name carries (vcc-<user>-php85-<hash>.conf).
     * @return array<string, list<string>>
     */
    public static function velocityPhps(string $root): array
    {
        $id = self::dirId($root);
        $out = [];
        if ($id === null) {
            return [];
        }
        foreach (self::$webPaths['velocity'] as $dir) {
            foreach (glob($dir . '/*.conf') ?: [] as $f) {
                if (!preg_match('/-php(\d)(\d+)-/', basename($f), $m)) {
                    continue;
                }
                // a string match, not a JSON decode: a site file holds every rewrite rule and can be megabytes
                preg_match_all('/"([^"]+)"\s*:\s*\{\s*"root"\s*:\s*"([^"]+)"/', (string) @file_get_contents($f), $hosts, PREG_SET_ORDER);
                foreach ($hosts as $h) {
                    $host = $h[1];
                    $r = str_replace('\\/', '/', $h[2]);
                    if (rtrim($r, '/') === rtrim($root, '/') || self::dirId($r) === $id) {
                        $out[$m[1] . '.' . $m[2]][] = "$host (Velocity " . basename($f) . ')';
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The PHP constraints of an install's packages: composer.json require.php and require.php of every package its
     * composer.lock installs (not the dev ones). name => constraint.
     * @return array<string,string>
     */
    public static function lockPhpConstraints(?string $tree): array
    {
        $out = [];
        if ($tree === null) {
            return $out;
        }
        foreach (['composer.json', 'composer.lock'] as $f) {
            $j = is_file("$tree/$f") ? json_decode((string) file_get_contents("$tree/$f"), true) : null;
            if (!is_array($j)) {
                continue;
            }
            if ($f === 'composer.json' && isset($j['require']['php'])) {
                $out['composer.json'] = (string) $j['require']['php'];
            }
            foreach ((array) ($j['packages'] ?? []) as $p) {
                if (is_array($p) && isset($p['name'], $p['require']['php'])) {
                    $out[(string) $p['name']] = (string) $p['require']['php'];
                }
            }
        }
        return $out;
    }

    /** "8.5" -> "8.5.0", "8.6.0-dev" -> "8.6.0", "v1" -> "1.0.0" (three numeric positions). */
    private static function norm(string $v): ?string
    {
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.\d+)?(?:[-@].*)?$/i', trim($v), $m)) {
            return null;
        }
        return (int) $m[1] . '.' . (int) ($m[2] ?? 0) . '.' . (int) ($m[3] ?? 0);
    }

    /**
     * Whether Composer's constraint $c allows PHP $v ("8.6.0"): ||, |, AND by space or comma, >= > <= < = == != <>,
     * ^, ~, 8.* and "A - B". null when the constraint has a form this does not read (then it decides nothing).
     */
    public static function constraintAllows(string $c, string $v): ?bool
    {
        $v = self::norm($v);
        $c = trim(preg_replace('/(>=|<=|!=|==|<>|>|<|=|\^|~)\s+/', '$1', $c) ?? '');
        if ($v === null) {
            return null;
        }
        if ($c === '' || $c === '*') {
            return true;
        }
        $unknown = false;
        foreach (preg_split('/\s*\|\|?\s*/', $c) ?: [] as $or) {
            $or = trim($or);
            if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $or, $m)) {
                $lo = self::norm($m[1]);
                $hi = self::norm($m[2]);
                if ($lo === null || $hi === null) {
                    $unknown = true;
                    continue;
                }
                $parts = count(explode('.', preg_replace('/[-@].*$/', '', ltrim($m[2], 'v'))));
                $hiOk = $parts >= 3 ? version_compare($v, $hi, '<=') : version_compare($v, self::bump($hi, $parts - 1), '<');
                if (version_compare($v, $lo, '>=') && $hiOk) {
                    return true;
                }
                continue;
            }
            $all = true;
            foreach (preg_split('/\s*,\s*|\s+/', $or) ?: [] as $atom) {
                $r = self::atomAllows($atom, $v);
                if ($r === null) {
                    $unknown = true;
                    $all = false;
                    break;
                }
                if (!$r) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return $unknown ? null : false;
    }

    /** "8.5.0" with position $pos (0 major, 1 minor, 2 patch) raised by one and the ones below it zeroed. */
    private static function bump(string $v, int $pos): string
    {
        $p = array_map('intval', explode('.', $v));
        $p[$pos]++;
        for ($i = $pos + 1; $i < 3; $i++) {
            $p[$i] = 0;
        }
        return implode('.', $p);
    }

    private static function atomAllows(string $a, string $v): ?bool
    {
        $a = preg_replace('/@\w+$/', '', trim($a)) ?? '';
        if ($a === '' || $a === '*' || strtolower($a) === 'x') {
            return true;
        }
        if (preg_match('/^v?(\d+)(?:\.(\d+))?\.[*x]$/i', $a, $m)) {
            $lo = $m[1] . '.' . ($m[2] ?? '0') . '.0';
            return version_compare($v, $lo, '>=') && version_compare($v, self::bump($lo, isset($m[2]) ? 1 : 0), '<');
        }
        if (!preg_match('/^(>=|<=|!=|==|<>|>|<|=|\^|~)?(.+)$/', $a, $m) || ($n = self::norm($m[2])) === null) {
            return null;
        }
        $parts = count(explode('.', preg_replace('/[-@].*$/', '', ltrim($m[2], 'vV'))));
        switch ($m[1]) {
            case '>=':
                return version_compare($v, $n, '>=');
            case '>':
                return version_compare($v, $n, '>');
            case '<=':
                return version_compare($v, $n, '<=');
            case '<':
                return version_compare($v, $n, '<');
            case '!=':
            case '<>':
                return version_compare($v, $n, '!=');
            case '^':
                $p = explode('.', $n);
                $pos = (int) $p[0] > 0 ? 0 : ((int) $p[1] > 0 || $parts < 3 ? 1 : 2);
                return version_compare($v, $n, '>=') && version_compare($v, self::bump($n, $pos), '<');
            case '~':
                return version_compare($v, $n, '>=') && version_compare($v, self::bump($n, $parts >= 3 ? 1 : 0), '<');
            default:
                // a bare or "=" version: that exact version, as Composer reads it ("8.1" is 8.1.0)
                return version_compare($v, $n, '==');
        }
    }

    /** The package whose PHP constraint rules PHP_VERSION_ID $id out (name: constraint), or null. @param array<string,string> $constraints */
    public static function excludedBy(array $constraints, int $id): ?string
    {
        $v = intdiv($id, 10000) . '.' . intdiv($id % 10000, 100) . '.' . ($id % 100);
        foreach ($constraints as $name => $c) {
            if (self::constraintAllows($c, $v) === false) {
                return "$name requires php $c";
            }
        }
        return null;
    }

    /** True for a PHP a hosting panel builds and maintains (Plesk, LiteSpeed, cPanel, CloudLinux). */
    public static function isPanelPhp(string $b): bool
    {
        return (bool) preg_match('#^(/opt/plesk/php/|/usr/local/lsws/|/opt/cpanel/|/opt/alt/)#', str_replace('\\', '/', $b));
    }

    /**
     * The installed PHPs in the order of the fallback: the panel's own PHPs first, newest first, then the others
     * (/usr/bin/phpNN, Windows), newest first; when the node has a panel PHP, another PHP newer than the newest panel
     * one is left out (a distribution's dev or preview build such as /usr/bin/php86 is not what the sites run).
     * @param list<string> $installed @param array<string,int|null> $versions
     * @return list<string>
     */
    public static function rankInstalled(array $installed, array $versions): array
    {
        $panelMax = 0;
        foreach ($installed as $b) {
            if (self::isPanelPhp($b) && ($versions[$b] ?? null) !== null) {
                $panelMax = max($panelMax, intdiv((int) $versions[$b], 100));
            }
        }
        $list = array_values(array_filter($installed, static function ($b) use ($versions, $panelMax) {
            return self::isPanelPhp($b) || $panelMax === 0 || intdiv((int) ($versions[$b] ?? 0), 100) <= $panelMax;
        }));
        usort($list, static function ($a, $b) use ($versions) {
            return [self::isPanelPhp($b), $versions[$b] ?? 0] <=> [self::isPanelPhp($a), $versions[$a] ?? 0];
        });
        return $list;
    }

    /** PHP binaries of the usual panels and distributions on this node (Plesk, LiteSpeed, cPanel, CloudLinux, Debian). */
    public static function installedPhps(): array
    {
        $list = [];
        $globs = ['/opt/plesk/php/*/bin/php', '/usr/local/lsws/lsphp*/bin/php', '/opt/cpanel/ea-php*/root/usr/bin/php', '/opt/alt/php*/usr/bin/php', '/usr/bin/php[0-9]*'];
        if (DIRECTORY_SEPARATOR === '\\') {
            // XAMPP, WampServer, Laragon, the PHP installer of windows.php.net and loose zips under C:\php or C:\tools
            $pf = (string) (getenv('ProgramFiles') ?: 'C:\\Program Files');
            $globs = ['C:/xampp/php/php.exe', 'C:/wamp64/bin/php/php*/php.exe', 'C:/wamp/bin/php/php*/php.exe',
                'C:/laragon/bin/php/php*/php.exe', str_replace('\\', '/', $pf) . '/PHP/*/php.exe', 'C:/php*/php.exe', 'C:/php/*/php.exe', 'C:/tools/php*/php.exe'];
        }
        foreach ($globs as $g) {
            foreach (glob($g) ?: [] as $f) {
                if (is_file($f) && is_executable($f)) {
                    $list[] = $f;
                }
            }
        }
        return array_values(array_unique($list));
    }

    /** PHP_VERSION_ID of a PHP binary, or null when it does not run. */
    public static function binaryVersion(string $php): ?int
    {
        if (realpath($php) !== false && realpath($php) === realpath(PHP_BINARY)) {
            return PHP_VERSION_ID;
        }
        $r = Proc::run([$php, '-n', '-r', 'echo PHP_VERSION_ID;'], null, null, 30);
        return $r['exit'] === 0 && preg_match('/^\d{5,6}$/', trim($r['out'])) ? (int) trim($r['out']) : null;
    }

    /**
     * The choice itself, without running anything (tested on its own).
     * @param array<string,int|null> $versions binary => PHP_VERSION_ID (null: does not run)
     * @param string|null $setting the php setting (--php), '' or null when none
     * @param list<string> $order the binaries in the order of preference after the web PHP (this PHP, PATH, installed)
     * @param array<string,string> $constraints package => require.php of the install's composer.json and composer.lock
     *        (lockPhpConstraints()): a PHP one of them rules out is skipped; never applied to the php setting
     * @param string $webSource where the web PHP was read (webPhpInfo()), for the reason
     * @return array{binary: ?string, version: ?int, needs: int, web: ?string, reason: string, skipped: array<string,string>}
     */
    public static function choosePhp(array $versions, ?string $setting, ?string $web, array $order, ?int $min, array $constraints = [], string $webSource = ''): array
    {
        $needs = max(80100, (int) $min);
        $v = static function (int $id): string {
            return intdiv($id, 10000) . '.' . intdiv($id % 10000, 100) . '.' . ($id % 100);
        };
        $skipped = [];
        $ok = static function (?string $b, bool $lock = true) use ($versions, $needs, $constraints, $v, &$skipped): bool {
            if ($b === null || !isset($versions[$b]) || $versions[$b] === null) {
                return false;
            }
            if ($versions[$b] < $needs) {
                $skipped[$b] = 'PHP ' . $v((int) $versions[$b]) . ' is below ' . $v($needs);
                return false;
            }
            $ex = $lock ? self::excludedBy($constraints, (int) $versions[$b]) : null;
            if ($ex !== null) {
                $skipped[$b] = 'PHP ' . $v((int) $versions[$b]) . ': ' . $ex;
                return false;
            }
            return true;
        };
        $res = ['binary' => null, 'version' => null, 'needs' => $needs, 'web' => $web, 'reason' => ''];
        if ($setting !== null && $setting !== '') {
            if ($ok($setting, false)) {
                return ['binary' => $setting, 'version' => $versions[$setting], 'reason' => 'the php setting (--php)', 'skipped' => []] + $res;
            }
            $res['reason'] = "the php setting $setting " . (($versions[$setting] ?? null) === null ? 'does not run' : 'is PHP ' . $v((int) $versions[$setting])) . ', the install needs PHP ' . $v($needs) . ' or newer (vendor/composer/platform_check.php)';
            return $res + ['skipped' => []];
        }
        if ($web !== null) {
            foreach ($order as $b) {
                if (preg_match('/^(\d+)\.(\d+)$/', $web, $m) && isset($versions[$b]) && intdiv((int) $versions[$b], 100) === (int) $m[1] * 100 + (int) $m[2] && $ok($b)) {
                    return ['binary' => $b, 'version' => $versions[$b], 'reason' => "the web server's PHP $web" . ($webSource !== '' ? " ($webSource)" : ''), 'skipped' => $skipped] + $res;
                }
            }
        }
        foreach ($order as $b) {
            if ($ok($b)) {
                return ['binary' => $b, 'version' => $versions[$b], 'reason' => ($web !== null ? "no binary of the web server's PHP $web will do; " : "the web server's PHP is unknown; ")
                    . 'the first PHP that meets ' . $v($needs) . ($constraints !== [] ? ' and the PHP constraints of composer.lock' : '') . ' (this PHP, php on PATH, then the panel PHPs newest first, then the others)', 'skipped' => $skipped] + $res;
            }
        }
        $res['reason'] = 'no PHP on this node meets PHP ' . $v($needs) . ' (vendor/composer/platform_check.php of the install, or the kernel minimum)'
            . ($constraints !== [] ? ' and the PHP constraints of composer.lock' : '') . '; name one with --php';
        return $res + ['skipped' => $skipped];
    }

    /**
     * Runs the choice on this node: the platform minimum of the install (and of the target tree, when given), the
     * web server's PHP (webPhpInfo(); a site several vhosts serve with different PHPs: the lowest that meets the
     * minimum), the PHP constraints of the install's (and the target's) composer.lock, and the binaries in order:
     * this PHP, php on PATH, then the installed ones (rankInstalled(): panel PHPs first, newest first).
     * @return array{binary: ?string, version: ?int, needs: int, web: ?string, reason: string, skipped: array<string,string>, web_from: array<string, list<string>>}
     */
    public static function selectPhp(Config $cfg, string $root, ?string $targetTree = null): array
    {
        $min = max((int) self::platformMinimum($root), (int) self::platformMinimum($targetTree));
        $setting = $cfg->get('php');
        $info = self::webPhpInfo($root);
        $web = $info['version'];
        foreach (array_keys($info['versions']) as $wv) {
            if (preg_match('/^(\d+)\.(\d+)$/', (string) $wv, $m) && (int) $m[1] * 100 + (int) $m[2] >= intdiv(max(80100, $min), 100)) {
                $web = (string) $wv;
                break;
            }
        }
        $webSource = $info['source'] === '' ? '' : $info['source'] . ($web !== null && isset($info['versions'][$web]) ? ': ' . implode(', ', $info['versions'][$web]) : '');
        $constraints = array_merge(self::lockPhpConstraints($root), self::lockPhpConstraints($targetTree));
        $first = [PHP_BINARY];
        $w = Proc::which('php');
        if ($w !== null) {
            $first[] = $w;
        }
        $installed = self::installedPhps();
        // probing a binary costs a process: the setting, the web PHP's own binaries (by their path: 8.4, 84) and
        // this PHP first; every other installed PHP only when none of those will do
        $webBins = $web === null ? [] : array_values(array_filter($installed, static function ($b) use ($web) {
            return strpos($b, $web) !== false || strpos($b, str_replace('.', '', $web)) !== false;
        }));
        $versions = [];
        $probe = static function (array $list) use (&$versions): void {
            foreach ($list as $b) {
                if (!array_key_exists($b, $versions)) {
                    $versions[$b] = self::binaryVersion($b);
                }
            }
        };
        $probe(array_merge($setting !== '' ? [$setting] : [], $webBins, $first));
        $c = self::choosePhp($versions, $setting, $web, array_values(array_unique(array_merge($webBins, $first))), $min ?: null, $constraints, $webSource);
        // settled: a --php is never replaced; a found PHP is kept unless the web PHP's binary was not recognised by
        // its path (then all installed ones are probed, so the web PHP can still be the one)
        $webUnseen = $web !== null && $webBins === [];
        if ($setting === '' && ($c['binary'] === null || $webUnseen)) {
            $probe($installed);
            $c = self::choosePhp($versions, $setting, $web, array_values(array_unique(array_merge($first, self::rankInstalled($installed, $versions)))), $min ?: null, $constraints, $webSource);
        }
        return $c + ['web_from' => $info['versions']];
    }

    /** True when the tools run as root (xne as root and the install owned by root, or run_as=root). */
    public function asRoot(): bool
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return false;
        }
        $ra = $this->cfg->get('run_as');
        if ($ra === 'root') {
            return true;
        }
        if ($ra !== 'auto' && $ra !== '') {
            return false;
        }
        return $this->owner['uid'] === 0;
    }

    /**
     * The argv that runs $script (relative to the root) with $args.
     * @param list<string> $args
     * @return list<string>
     */
    public function argv(string $script, array $args, bool $ezscript = true): array
    {
        $argv = array_merge([$this->php(), $script], $args);
        if ($this->asRoot()) {
            if ($ezscript) {
                $argv[] = '--allow-root-user';
            }
            return $argv;
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $user = $this->cfg->get('run_as');
            if ($user === 'auto' || $user === '') {
                $user = $this->owner['user'];
            }
            $ru = Proc::which('runuser');
            if ($ru === null) {
                throw XneException::refused("xne runs as root but cannot run the install's tools as $user (no runuser); set run_as=root or run xne as $user");
            }
            return array_merge([$ru, '-u', $user, '--'], $argv);
        }
        return $argv;
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, out: string, err: string, ms: int, timeout: bool}
     */
    public function run(string $script, array $args, bool $ezscript = true, ?int $timeout = null): array
    {
        if (!is_file($this->root . '/' . $script)) {
            throw new XneException("the install has no $script");
        }
        $argv = $this->argv($script, $args, $ezscript);
        $r = Proc::run($argv, $this->root, null, $timeout ?? $this->cfg->int('timeout'));
        $this->runs[] = ['command' => implode(' ', array_slice($argv, array_search($script, $argv, true))), 'exit' => $r['exit'], 'ms' => $r['ms']];
        return $r;
    }

    public function has(string $script): bool
    {
        return is_file($this->root . '/' . $script);
    }

    /** Targeted cache clears, one tag per call (a known trap: a comma list is not honoured everywhere). */
    public function clearCaches(array $tags): array
    {
        $done = [];
        foreach ($tags as $t) {
            $r = $this->run('bin/php/ezcache.php', ['--clear-tag=' . $t]);
            if ($r['exit'] !== 0) {
                throw new XneException("ezcache.php --clear-tag=$t failed: " . self::tail($r));
            }
            $done[] = $t;
        }
        return $done;
    }

    /** The extension autoloads (ezpgenerateautoloads.php -e): kernel autoloads ship with the release itself. */
    public function autoloads(): void
    {
        $r = $this->run('bin/php/ezpgenerateautoloads.php', ['-e'], false);
        if ($r['exit'] !== 0) {
            throw new XneException('ezpgenerateautoloads.php -e failed: ' . self::tail($r));
        }
    }

    /**
     * exp:upgrade for the stages given, applied, as JSON. Captions are xne's verified backup (the kernel's own caption
     * would double the time), so --no-caption --force-no-caption; the deploy and the verification are xne's finish
     * and health steps. @return array<string,mixed> the exp-upgrade/1 report
     */
    public function upgrade(array $stages, string $toSha, array $update = [], array $extra = []): array
    {
        $args = ['--apply', '--stages=' . implode(',', $stages), '--no-deploy', '--no-verify', '--no-tests', '--format=json', '--no-caption', '--force-no-caption'];
        foreach ($extra as $a) {
            // the code stage's answer after a file patch (codeStageArgs()): --code-applied=<sha> or --force-order
            $args[] = $a;
        }
        if ($update !== []) {
            // the libraries stage's composer update of these packages, with their dependencies
            $args[] = '--update=' . implode(',', $update);
        }
        if (in_array('libraries', $stages, true)) {
            // a phar runs under the kernel's own PHP (the one php() chose); "composer" on the PATH is often a
            // wrapper whose phar starts with "env php", the node's CLI PHP (db: 8.0), and resolves another set
            $phar = self::composerPhar($this->cfg->get('composer'), $this->root);
            if ($phar !== null) {
                $args[] = '--composer=' . $phar;
            }
        }
        if ($toSha !== '') {
            $args[] = '--to=' . $toSha;
        }
        $r = $this->run('bin/php/upgrade.php', $args);
        $j = self::json($r['out']);
        if ($r['exit'] !== 0) {
            $why = '';
            if ($j !== null) {
                foreach ((array) ($j['errors'] ?? []) as $e) {
                    $why .= ' ' . (is_string($e) ? $e : json_encode($e));
                }
                foreach ((array) ($j['stages'] ?? []) as $st) {
                    foreach ((array) ($st['steps'] ?? []) as $step) {
                        if (($step['state'] ?? '') === 'failed') {
                            $why .= ' ' . ($step['id'] ?? '?') . ': ' . implode(' ', array_map('strval', (array) ($step['messages'] ?? [])));
                        }
                    }
                }
            }
            throw new XneException('exp:upgrade failed (exit ' . $r['exit'] . ')' . ($why !== '' ? ':' . $why : ': ' . self::tail($r)));
        }
        return $j ?? ['raw' => substr($r['out'], -2000)];
    }

    /**
     * The Composer phar for the libraries stage: the composer setting, else composer.phar on the PATH, in
     * /usr/local/bin or /usr/bin, the install's own composer.phar, else "composer" on the PATH when it is a phar
     * itself. A path that does not end in .phar is given through a .phar link only (the kernel runs a .phar with its
     * own PHP, anything else as a program). null: let the kernel look (it finds composer on the PATH).
     */
    public static function composerPhar(string $setting, string $root, ?array $path = null): ?string
    {
        if ($setting !== '') {
            return $setting;
        }
        $dirs = $path ?? array_values(array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')), 'strlen'));
        $cands = [];
        foreach (array_merge($dirs, $path === null ? ['/usr/local/bin', '/usr/bin'] : []) as $d) {
            $cands[] = rtrim($d, '/') . '/composer.phar';
        }
        $cands[] = $root . '/composer.phar';
        foreach ($cands as $c) {
            if (is_file($c) && self::isPhar($c)) {
                return $c;
            }
        }
        foreach ($dirs as $d) {
            $c = rtrim($d, '/') . '/composer';
            if (is_file($c) && self::isPhar($c) && substr((string) realpath($c), -5) === '.phar') {
                return (string) realpath($c);
            }
        }
        return null;
    }

    /** A PHP archive (its stub ends in __HALT_COMPILER), not a shell wrapper. */
    private static function isPhar(string $f): bool
    {
        $h = @fopen($f, 'rb');
        if ($h === false) {
            return false;
        }
        $head = (string) fread($h, 65536);
        fclose($h);
        return strpos($head, '__HALT_COMPILER') !== false;
    }

    /** The kernel stages in their fixed order (expUpgradeRegistry::STAGES). */
    public const STAGES = ['code', 'libraries', 'database', 'scripts', 'finish'];

    /** The code step of the kernel's code stage: moves the git checkout, the one step a file patch does itself. */
    public const CODE_STEP = '6.0.15-code-update';

    /**
     * Whether the install's exp:upgrade knows --code-applied (the kernel feature of 2026-11-27): its command contract
     * (kernel/private/classes/commands/upgradebase.php, the options every exp:upgrade command takes) names it.
     */
    public static function supportsCodeApplied(string $root): bool
    {
        $f = $root . '/kernel/private/classes/commands/upgradebase.php';
        return is_file($f) && strpos((string) file_get_contents($f), '[code-applied:]') !== false;
    }

    /**
     * The exp:upgrade arguments that tell the kernel the code is in place after xne's file patch, for a run of
     * $stages. A git checkout keeps its commits under a file patch, so the kernel's code step (which moves the
     * checkout) stays pending, and exp:upgrade refuses a later stage while an earlier one has pending steps.
     *  - a kernel with --code-applied: --code-applied=<sha> (the code stage counts as in place, nothing else changes);
     *  - an older kernel: --force-order, only when the code step is the one pending step of the stages before the
     *    run's first (exp:upgrade:status says so); any other pending step keeps the kernel's refusal (no arguments).
     * $pending: stage => list of pending or failed step ids of those earlier stages (pendingSteps()), null when
     * unknown. @param list<string> $stages @param array<string,list<string>>|null $pending
     * @return array{args: list<string>, note: string}
     */
    public static function codeStageArgs(bool $codeApplied, array $stages, string $sha, ?array $pending): array
    {
        if ($codeApplied) {
            return ['args' => ['--code-applied=' . $sha], 'note' => 'code stage: in place by the file patch (--code-applied=' . substr($sha, 0, 12) . ')'];
        }
        $earlier = self::earlierStages($stages);
        if ($earlier === [] || $pending === null) {
            return ['args' => [], 'note' => ''];
        }
        $ids = [];
        foreach ($earlier as $st) {
            foreach ($pending[$st] ?? [] as $id) {
                $ids[] = $st . ':' . $id;
            }
        }
        if ($ids === ['code:' . self::CODE_STEP]) {
            return ['args' => ['--force-order'], 'note' => 'code stage: in place by the file patch; this kernel has no --code-applied, so --force-order (the code step was the only pending step before ' . $stages[0] . ')'];
        }
        return ['args' => [], 'note' => ''];
    }

    /** @param list<string> $stages @return list<string> the kernel stages before the first of $stages */
    public static function earlierStages(array $stages): array
    {
        $out = [];
        foreach (self::STAGES as $st) {
            if (in_array($st, $stages, true)) {
                break;
            }
            $out[] = $st;
        }
        return $out;
    }

    /**
     * The pending or failed step ids of each stage asked for, from exp:upgrade:status (null: no status command or an
     * unreadable answer). @param list<string> $stages @return array<string,list<string>>|null
     */
    public function pendingSteps(array $stages): ?array
    {
        if ($stages === [] || !$this->has('bin/php/upgradestatus.php')) {
            return $stages === [] ? [] : null;
        }
        $r = $this->run('bin/php/upgradestatus.php', ['--stages=' . implode(',', $stages), '--format=json']);
        $j = self::json($r['out']);
        return $j === null ? null : self::pendingStepsOf($j);
    }

    /** @param array<string,mixed> $status an exp-upgrade/1 report @return array<string,list<string>> stage => step ids */
    public static function pendingStepsOf(array $status): array
    {
        $out = [];
        foreach ((array) ($status['stages'] ?? []) as $st) {
            $name = (string) ($st['stage'] ?? $st['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = $out[$name] ?? [];
            foreach ((array) ($st['steps'] ?? []) as $step) {
                if (in_array($step['state'] ?? '', ['pending', 'failed'], true)) {
                    $out[$name][] = (string) ($step['id'] ?? '?');
                }
            }
        }
        return $out;
    }

    /** exp:upgrade:status as JSON, or null when the install has no upgrade command. */
    public function upgradeStatus(): ?array
    {
        if (!$this->has('bin/php/upgradestatus.php')) {
            return null;
        }
        $r = $this->run('bin/php/upgradestatus.php', ['--format=json']);
        return self::json($r['out']);
    }

    /**
     * The pending step count of each stage asked for, from exp:upgrade:status --stages=... (the kernel counts a failed
     * step as pending too), or null when the install has no status command or its answer is not readable.
     * @param list<string> $stages @return array<string,int>|null
     */
    public function pendingStages(array $stages): ?array
    {
        if (!$this->has('bin/php/upgradestatus.php')) {
            return null;
        }
        $r = $this->run('bin/php/upgradestatus.php', ['--stages=' . implode(',', $stages), '--format=json']);
        $j = self::json($r['out']);
        return $j === null ? null : self::pendingOf($j);
    }

    /** @param array<string,mixed> $status an exp-upgrade/1 report @return array<string,int> stage => pending steps */
    public static function pendingOf(array $status): array
    {
        $out = [];
        foreach ((array) ($status['stages'] ?? []) as $st) {
            $name = (string) ($st['stage'] ?? $st['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $n = isset($st['pending']) ? (int) $st['pending'] : 0;
            if (!isset($st['pending'])) {
                foreach ((array) ($st['steps'] ?? []) as $step) {
                    if (in_array($step['state'] ?? '', ['pending', 'failed'], true)) {
                        $n++;
                    }
                }
            }
            $out[$name] = ($out[$name] ?? 0) + $n;
        }
        return $out;
    }

    public function maintenance(string $onOff, string $until = '30m'): bool
    {
        if (!$this->has('bin/php/maintenance.php')) {
            return false;
        }
        $args = $onOff === 'on' ? ['on', '--allow-admin', '--until=' . $until, '--message=An upgrade is in progress.'] : ['off'];
        $r = $this->run('bin/php/maintenance.php', $args);
        if ($r['exit'] !== 0) {
            throw new XneException("maintenance.php $onOff failed: " . self::tail($r));
        }
        return true;
    }

    /** exp:velocity deploy (FPM reload, Velocity restart), only when the install asks for it with finish=velocity. */
    public function velocityDeploy(): void
    {
        $r = $this->run('bin/php/velocity.php', ['deploy']);
        if ($r['exit'] !== 0) {
            throw new XneException('exp:velocity deploy failed: ' . self::tail($r));
        }
    }

    /** A CLI bootstrap of the install (settings, autoloads, kernel): ezcache.php --list-tags must answer. */
    public function bootstrapCheck(): void
    {
        $r = $this->run('bin/php/ezcache.php', ['--list-tags']);
        if ($r['exit'] !== 0 || stripos($r['out'] . $r['err'], 'fatal error') !== false) {
            throw new XneException('the install does not start from the CLI after the upgrade: ' . self::tail($r));
        }
    }

    /** @return array<string,mixed>|null the first JSON object in $out */
    public static function json(string $out): ?array
    {
        $p = strpos($out, '{');
        if ($p === false) {
            return null;
        }
        $d = json_decode(substr($out, $p), true);
        if (is_array($d)) {
            return $d;
        }
        $e = strrpos($out, '}');
        $d = $e === false ? null : json_decode(substr($out, $p, $e - $p + 1), true);
        return is_array($d) ? $d : null;
    }

    /** @param array{out: string, err: string} $r */
    public static function tail(array $r): string
    {
        $t = trim($r['err'] !== '' ? $r['err'] : $r['out']);
        $lines = array_slice(preg_split('/\r?\n/', $t), -6);
        return trim(implode(' | ', $lines));
    }
}
