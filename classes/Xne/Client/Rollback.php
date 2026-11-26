<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Db\Factory;
use Xne\Fs;
use Xne\Method\Git;
use Xne\Tar;
use Xne\XneException;

/**
 * Rollback (owner Q17, F3): puts an install back to a backup, files and database, in one command; the same code runs
 * automatically when any step of an upgrade fails.
 *
 *   1. files the upgrade added are moved aside into the backup (aside-rollback/), never deleted
 *   2. the archived files are written back (atomically, each checked against its recorded md5)
 *   3. library folders (vendor/, Composer extensions): files added since the backup are moved aside
 *   4. a git install is reset to the commit it was on (git reset --keep, refused over local changes)
 *   5. the database is restored from the dump (PostgreSQL in one transaction; SQLite through the backup API)
 *   6. the extension autoloads and the caches are cleared with the restored code
 */
final class Rollback
{
    /** @var Config */
    private $cfg;
    /** @var string */
    private $root;
    /** @var callable|null */
    private $progress;

    public function __construct(Config $cfg, string $root, ?callable $progress = null)
    {
        $this->cfg = $cfg;
        $this->root = $root;
        $this->progress = $progress;
    }

    private function say(string $m): void
    {
        if ($this->progress) {
            ($this->progress)($m);
        }
    }

