<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Db\Factory;
use Xne\Filelist;
use Xne\Fs;
use Xne\Method\ComposerMerge;
use Xne\Method\FilePatch;
use Xne\Method\Git;
use Xne\Source\Ranker;
use Xne\Version;
use Xne\XneException;

/**
 * Plan: source ranking, the target release by channel (or --to), the method, the file diff against the base, the
 * local edits found, the libraries and database steps the kernel will run, the traps, and the ordered steps of the
 * upgrade. Read-only: computing a plan changes nothing in the install (the release trees go to the xne cache).
 */
final class Plan
{
    /** @var array<string,mixed> the plan as reported */
    public $data = [];
    /** @var Release|null */
    public $target;
    /** @var Release|null */
    public $base;
    /** @var array<string,mixed> */
    public $detect = [];
    /** @var \Xne\Source\Source|null */
    public $source;

    /**
     * @param array<string,string> $opt to, channel, method, shared, accept (comma list), refresh
     */
    public static function make(Config $cfg, string $root, array $opt, ?callable $progress = null): self
    {
        $p = new self();
        $det = Detect::run($root);
        $p->detect = $det;
        $current = (string) $det['version'];
        $channel = $opt['channel'] ?? $cfg->get('channel');
        $warnings = [];
        $ranker = new Ranker($cfg);
        [$src, $refs] = $ranker->first(($opt['refresh'] ?? '') === '1');
        $p->source = $src;
        $ref = Ranker::target($refs, $current, $channel, $opt['to'] ?? '');
        $d = [
            'root' => $root,
            'install' => [
                'version' => $current, 'type' => $det['install_type'], 'php' => $det['php']['cli'],
                'databases' => $det['databases'], 'owner' => $det['owner']['user'],
                'extensions' => count($det['extensions']), 'manifest' => $det['manifest'],
            ],
            'sources' => $ranker->report,
            'channel' => ($opt['to'] ?? '') !== '' ? 'ref' : $channel,
            'up_to_date' => false,
            'warnings' => &$warnings,
            'blocked' => [],
        ];
        if ($channel === 'main' && ($opt['to'] ?? '') === '') {
            $warnings[] = 'the main channel is the nightly development branch (F2): a backup is forced, switch back with --channel=stable or xne rollback';
        }
        if ($ref === null) {
            $d['up_to_date'] = true;
            $d['target'] = null;
            unset($d['warnings']);
            $d['warnings'] = $warnings;
            $p->data = $d;
            return $p;
        }
        $rel = Release::ensure($cfg, $src, $ref, $progress);
        $p->target = $rel;
        $d['target'] = ['ref' => $ref['ref'], 'kind' => $ref['kind'], 'sha' => $rel->meta['sha'], 'version' => $rel->version(), 'source' => $src->name(), 'files' => count($rel->files())];
        $cmp = Version::compare($rel->version(), $current);
        if ($cmp < 0 && empty($opt['allow_downgrade'])) {
            $d['blocked'][] = 'the target ' . $rel->version() . ' is older than the install (' . $current . '); use xne rollback to go back';
        }
        if ($cmp === 0 && $ref['kind'] === 'tag') {
            $warnings[] = 'the install already declares ' . $current . '; files that differ from the release are brought in line';
        }

        // method
        $method = $opt['method'] ?? $cfg->get('method');
        if ($method === 'auto') {
            $method = $det['install_type'] === 'git' ? 'git' : ($det['install_type'] === 'composer' ? 'composer' : 'filepatch');
        }
        if (!in_array($method, ['filepatch', 'git', 'composer'], true)) {
            throw XneException::usage("unknown method $method (auto, filepatch, git, composer)");
        }
        $d['method'] = $method;
        if ($method === 'composer') {
            $d['blocked'][] = 'the Composer method comes in phase 3; use --method=filepatch for this install';
        }

        // the file diff
        $protect = $cfg->list('protect');
        $diff = null;
        if ($method === 'filepatch') {
            $baseRef = Ranker::tagOf($refs, $current);
            $baseMap = null;
            $baseKind = 'none';
            if ($baseRef !== null) {
                $p->base = Release::ensure($cfg, $src, $baseRef, $progress);
                $baseMap = $p->base->files();
                $baseKind = 'release ' . $baseRef['ref'];
            } else {
                $mf = Filelist::read($root . '/share/filelist.md5');
                if ($mf !== null) {
                    $baseMap = $mf;
                    $baseKind = 'share/filelist.md5';
                }
            }
            if ($baseMap === null) {
                $warnings[] = 'no base for ' . $current . ' (no release tag, no share/filelist.md5): every file that differs is kept aside as a local edit, and no file is retired';
            }
            if ($progress) {
                $progress('comparing ' . count($rel->files()) . ' release files with the install');
            }
            $diff = FilePatch::diff($root, $rel->files(), $baseMap, $protect, ComposerMerge::packagePaths($root));
            $d['base'] = $baseKind;
        } elseif ($method === 'git') {
            $g = new Git($root);
            $d['git'] = ['head' => $det['git']['head'] ?? '', 'branch' => $det['git']['branch'] ?? ''];
            if (!($det['git']['available'] ?? false)) {
                $d['blocked'][] = 'git is not installed: use --method=filepatch';
            } else {
                $problems = $g->check((string) $rel->meta['sha']);
                foreach ($problems as $x) {
                    $d['blocked'][] = 'git: ' . $x;
                }
                $files = $g->has((string) $rel->meta['sha']) ? $g->changed((string) $rel->meta['sha']) : [];
                $d['git']['target_present'] = $g->has((string) $rel->meta['sha']);
                $d['git']['changed'] = count($files);
                $diff = ['add' => [], 'update' => $files, 'remove' => [], 'local_edits' => [], 'kept_retired' => [], 'protected' => [], 'shared' => [], 'links' => [], 'same' => 0];
                if (!$d['git']['target_present']) {
                    $warnings[] = 'the target commit is fetched into the checkout during the upgrade (its file list is known after that)';
                }
            }
        }
        if ($diff !== null) {
            $d['files'] = [
                'add' => count($diff['add']), 'update' => count($diff['update']), 'remove' => count($diff['remove']),
                'local_edits' => count($diff['local_edits']), 'kept_retired' => count($diff['kept_retired']),
                'protected' => count($diff['protected']), 'shared' => count($diff['shared']), 'links' => count($diff['links']), 'packages' => count($diff['packages'] ?? []), 'same' => $diff['same'],
            ];
            $d['local_edits'] = $diff['local_edits'];
            $d['kept_retired'] = $diff['kept_retired'];
            $d['shared_files'] = array_slice($diff['shared'], 0, 50);
            $d['diff'] = $diff;
        }
        $shared = $opt['shared'] ?? '';
        if ($diff !== null && $diff['shared'] && !in_array($shared, ['skip', 'write'], true)) {
            $d['blocked'][] = count($diff['shared']) . ' file(s) are in folders shared with another install: --shared=skip or --shared=write';
        }

        // libraries + database
        $tree = $rel->tree();
        $dbs = Factory::settings($root);
        $engine = $dbs[0]['engine'];
        $dbMode = $cfg->get('db_upgrade');
        if ($dbMode === 'auto') {
            $dbMode = is_file($tree . '/bin/php/upgrade.php') ? 'kernel' : 'sql';
        }
        $db = ['engine' => $engine, 'mode' => $dbMode, 'site_version' => $det['databases'][0]['site_version'] ?? null];
        $libs = ['needed' => false, 'dirs' => []];
        if ($dbMode === 'kernel') {
            $lockT = is_file($tree . '/composer.lock') ? md5_file($tree . '/composer.lock') : null;
            $lockL = is_file($root . '/composer.lock') ? md5_file($root . '/composer.lock') : null;
            $wantLibs = $cfg->get('libraries') !== 'skip' && $lockT !== null && $lockT !== $lockL;
            if (!$wantLibs && $cfg->get('libraries') !== 'skip') {
                // the same composer.lock, but the kernel may still report its libraries stage as pending (vendor/
                // not what the lock names, as on a kickstarted install): exp:upgrade then refuses the database stage
                // until libraries ran, so it runs first, in the kernel's order, with the library folders backed up
                try {
                    $pend = (new Kernel($root, $cfg, $det['owner']))->pendingStages(['libraries']);
                    if ($pend !== null && ($pend['libraries'] ?? 0) > 0) {
                        $wantLibs = true;
                        $db['libraries_reason'] = 'the kernel reports ' . $pend['libraries'] . ' pending libraries step(s)';
                    }
                } catch (\Throwable $e) {
                    $warnings[] = 'could not read the kernel upgrade status of the libraries stage: ' . $e->getMessage();
                }
            } elseif ($wantLibs) {
                $db['libraries_reason'] = 'composer.lock changes';
            }
            // an install whose libraries Composer manages: its composer.json follows the release's (merged, the
            // site's extension packages kept), and the kernel's libraries stage installs what is new
            if ($cfg->get('libraries') !== 'skip' && ComposerMerge::managed($root) && is_file($tree . '/composer.json')) {
                $mine = json_decode((string) file_get_contents($root . '/composer.json'), true);
                $theirs = json_decode((string) file_get_contents($tree . '/composer.json'), true);
                if (is_array($mine) && is_array($theirs)) {
                    $m = ComposerMerge::merge($mine, $theirs, ComposerMerge::lockPackages($root . '/composer.lock'));
                    $write = ComposerMerge::encode($m['json']) !== (string) file_get_contents($root . '/composer.json');
                    $d['composer'] = ['write' => $write, 'json' => $m['json'], 'added' => $m['added'], 'changed' => $m['changed'],
                        'kept' => $m['kept'], 'dropped' => $m['dropped'], 'update' => $m['update']];
                    if ($m['update'] !== [] || $write) {
                        $wantLibs = true;
                        $db['libraries_reason'] = 'composer.json follows the release: ' . count($m['added']) . ' new package(s), '
                            . count($m['changed']) . ' changed constraint(s), ' . count($m['kept']) . ' site extension(s) kept, '
                            . count($m['dropped']) . ' dropped';
                    }
                } else {
                    $warnings[] = 'composer.json of the install or the release cannot be read: the libraries are left as they are';
                }
            }
            $stages = $wantLibs ? ['libraries', 'database', 'scripts'] : ['database', 'scripts'];
            $db['kernel_command'] = 'php bin/php/upgrade.php --apply --stages=' . implode(',', $stages) . ' --no-deploy --no-verify --format=json --no-caption --force-no-caption';
            $db['stages'] = $stages;
            if ($method === 'filepatch') {
                // the file patch puts the code in place: the release's kernel is told so (older kernels: Apply
                // checks that the code step is the only pending one before it passes --force-order)
                $db['code_stage'] = Kernel::supportsCodeApplied($tree) ? 'code-applied' : 'force-order-if-only-code-pending';
                if ($db['code_stage'] === 'code-applied') {
                    $db['kernel_command'] .= ' --code-applied=' . (string) $rel->meta['sha'];
                }
            }
            if ($wantLibs) {
                $libs = ['needed' => true, 'dirs' => self::libraryDirs($root, $tree, (array) ($d['composer']['added'] ?? []))];
                if (!empty($d['composer']['update'])) {
                    $libs['update'] = $d['composer']['update'];
                    $db['kernel_command'] .= ' --update=' . implode(',', $d['composer']['update']);
                }
            }
        } elseif ($dbMode === 'sql' && ($engine === 'mongodb' || $engine === 'oracle')) {
            // MongoDB and Oracle: the steps are the kernel's exp:upgrade (6.0.15 and newer), not SQL files
            $db['sql_files'] = [];
            $d['blocked'][] = 'the ' . $engine . ' database is upgraded by the kernel\'s exp:upgrade, which the target ' . $rel->version() . ' does not have';
        } elseif ($dbMode === 'sql') {
            $from = (string) ($db['site_version'] ?? $current);
            if (!Version::valid($from)) {
                $from = $current;
            }
            $db['sql_files'] = self::sqlFiles($tree, $engine, $from, $rel->version());
            $common = [];
            foreach ((array) @glob($tree . '/update/common/scripts/' . Version::line($rel->version()) . '/*.php') as $f) {
                $common[] = 'update/common/scripts/' . Version::line($rel->version()) . '/' . basename((string) $f);
            }
            $db['scripts_info'] = $common;
        }
        $d['database'] = $db;
        $d['libraries'] = $libs;
        // the PHP the kernel's tools will run with: refused here, before any backup, when none meets the platform
        // check of the install's vendor/ (the tools would die in it after the files had changed)
        $php = Kernel::selectPhp($cfg, $root, $tree);
        $d['php'] = $php;
        if ($php['binary'] === null) {
            $d['blocked'][] = 'php: ' . $php['reason'];
        }

        // traps
        $traps = self::withSharedChoice(Traps::run($det, $diff, $tree), (string) ($opt['shared'] ?? ''));
        // the PHP extensions the target requires (its composer.json and every package of its composer.lock), checked
        // against that PHP now: Composer would refuse them half way through the upgrade
        $ext = PhpExtensions::check($tree, $php['binary']);
        $d['php_extensions'] = $ext;
        if ($ext['missing'] !== []) {
            $traps[] = self::extensionTrap($ext);
        } elseif ($php['binary'] !== null && $ext['required'] !== [] && !$ext['checked']) {
            $warnings[] = 'could not list the extensions of ' . $php['binary'] . ' (php -m failed): the target requires ' . implode(', ', array_keys($ext['required']));
        }
        $accept = array_filter(array_map('trim', explode(',', (string) ($opt['accept'] ?? ''))), 'strlen');
        $d['traps'] = $traps;
        foreach (Traps::blocking($traps, $accept) as $t) {
            $d['blocked'][] = 'trap ' . $t['id'] . ': ' . $t['message'] . ($t['fix'] !== '' ? ' (fix: ' . $t['fix'] . ')' : '');
        }
        $d['maintenance'] = $cfg->get('maintenance');
        $d['backup'] = ['keep' => $cfg->int('backup_keep'), 'dir' => (new Backup($cfg, $root))->dir()];
        $steps = ['lock', 'backup (files + database, verified)'];
        if ($cfg->get('maintenance') === 'page') {
            $steps[] = 'maintenance on (503 page, admins can sign in)';
        }
        $steps[] = $method === 'git' ? 'git fast-forward to ' . substr((string) $rel->meta['sha'], 0, 12) : 'file patch (' . ($diff === null ? 0 : count($diff['add']) + count($diff['update']) + count($diff['local_edits'])) . ' written, ' . ($diff === null ? 0 : count($diff['remove'])) . ' retired)';
        if ($dbMode === 'kernel') {
            $steps[] = 'kernel exp:upgrade' . ($php['binary'] !== null ? ' with ' . $php['binary'] : '') . ': ' . implode(', ', $db['stages']);
        } elseif ($dbMode === 'sql') {
            $steps[] = count($db['sql_files']) . ' SQL update file(s)';
        }
        $steps[] = 'extension autoloads + cache clears (' . implode(', ', $cfg->list('cache_tags')) . ($cfg->get('finish') === 'velocity' ? ', exp:velocity deploy' : '') . ')';
        $steps[] = 'health check (files, manifest, CLI bootstrap, kernel upgrade status' . ($cfg->get('health_url') !== '' ? ', URLs' : '') . ')';
        if ($cfg->get('maintenance') === 'page') {
            $steps[] = 'maintenance off';
        }
        $steps[] = 'rotate backups (keep ' . $cfg->int('backup_keep') . ')';
        $d['steps'] = $steps;
        $d['on_failure'] = 'automatic rollback of files and database to the backup';
        unset($d['warnings']);
        $d['warnings'] = $warnings;
        $p->data = $d;
        return $p;
    }

