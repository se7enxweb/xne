<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Db\Factory;
use Xne\Fs;
use Xne\Method\FilePatch;
use Xne\Method\Git;
use Xne\XneException;

/**
 * Apply: the upgrade itself, in this order, with an automatic rollback (F3) when any step after the backup fails:
 * lock, plan check, verified backup, maintenance (F4: keep serving by default, or the kernel's 503 page), files
 * (file patch or git fast-forward), the kernel's exp:upgrade (libraries, database, scripts) or the SQL update files,
 * the extension autoloads and targeted cache clears, the health check, maintenance off, backup rotation.
 */
final class Apply
{
    /**
     * Folders a kernel upgrade step may write although a file patch never does (exp:upgrade writes
     * settings/override/*.ini.append.php for new features): archived whole with their listing, so a rollback restores
     * them and moves aside what the upgrade added. The kernel's own caption keeps settings/override for the same reason.
     */
    public const SETTINGS_DIRS = ['settings/override', 'settings/siteaccess'];

    /** @var Config */
    private $cfg;
    /** @var string */
    private $root;
    /** @var callable|null */
    private $progress;
    /** @var string|null for tests: fail on purpose after this step (env XNE_TEST_FAIL_AFTER) */
    private $failAfter;

    public function __construct(Config $cfg, string $root, ?callable $progress = null)
    {
        $this->cfg = $cfg;
        $this->root = $root;
        $this->progress = $progress;
        $f = getenv('XNE_TEST_FAIL_AFTER');
        $this->failAfter = $f === false || $f === '' ? null : $f;
    }

    private function say(string $m): void
    {
        if ($this->progress) {
            ($this->progress)($m);
        }
    }

    private function checkpoint(string $step): void
    {
        if ($this->failAfter === $step) {
            throw new XneException("forced failure after the step \"$step\" (XNE_TEST_FAIL_AFTER)");
        }
    }

    /** @return resource the lock, held until the process ends */
    public static function lock(Config $cfg, string $root)
    {
        $dir = Fs::join($cfg->get('state_dir'), 'installs', Backup::installId($root));
        Fs::mkdir($dir, 0700);
        $h = fopen($dir . '/lock', 'c');
        if ($h === false || !flock($h, LOCK_EX | LOCK_NB)) {
            throw XneException::refused('another xne run holds the lock of this install');
        }
        return $h;
    }