    /**
     * The extension folders of extension packages the install's composer.lock names that are new since the backup:
     * not named by the composer.lock in the backup, not a library folder of its listing, and present as a folder (not
     * a link) in the install. Only for a backup that archived composer.lock (the libraries ran). @return list<string>
     * @param array<string,mixed> $b @param array<string,mixed> $listing
     */
    private function addedExtensionDirs(array $b, array $listing): array
    {
        $sums = (array) ($b['files']['sums'] ?? []);
        if (!isset($sums['composer.lock']) || !is_file($this->root . '/composer.lock')) {
            return [];
        }
        $old = null;
        Tar::read((string) $b['dir'] . '/' . $b['files']['archive'], static function (array $e, $data) use (&$old): void {
            if ($e['name'] === 'composer.lock' && $e['type'] === 'file') {
                $old = $data->readAll();
            }
        });
        if ($old === null) {
            return [];
        }
        $before = array_flip(Plan::lockExtensionDirs($old));
        $out = [];
        foreach (Plan::lockExtensionDirs((string) file_get_contents($this->root . '/composer.lock')) as $ld) {
            $abs = $this->root . '/' . $ld;
            if (isset($before[$ld]) || array_key_exists($ld, $listing) || is_link($abs) || !is_dir($abs)) {
                continue;
            }
            $out[] = $ld;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $b the backup (with dir)
     * @return array<string,mixed> what was done
     */
    public function run(array $b, bool $withDb = true, bool $dryRun = false, bool $writeShared = false): array
    {
        Backup::verify($b);
        $dir = (string) $b['dir'];
        // files the upgrade wrote through a link out of the install (--shared=write) are restored through it too:
        // the paths the backup recorded as shared, or, for a backup made before xne recorded them, every archived
        // path when the operator says --shared=write again
        $sharedOk = array_flip(array_map('strval', (array) ($b['shared_files'] ?? [])));
        $writeShared = $writeShared || !empty($b['shared_write']);
        $root = $this->root;
        $outside = static function (string $p) use ($root): bool {
            return !Fs::inside($root, dirname($root . '/' . $p));
        };
        $mayWrite = static function (string $p) use ($outside, $sharedOk, $writeShared, $b): bool {
            if (!$outside($p)) {
                return true;
            }
            return $writeShared && (isset($sharedOk[$p]) || !isset($b['shared_files']));
        };
        // every path is checked before anything changes: a refusal halfway would leave a mixed tree
        $sums = (array) $b['files']['sums'];
        $refused = [];
        Tar::read($dir . '/' . $b['files']['archive'], static function (array $e, $data) use ($sums, $mayWrite, &$refused): void {
            $p = $e['name'];
            if (Fs::safeRel($p) && isset($sums[$p]) && !$mayWrite($p)) {
                $refused[] = $p;
            }
        });
        if ($refused) {
            throw XneException::refused(count($refused) . ' file(s) of the backup are in folders that lead out of the install (first: ' . $refused[0]
                . '); nothing was changed. When the upgrade wrote them with --shared=write, roll back with --shared=write');
        }
        $aside = $dir . '/aside-rollback';
        $done = ['backup' => $b['id'], 'moved_aside' => [], 'restored' => 0, 'git_reset' => null, 'database' => false, 'dry_run' => $dryRun];
        $added = (array) ($b['applied']['added'] ?? []);
        $listing = is_file($dir . '/listing.json') ? Fs::readJson($dir . '/listing.json') : [];
        $extra = [];
        foreach ($listing as $ld => $files) {
            if (!is_dir($this->root . '/' . $ld)) {
                continue;
            }
            $known = array_flip((array) $files);
            foreach (Fs::walk($this->root . '/' . $ld, static function (string $r, bool $isDir) {
                return $isDir && basename($r) === '.git';
            }) as $r) {
                if (!isset($known[$r])) {
                    $extra[] = $ld . '/' . $r;
                }
            }
        }
        // extension folders Composer added for packages the plan could not name: dependencies of an updated package
        // (exponential-php-floor 1.3 brings some forty extensions) are in no composer.json and in no lock of the
        // release. The install's composer.lock names them now; a folder the lock in the backup names too was there
        // before and is restored, not moved.
        $newExtDirs = $this->addedExtensionDirs($b, $listing);
        foreach ($newExtDirs as $ld) {
            foreach (Fs::walk($this->root . '/' . $ld) as $r) {
                $extra[] = $ld . '/' . $r;
            }
        }
        if ($dryRun) {
            $done['would_move_aside'] = array_merge($added, $extra);
            $done['would_restore'] = (int) $b['files']['count'];
            $done['would_restore_database'] = $withDb && !empty($b['db']);
            $done['would_git_reset'] = ($b['method'] ?? '') === 'git' ? ($b['git']['head'] ?? null) : null;
            return $done;
        }

        if (($b['method'] ?? '') === 'git' && !empty($b['git']['head'])) {
            $this->say('rollback: git reset --keep ' . substr((string) $b['git']['head'], 0, 12));
            (new Git($this->root))->resetKeep((string) $b['git']['head']);
            $done['git_reset'] = $b['git']['head'];
        } else {
            $this->say('rollback: moving ' . count($added) . ' added file(s) aside');
            foreach ($added as $p) {
                Guard::guardedMoveAside($this->root, (string) $p, $aside);
                $done['moved_aside'][] = $p;
            }
        }
        foreach ($extra as $p) {
            Guard::guardedMoveAside($this->root, $p, $aside);
            $done['moved_aside'][] = $p;
        }
        foreach ($newExtDirs as $ld) {
            Guard::guardedRemoveEmptyDirs($this->root, $ld);
        }
        $this->say('rollback: restoring ' . $b['files']['count'] . ' file(s)');
        $n = 0;
        $isGit = ($b['method'] ?? '') === 'git';
        $owner = $this->owner($b);
        $chown = function_exists('posix_geteuid') && posix_geteuid() === 0 && $owner['uid'] !== 0;
        $own = static function (string $path, string $upTo) use ($chown, $owner): void {
            if (!$chown) {
                return;
            }
            for ($x = $path; strlen($x) > strlen($upTo); $x = dirname($x)) {
                if (@fileowner($x) === 0 || $x === $path) {
                    @lchown($x, $owner['uid']);
                    @lchgrp($x, $owner['gid']);
                }
            }
        };
        $followed = array_flip(array_map('strval', (array) ($b['files']['followed'] ?? [])));
        Tar::read($dir . '/' . $b['files']['archive'], static function (array $e, $data) use ($root, $sums, &$n, $isGit, $listing, $mayWrite, $own, $followed): void {
            $p = $e['name'];
            if (!Fs::safeRel($p) || !isset($sums[$p])) {
                return;
            }
            if ($isGit) {
                $inLib = false;
                foreach (array_keys($listing) as $ld) {
                    if ($p === $ld || strncmp($p, $ld . '/', strlen($ld) + 1) === 0) {
                        $inLib = true;
                    }
                }
                if (!$inLib) {
                    return;
                }
            }
            $dst = $root . '/' . $p;
            if (!$mayWrite($p)) {
                throw new XneException("refused to restore $p: its folder leads out of the install");
            }
            if ($e['type'] === 'link') {
                if (!is_link($dst) && !file_exists($dst)) {
                    Fs::mkdir(dirname($dst));
                    Fs::symlink($e['link'], $dst);
                    $own($dst, $root);
                }
                $n++;
                return;
            }
            if ($e['type'] !== 'file') {
                return;
            }
            if (isset($followed[$p]) && is_link($dst)) {
                // archived by content from behind a link out of the install: written back through the same link
                $real = realpath($dst);
                if ($real === false) {
                    throw new XneException("refused to restore $p: its link leads to nothing");
                }
                $dst = $real;
            }
            if (Fs::fingerprint($dst) === $sums[$p]) {
                $n++;
                return;
            }
            $md5 = Fs::atomicFromTar($data, $dst, $e['mode'] ?: 0644);
            if ($md5 !== $sums[$p] || Fs::fingerprint($dst) !== $sums[$p]) {
                throw new XneException("$p does not match the backup after the restore");
            }
            // a file restored behind a link: only that file is given back to the owner, no folder outside the install
            $own($dst, isset($followed[$p]) ? dirname($dst) : $root);
            $n++;
        });
        $done['restored'] = $n;
        if ($withDb && !empty($b['db'])) {
            $settings = Factory::settings($this->root)[0];
            $drv = Factory::driver($settings);
            $this->say('rollback: restoring the ' . $drv->engine() . ' database');
            $drv->restore($dir . '/' . $b['db']['file']);
            $after = $drv->counts();
            foreach ((array) $b['db']['tables'] as $t => $c) {
                if (($after[$t] ?? -1) !== (int) $c) {
                    throw new XneException("after the restore $t has " . ($after[$t] ?? 'no') . " rows, $c expected");
                }
            }
            foreach (array_keys($after) as $t) {
                if (!array_key_exists($t, (array) $b['db']['tables'])) {
                    throw new XneException("after the restore the table $t is still there");
                }
            }
            $done['database'] = true;
        }
        $k = new Kernel($this->root, $this->cfg, $owner);
        $done['finish'] = [];
        try {
            if ($k->has('bin/php/ezpgenerateautoloads.php')) {
                $k->autoloads();
                $done['finish'][] = 'autoloads';
            }
            if ($k->has('bin/php/ezcache.php')) {
                $done['finish'] = array_merge($done['finish'], $k->clearCaches($this->cfg->list('cache_tags')));
            }
        } catch (XneException $e) {
            $done['finish_warning'] = $e->getMessage();
        }
        return $done;
    }

    /**
     * The install's owner as the backup recorded it (since 0.3.0.2), else as found now, before anything is restored.
     * @param array<string,mixed> $b
     * @return array{user: string, group: string, uid: int, gid: int}
     */
    private function owner(array $b): array
    {
        $o = $b['owner'] ?? null;
        if (is_array($o) && isset($o['uid'], $o['gid'], $o['user'])) {
            return ['user' => (string) $o['user'], 'group' => (string) ($o['group'] ?? $o['gid']), 'uid' => (int) $o['uid'], 'gid' => (int) $o['gid']];
        }
        return Detect::owner($this->root);
    }
}
