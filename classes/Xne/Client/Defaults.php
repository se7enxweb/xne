<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Db\Factory;
use Xne\Fs;
use Xne\Ini;
use Xne\IniEdit;
use Xne\XneException;

/**
 * xne defaults: an older install's settings brought to what a fresh install of the same kernel has, without losing
 * the site's own. The reference is a fresh install (--reference=<its root>), made by the kernel's exp:install from the
 * same release: a fresh install's extension list comes from its site package and the kernel's own defaults
 * (expInstallDefaultExtensions), so a real one is the only complete list.
 *
 * - settings/override/site.ini: ActiveExtensions[] keeps the site's list and order; the reference's extensions that
 *   are in the install's extension/ folder and missing are added after them (an extension the install does not have,
 *   such as the reference site's own, is never added).
 * - every administration siteaccess of the install (its SiteDesign is an admin design, or a design that does not exist
 *   with admin designs after it): the reference admin's design (admin4l, then admin4 ... admin) and its access
 *   extensions (exp_adminui, sqliimport, expai ...) that the install has and does not run globally.
 * - [FileSettings] AllowedDeletionDirs[]: the real folder of var/ when var/ is a link out of the install (a capture's
 *   arc/ tree); without it the kernel refuses every cache delete there ("outside eZ Publish root").
 *
 * Apply: a verified backup of the settings files and the database, the files written (owner and mode kept), then the
 * kernel's extension autoloads, its exp:upgrade database stage (the tables of the newly active extensions:
 * expUpgradeStepActiveExtensionTables), the cache clears and the CLI bootstrap check. Any failure rolls back.
 */
final class Defaults
{
    public const ADMIN_DESIGNS = ['admin', 'admin2', 'admin3', 'admin4', 'admin4l'];

    /** @return array<string,mixed> the plan: changes per settings file, and what they mean */
    public static function plan(string $root, string $reference): array
    {
        if (!is_file($reference . '/lib/version.php') || !is_dir($reference . '/settings')) {
            throw XneException::usage("--reference: $reference is no Exponential install");
        }
        $warnings = [];
        $v = \Xne\Version::fromTree($root);
        $rv = \Xne\Version::fromTree($reference);
        if ($v !== $rv) {
            $warnings[] = "the reference is $rv, the install $v: a fresh install of the install's own version is the right reference";
        }
        $files = [];
        $summary = ['extensions_added' => [], 'admin_siteaccesses' => [], 'allowed_deletion_dirs' => []];

        // global extensions
        $over = 'settings/override/site.ini.append.php';
        $mine = self::global($root);
        $theirs = self::global($reference);
        $list = array_values(array_map('strval', (array) $mine->get('ExtensionSettings', 'ActiveExtensions', [])));
        $add = [];
        foreach ((array) $theirs->get('ExtensionSettings', 'ActiveExtensions', []) as $e) {
            $e = (string) $e;
            if ($e !== '' && !in_array($e, $list, true) && !in_array($e, $add, true) && Fs::safeRel($e) && is_dir($root . '/extension/' . $e)) {
                $add[] = $e;
            }
        }
        $global = array_merge(self::unique($list), $add);
        if ($add) {
            $files[$over][] = ['section' => 'ExtensionSettings', 'key' => 'ActiveExtensions', 'array' => $global];
            $summary['extensions_added'] = $add;
        }

        // var/ out of the install
        $var = $root . '/var';
        $real = is_link($var) ? realpath($var) : false;
        if ($real !== false && !Fs::inside($root, $real)) {
            $allowed = array_values(array_map('strval', (array) $mine->get('FileSettings', 'AllowedDeletionDirs', [])));
            if (!in_array($real, $allowed, true) && !in_array($real . '/', $allowed, true)) {
                $files[$over][] = ['section' => 'FileSettings', 'key' => 'AllowedDeletionDirs', 'array' => array_merge($allowed, [$real])];
                $summary['allowed_deletion_dirs'] = [$real];
            }
        }

        // the administration siteaccesses
        $ref = self::referenceAdmin($reference);
        if ($ref === null) {
            $warnings[] = 'the reference has no administration siteaccess on admin4l: admin designs and access extensions are left as they are';
        } else {
            foreach (self::siteaccesses($root, $mine) as $sa) {
                $file = 'settings/siteaccess/' . $sa . '/site.ini.append.php';
                $ini = Ini::load([$root . '/' . $file]);
                if (!self::isAdmin($root, $ini)) {
                    continue;
                }
                $ops = [];
                if ((string) $ini->get('DesignSettings', 'SiteDesign', '') !== $ref['design']) {
                    $ops[] = ['section' => 'DesignSettings', 'key' => 'SiteDesign', 'value' => $ref['design']];
                }
                $designs = array_values(array_map('strval', (array) $ini->get('DesignSettings', 'AdditionalSiteDesignList', [])));
                if ($designs !== $ref['designs']) {
                    $ops[] = ['section' => 'DesignSettings', 'key' => 'AdditionalSiteDesignList', 'array' => $ref['designs']];
                }
                $access = array_values(array_map('strval', (array) $ini->get('ExtensionSettings', 'ActiveAccessExtensions', [])));
                $accessAdd = [];
                foreach ($ref['access'] as $e) {
                    if (!in_array($e, $access, true) && !in_array($e, $global, true) && is_dir($root . '/extension/' . $e)) {
                        $accessAdd[] = $e;
                    }
                }
                if ($accessAdd) {
                    $ops[] = ['section' => 'ExtensionSettings', 'key' => 'ActiveAccessExtensions', 'array' => array_merge($access, $accessAdd)];
                }
                if ($ops) {
                    $files[$file] = $ops;
                    $summary['admin_siteaccesses'][$sa] = ['design' => $ref['design'], 'access_extensions_added' => $accessAdd];
                }
            }
        }
        return ['root' => $root, 'reference' => $reference, 'files' => $files, 'summary' => $summary, 'warnings' => $warnings,
            'up_to_date' => $files === []];
    }