    /**
     * The blocking trap for missing PHP extensions, with the php.ini line for Linux and Windows.
     * @param array<string,mixed> $ext the result of PhpExtensions::check()
     * @return array{id: string, level: string, message: string, fix: string}
     */
    public static function extensionTrap(array $ext): array
    {
        $names = [];
        $lin = [];
        $win = [];
        foreach ($ext['missing'] as $e => $m) {
            $names[] = $e . ' (required by ' . implode(', ', array_slice($m['required_by'], 0, 3)) . (count($m['required_by']) > 3 ? ', ...' : '') . ')';
            $lin[] = $m['linux'];
            $win[] = $m['windows'];
        }
        return [
            'id' => 'php-extension-missing',
            'level' => 'block',
            'message' => 'the target requires PHP extension(s) that ' . $ext['php'] . ' does not load: ' . implode('; ', $names)
                . '; Composer would refuse the libraries stage and the new vendor/ would stop every request',
            'fix' => 'enable them in ' . ($ext['ini'] !== '' ? $ext['ini'] : 'the php.ini of that PHP (php --ini)') . ' and in the web server\'s PHP, then plan again. Linux: '
                . implode('; ', $lin) . '. Windows: ' . implode('; ', $win),
        ];
    }

    /**
     * The update/database/<engine>/<line>/ files from the site's database version to the target: a file
     * dbupdate-A-B.sql (or A-to-B) is chained: taken when A <= current < B <= target, then current = B (older lines
     * chain their files this way, owner Q7). unstable/ is never used.
     * @return list<string>
     */
    public static function sqlFiles(string $tree, string $engine, string $from, string $to): array
    {
        $cand = [];
        foreach ((array) @glob($tree . '/update/database/' . $engine . '/*/dbupdate-*.sql') as $f) {
            $f = (string) $f;
            if (!preg_match('/dbupdate-([\d.]+\w*?)-(?:to-)?([\d.]+\w*)\.sql$/', basename($f), $m)) {
                continue;
            }
            if (Version::valid($m[1]) && Version::valid($m[2])) {
                $cand[] = [$m[1], $m[2], substr($f, strlen($tree) + 1)];
            }
        }
        usort($cand, static function ($x, $y) {
            return Version::compare($x[1], $y[1]) ?: Version::compare($x[0], $y[0]);
        });
        $out = [];
        $cur = $from;
        foreach ($cand as [$a, $b, $rel]) {
            if (Version::compare($a, $cur) <= 0 && Version::compare($b, $cur) > 0 && Version::compare($b, $to) <= 0) {
                $out[] = $rel;
                $cur = $b;
            }
        }
        return $out;
    }