    /**
     * @param array<string,string> $opt the plan options
     * @return array<string,mixed> the result (ok, steps, backup, rollback ...)
     */
    public function run(Plan $plan, array $opt): array
    {
        $d = $plan->data;
        $res = ['ok' => false, 'from' => $d['install']['version'], 'steps' => []];
        if ($d['up_to_date']) {
            $res['ok'] = true;
            $res['up_to_date'] = true;
            return $res;
        }
        $res['to'] = $d['target']['version'];
        $res['target'] = $d['target'];
        if ($d['blocked']) {
            throw XneException::refused("the upgrade is refused:\n  - " . implode("\n  - ", $d['blocked']));
        }
        $lock = self::lock($this->cfg, $this->root);
        $det = $plan->detect;
        $owner = $det['owner'];
        $k = new Kernel($this->root, $this->cfg, $owner);
        $method = (string) $d['method'];
        $diff = $d['diff'] ?? null;
        $target = $plan->target->files();
        $tree = $plan->target->tree();
        $sha = (string) $d['target']['sha'];
        $git = $method === 'git' ? new Git($this->root) : null;

        if ($git !== null) {
            $this->say('git: fetching ' . substr($sha, 0, 12));
            $url = $plan->source !== null && $plan->source->name() === 'github' ? 'https://github.com/' . $this->cfg->get('github_repo') . '.git' : '';
            $git->fetch($sha, (string) $d['target']['ref'], $url);
            foreach ($git->check($sha) as $x) {
                throw XneException::refused('git: ' . $x);
            }
            $diff['update'] = $git->changed($sha);
        }

        // backup
        // a git install keeps its old files in git itself (the rollback is git reset --keep); a file patch archives them
        $changed = $git !== null ? [] : array_merge($diff['update'] ?? [], $diff['local_edits'] ?? [], $diff['remove'] ?? []);
        if ($method === 'filepatch' && ($opt['shared'] ?? '') === 'write') {
            $changed = array_merge($changed, $diff['shared']);
        }
        $drv = null;
        if (($d['database']['mode'] ?? 'none') !== 'none') {
            $drv = Factory::driver(Factory::settings($this->root)[0]);
        }
        $bk = new Backup($this->cfg, $this->root);
        $info = [
            'from_version' => $d['install']['version'], 'target' => $d['target'], 'method' => $method,
            'git' => $git !== null ? ['head' => $git->head(), 'branch' => $det['git']['branch'] ?? ''] : null,
            'maintenance' => $this->cfg->get('maintenance'),
            // the rollback restores files as this user (xne runs as root; a restored file owned by root would make
            // the next detection take root for the install's owner, and the kernel's tools would run as root)
            'owner' => $owner,
        ];
        if ($method === 'filepatch' && ($opt['shared'] ?? '') === 'write' && !empty($diff['shared'])) {
            // the rollback restores these through the same links (and only these)
            $info['shared_write'] = true;
            $info['shared_files'] = array_values($diff['shared']);
        }
        $b = $bk->create($changed, $diff['local_edits'] ?? [], array_merge($d['libraries']['needed'] ? $d['libraries']['dirs'] : [], self::SETTINGS_DIRS), $drv, $info, $this->progress);
        $res['backup'] = ['id' => $b['id'], 'dir' => $b['dir'], 'files' => $b['files']['count'], 'db' => $b['db'] === null ? null : ['engine' => $b['db']['engine'], 'tables' => count($b['db']['tables']), 'bytes' => $b['db']['bytes']]];
        $res['steps'][] = 'backup verified';
        $this->checkpoint('backup');

        $maintOn = false;
        $applied = ['added' => [], 'written' => 0, 'removed' => 0];
        try {
            if ($this->cfg->get('maintenance') === 'page') {
                $maintOn = $k->maintenance('on', $this->cfg->get('maintenance_until'));
                $res['steps'][] = $maintOn ? 'maintenance on' : 'maintenance page not available before the files land';
            }

            // files
            if ($git !== null) {
                $this->say('git: fast-forward to ' . substr($sha, 0, 12));
                $git->fastForward($sha);
                $res['steps'][] = 'git fast-forward ' . substr((string) $b['git']['head'], 0, 12) . '..' . substr($sha, 0, 12);
            } else {
                $this->say('files: writing ' . (count($diff['add']) + count($diff['update']) + count($diff['local_edits'])) . ', retiring ' . count($diff['remove']));
                $applied['added'] = $diff['add'];
                $b = $bk->setState($b, 'applying', ['applied' => $applied]);
                $r = FilePatch::apply($this->root, $tree, $diff, $target, $b['dir'] . '/aside', $owner, ($opt['shared'] ?? '') === 'write');
                $applied['written'] = $r['written'];
                $applied['removed'] = $r['removed'];
                $res['steps'][] = "files: {$r['written']} written, {$r['removed']} retired, " . count($diff['local_edits']) . ' local edit(s) kept aside';
            }
            $b = $bk->setState($b, 'applying', ['applied' => $applied]);
            $this->checkpoint('files');
            if ($this->cfg->get('maintenance') === 'page' && !$maintOn) {
                $maintOn = $k->maintenance('on', $this->cfg->get('maintenance_until'));
                if ($maintOn) {
                    $res['steps'][] = 'maintenance on';
                }
            }

            // composer.json follows the release (merged: the site's extension packages kept), through a link
            // into a capture's arc/ tree when it is one; its old content is in the backup (a library file)
            if (!empty($d['composer']['write'])) {
                $cj = $this->root . '/composer.json';
                $real = is_link($cj) ? (string) realpath($cj) : $cj;
                if ($real === '' || !is_file($real)) {
                    throw new XneException('composer.json is a link to nothing: it cannot follow the release');
                }
                Fs::atomicWrite($real, \Xne\Method\ComposerMerge::encode((array) $d['composer']['json']), fileperms($real) & 0777);
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    @chown($real, $owner['uid']);
                    @chgrp($real, $owner['gid']);
                }
                $res['steps'][] = 'composer.json follows the release: ' . count((array) $d['composer']['added']) . ' new package(s), '
                    . count((array) $d['composer']['kept']) . ' site extension(s) kept';
            }

            // libraries + database + scripts
            $mode = $d['database']['mode'];
            $ran = [];
            if ($mode === 'kernel') {
                $stages = $d['database']['stages'];
                if (!in_array('libraries', $stages, true) && $this->cfg->get('libraries') !== 'skip') {
                    // the new kernel may report its libraries stage pending although the plan did not: run it in
                    // order only when its folders are in the backup, else stop here and roll back (never run unbacked)
                    $pend = $k->pendingStages(['libraries']);
                    if ($pend !== null && ($pend['libraries'] ?? 0) > 0) {
                        if (!$d['libraries']['needed']) {
                            throw new XneException('the kernel reports ' . $pend['libraries'] . ' pending libraries step(s) after the files landed, and the library folders are not in the backup: run xne upgrade again (the plan then backs them up)');
                        }
                        $stages = array_merge(['libraries'], $stages);
                    }
                }
                $this->say('kernel: exp:upgrade ' . implode(',', $stages));
                $rep = $k->upgrade($stages, $git !== null ? $sha : '', in_array('libraries', $stages, true) ? (array) ($d['libraries']['update'] ?? []) : []);
                $ran = $stages;
                $res['kernel_upgrade'] = $rep['summary'] ?? null;
                $res['steps'][] = 'kernel exp:upgrade ' . implode(',', $ran) . ': ' . json_encode($rep['summary'] ?? 'done');
            } elseif ($mode === 'sql') {
                foreach ($d['database']['sql_files'] as $f) {
                    $this->say("database: $f");
                    $n = $drv->runSqlFile($tree . '/' . $f);
                    $res['steps'][] = "database: $f ($n statements)";
                }
                if (!$d['database']['sql_files']) {
                    $res['steps'][] = 'database: no SQL update file for this step';
                }
            }
            $this->checkpoint('database');

            // finish
            $this->say('finish: autoloads and caches');
            $k->autoloads();
            $tags = $k->clearCaches($this->cfg->list('cache_tags'));
            $res['steps'][] = 'extension autoloads; caches cleared: ' . implode(', ', $tags);
            if ($this->cfg->get('finish') === 'velocity') {
                $k->velocityDeploy();
                $res['steps'][] = 'exp:velocity deploy';
            }
            $this->checkpoint('finish');

            // health
            $this->say('health check');
            $res['health'] = Health::run($this->cfg, $this->root, $k, $diff, $target, $ran, $method);
            $res['steps'][] = 'health check passed';
            $this->checkpoint('health');
            if ($maintOn) {
                $k->maintenance('off');
                $res['steps'][] = 'maintenance off';
            }
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            $this->say('FAILED: ' . $e->getMessage() . ' -- rolling back');
            try {
                if ($maintOn) {
                    try {
                        $k->maintenance('off');
                    } catch (\Throwable $x) {
                    }
                }
                $b = $bk->setState($b, 'rolling-back', ['applied' => $applied, 'error' => $e->getMessage()]);
                $rb = (new Rollback($this->cfg, $this->root, $this->progress))->run($b);
                $bk->setState($b, 'rolled-back', ['rollback' => $rb]);
                $res['rolled_back'] = true;
                $res['rollback'] = $rb;
                $res['steps'][] = 'automatic rollback done: ' . $rb['restored'] . ' file(s) restored, ' . count($rb['moved_aside']) . ' moved aside' . ($rb['database'] ? ', database restored' : '');
            } catch (\Throwable $rbe) {
                $res['rolled_back'] = false;
                $res['rollback_error'] = $rbe->getMessage();
                $bk->setState($b, 'rollback-failed', ['rollback_error' => $rbe->getMessage()]);
            }
            flock($lock, LOCK_UN);
            return $res;
        }
        $b = $bk->setState($b, 'applied', ['applied' => $applied, 'to_version' => $d['target']['version']]);
        $res['rotated'] = $bk->rotate($this->cfg->int('backup_keep'), (string) $b['id']);
        $res['ok'] = true;
        $res['kept_aside'] = $diff['local_edits'] ?? [];
        flock($lock, LOCK_UN);
        return $res;
    }
}