    /** The new text of one settings file. @param list<array<string,mixed>> $ops */
    public static function edit(string $text, array $ops): string
    {
        foreach ($ops as $op) {
            $text = isset($op['array'])
                ? IniEdit::setArray($text, (string) $op['section'], (string) $op['key'], array_map('strval', (array) $op['array']))
                : IniEdit::setValue($text, (string) $op['section'], (string) $op['key'], (string) $op['value']);
        }
        return $text;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function apply(Config $cfg, array $plan, ?callable $progress = null): array
    {
        $root = (string) $plan['root'];
        $say = static function (string $m) use ($progress): void {
            if ($progress) {
                $progress($m);
            }
        };
        $res = ['ok' => false, 'steps' => []];
        $lock = Apply::lock($cfg, $root);
        $owner = Detect::owner($root);
        $drv = Factory::driver(Factory::settings($root)[0]);
        $bk = new Backup($cfg, $root);
        $say('backup: ' . ($plan['files'] ? 'settings files and ' : '') . 'the database');
        $b = $bk->create(array_keys($plan['files']), [], [], $drv, ['from_version' => \Xne\Version::fromTree($root), 'target' => null,
            'method' => 'defaults', 'git' => null, 'owner' => $owner, 'defaults' => $plan['summary']], $progress);
        $res['backup'] = ['id' => $b['id'], 'dir' => $b['dir']];
        $res['steps'][] = 'backup verified';
        $k = new Kernel($root, $cfg, $owner);
        try {
            $b = $bk->setState($b, 'applying', []);
            foreach ($plan['files'] as $rel => $ops) {
                $path = $root . '/' . $rel;
                $exists = is_file($path);
                $text = $exists ? (string) file_get_contents($path) : "<?php /* #?ini charset=\"utf-8\"?\n\n*/ ?>\n";
                $mode = $exists ? (fileperms($path) & 0777) : 0644;
                Fs::atomicWrite($path, self::edit($text, $ops), $mode);
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    @chown($path, $owner['uid']);
                    @chgrp($path, $owner['gid']);
                }
                $res['steps'][] = "written: $rel";
            }
            $say('kernel: extension autoloads');
            $k->autoloads();
            if ($k->has('bin/php/upgrade.php')) {
                // the kernel reads the active extensions through its INI cache: cleared first, or the database stage
                // sees the old list and leaves the new extensions' tables out
                $k->clearCaches(['ini']);
                $say('kernel: exp:upgrade database (the tables of the active extensions)');
                $rep = $k->upgrade(['database'], '');
                $res['steps'][] = 'kernel exp:upgrade database: ' . json_encode($rep['summary'] ?? 'done');
                $pend = $k->pendingStages(['database']);
                if ($pend !== null && ($pend['database'] ?? 0) > 0) {
                    throw new XneException('the kernel still reports ' . $pend['database'] . ' pending database step(s) after the database stage');
                }
            }
            $res['steps'][] = 'caches cleared: ' . implode(', ', $k->clearCaches($cfg->list('cache_tags')));
            $k->bootstrapCheck();
            $res['steps'][] = 'CLI bootstrap ok';
            $bk->setState($b, 'applied', []);
            $res['ok'] = true;
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            $say('FAILED: ' . $e->getMessage() . ' -- rolling back');
            try {
                $rb = (new Rollback($cfg, $root, $progress))->run($b);
                $bk->setState($b, 'rolled-back', ['rollback' => $rb, 'error' => $e->getMessage()]);
                $res['rolled_back'] = true;
            } catch (\Throwable $e2) {
                $bk->setState($b, 'rollback-failed', ['error' => $e->getMessage(), 'rollback_error' => $e2->getMessage()]);
                $res['rolled_back'] = false;
                $res['rollback_error'] = $e2->getMessage();
            }
        }
        flock($lock, LOCK_UN);
        return $res;
    }