    /**
     * What Composer will change: vendor/, composer.json, composer.lock and every extension folder a Composer package
     * of the install or of the target's composer.lock installs into. @return list<string>
     */
    public static function libraryDirs(string $root, string $tree, array $newPackages = []): array
    {
        $dirs = ['vendor', 'composer.json', 'composer.lock'];
        // the extension folders of packages that will be installed: listed now (absent), so a rollback moves out
        // what the install put there
        foreach ($newPackages as $name) {
            $dir = substr((string) strrchr('/' . $name, '/'), 1);
            if ($dir !== '' && Fs::safeRel($dir)) {
                $dirs[] = 'extension/' . $dir;
            }
        }
        foreach ([$root . '/composer.lock', $tree . '/composer.lock'] as $lock) {
            if (is_file($lock)) {
                $dirs = array_merge($dirs, self::lockExtensionDirs((string) file_get_contents($lock)));
            }
        }
        return array_values(array_unique($dirs));
    }

    /**
     * The extension folders (extension/<name>) of the extension packages a composer.lock names, as the legacy
     * installer names them: extra.ezpublish-legacy-extension-name (se7enxweb/explayouts-api -> explayouts_api), else
     * extra.installer-name, else the package name after the vendor. @return list<string>
     */
    public static function lockExtensionDirs(string $lockJson): array
    {
        $j = json_decode($lockJson, true);
        if (!is_array($j)) {
            return [];
        }
        $dirs = [];
        foreach (array_merge((array) ($j['packages'] ?? []), (array) ($j['packages-dev'] ?? [])) as $pk) {
            if (!is_array($pk) || !in_array($pk['type'] ?? '', ['ezpublish-legacy-extension', 'exponential-legacy-extension', 'ezpublish-legacy-extension-installer'], true)) {
                continue;
            }
            $name = (string) ($pk['extra']['ezpublish-legacy-extension-name'] ?? $pk['extra']['installer-name']
                ?? substr((string) ($pk['name'] ?? ''), (int) strpos((string) ($pk['name'] ?? ''), '/') + 1));
            if ($name !== '' && Fs::safeRel($name) && strpos($name, '/') === false) {
                $dirs[] = 'extension/' . $name;
            }
        }
        return array_values(array_unique($dirs));
    }

    /**
     * --shared=skip|write is the answer the shared-extension-folder trap asks for: once it is given, the trap no
     * longer blocks (it stays listed, as info, with the choice). Without it the trap blocks as before.
     * @param list<array{id: string, level: string, message: string, fix: string}> $traps
     * @return list<array{id: string, level: string, message: string, fix: string}>
     */
    public static function withSharedChoice(array $traps, string $shared): array
    {
        if (!in_array($shared, ['skip', 'write'], true)) {
            return $traps;
        }
        foreach ($traps as $i => $t) {
            if ($t['id'] === 'shared-extension-folder' && $t['level'] === 'block') {
                $traps[$i]['level'] = 'info';
                $traps[$i]['message'] .= " (--shared=$shared given: " . ($shared === 'write' ? 'written through the links' : 'left as they are') . ')';
            }
        }
        return $traps;
    }
}