    private static function global(string $root): Ini
    {
        return Ini::load(array_merge([$root . '/settings/site.ini'], Ini::candidates($root . '/settings/override', 'site.ini')));
    }

    /** @return list<string> */
    private static function siteaccesses(string $root, Ini $global): array
    {
        $out = [];
        foreach ((array) $global->get('SiteAccessSettings', 'AvailableSiteAccessList', []) as $sa) {
            $sa = (string) $sa;
            if ($sa !== '' && Fs::safeRel($sa) && strpos($sa, '/') === false && is_file($root . '/settings/siteaccess/' . $sa . '/site.ini.append.php')) {
                $out[] = $sa;
            }
        }
        return array_values(array_unique($out));
    }

    /** An administration siteaccess: an admin design, or a design that exists nowhere followed by admin designs. */
    private static function isAdmin(string $root, Ini $ini): bool
    {
        $d = (string) $ini->get('DesignSettings', 'SiteDesign', '');
        if (in_array($d, self::ADMIN_DESIGNS, true)) {
            return true;
        }
        $more = array_map('strval', (array) $ini->get('DesignSettings', 'AdditionalSiteDesignList', []));
        if ($d === '' || !array_intersect($more, self::ADMIN_DESIGNS)) {
            return false;
        }
        return !is_dir($root . '/design/' . $d) && glob($root . '/extension/*/design/' . $d, GLOB_ONLYDIR) === [];
    }

    /** @return array{design: string, designs: list<string>, access: list<string>}|null */
    private static function referenceAdmin(string $reference): ?array
    {
        foreach (self::siteaccesses($reference, self::global($reference)) as $sa) {
            $ini = Ini::load([$reference . '/settings/siteaccess/' . $sa . '/site.ini.append.php']);
            if ((string) $ini->get('DesignSettings', 'SiteDesign', '') === 'admin4l') {
                return ['design' => 'admin4l',
                    'designs' => array_values(array_map('strval', (array) $ini->get('DesignSettings', 'AdditionalSiteDesignList', []))),
                    'access' => array_values(array_map('strval', (array) $ini->get('ExtensionSettings', 'ActiveAccessExtensions', [])))];
            }
        }
        return null;
    }

    /** @param list<string> $l @return list<string> */
    private static function unique(array $l): array
    {
        $out = [];
        foreach ($l as $x) {
            if ($x !== '' && !in_array($x, $out, true)) {
                $out[] = $x;
            }
        }
        return $out;
    }
}
