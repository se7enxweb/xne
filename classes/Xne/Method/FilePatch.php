<?php
declare(strict_types=1);

namespace Xne\Method;

use Xne\Client\Guard;
use Xne\Fs;
use Xne\XneException;

/**
 * Verified file patches (owner Q6, the focus): the install's files are compared with the base (the tree of the
 * release it runs, else its share/filelist.md5) and with the target release.
 *
 *   local == target                      same, nothing to do
 *   missing locally                      add
 *   local == base                        update (an unmodified file)
 *   local differs from base (or no base) local edit: kept aside in the backup's kept/ folder, listed, then replaced
 *   in base, not in target, unmodified   remove: moved aside into the backup (never deleted)
 *   in base, not in target, modified     kept in place and listed
 *
 * Protected paths (settings/override, settings/siteaccess, var, config.php, .htaccess ...) are never written. A file
 * whose folder leads out of the install (a symlinked, shared extension folder) is "shared": the plan refuses the
 * upgrade unless --shared=skip (leave those files) or --shared=write (write through the link) is given.
 */
final class FilePatch
{
    /**
     * @param array<string,string> $target path => md5
     * @param array<string,string>|null $base path => md5, null when unknown
     * @param list<string> $protect
     * @return array<string,mixed>
     */
    public static function diff(string $root, array $target, ?array $base, array $protect, array $packages = []): array
    {
        $d = ['add' => [], 'update' => [], 'remove' => [], 'local_edits' => [], 'kept_retired' => [], 'protected' => [], 'shared' => [], 'links' => [], 'packages' => [], 'same' => 0];
        // the paths Composer owns (see ComposerMerge::packagePaths) are never written and never retired: the libraries stage owns them
        $isPackage = static function (string $p) use ($packages): bool {
            return $packages !== [] && ComposerMerge::under($p, $packages);
        };
        $isProtected = static function (string $p) use ($protect): bool {
            foreach ($protect as $x) {
                if ($p === $x || strncmp($p, rtrim($x, '/') . '/', strlen(rtrim($x, '/')) + 1) === 0) {
                    return true;
                }
            }
            return false;
        };
        $outside = [];
        $isShared = static function (string $p) use ($root, &$outside): bool {
            $dir = dirname($p);
            if (!isset($outside[$dir])) {
                $outside[$dir] = !Fs::inside($root, $root . '/' . $dir);
            }
            return $outside[$dir];
        };
        foreach ($target as $p => $t) {
            if ($p === '' || !Fs::safeRel($p)) {
                continue;
            }
            if ($isProtected($p)) {
                if ($t !== Fs::fingerprint($root . '/' . $p)) {
                    $d['protected'][] = $p;
                }
                continue;
            }
            if ($isPackage($p)) {
                if ($t !== Fs::fingerprint($root . '/' . $p)) {
                    $d['packages'][] = $p;
                } else {
                    $d['same']++;
                }
                continue;
            }
            $l = Fs::fingerprint($root . '/' . $p);
            if ($l === $t) {
                $d['same']++;
                continue;
            }
            if (strncmp($t, 'link:', 5) === 0 || ($l !== null && strncmp($l, 'link:', 5) === 0)) {
                if ($l === null) {
                    $d['add'][] = $p;
                } else {
                    $d['links'][] = $p;
                }
                continue;
            }
            if ($isShared($p)) {
                $d['shared'][] = $p;
                continue;
            }
            if ($l === null) {
                $d['add'][] = $p;
            } elseif ($base !== null && isset($base[$p]) && $base[$p] === $l) {
                $d['update'][] = $p;
            } else {
                $d['local_edits'][] = $p;
            }
        }
        if ($base !== null) {
            foreach ($base as $p => $b) {
                if (isset($target[$p]) || !Fs::safeRel($p) || $isProtected($p)) {
                    continue;
                }
                $l = Fs::fingerprint($root . '/' . $p);
                if ($l === null) {
                    continue;
                }
                if ($isPackage($p)) {
                    $d['packages'][] = $p;
                    continue;
                }
                if ($isShared($p)) {
                    $d['shared'][] = $p;
                } elseif ($l === $b) {
                    $d['remove'][] = $p;
                } else {
                    $d['kept_retired'][] = $p;
                }
            }
        }
        return $d;
    }

    /**
     * Writes the planned files from the release tree into the install. Every written file is read back and checked
     * against the target md5. The removed ones go into $aside (a backup folder). Files are owned like the install.
     * @param array<string,mixed> $diff
     * @param array<string,string> $target
     * @param array{uid: int, gid: int} $owner
     * @return array{written: int, removed: int}
     */
    public static function apply(string $root, string $tree, array $diff, array $target, string $aside, array $owner, bool $writeShared): array
    {
        $paths = array_merge($diff['add'], $diff['update'], $diff['local_edits'], $writeShared ? $diff['shared'] : []);
        $chown = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $n = 0;
        $links = [];
        foreach ($paths as $p) {
            if (!isset($target[$p])) {
                continue;
            }
            $src = $tree . '/' . $p;
            $dst = $root . '/' . $p;
            if (strncmp($target[$p], 'link:', 5) === 0) {
                $links[$p] = $dst;
                continue;
            }
            $created = [];
            for ($dir = dirname($dst); strlen($dir) > strlen($root) && !is_dir($dir); $dir = dirname($dir)) {
                $created[] = $dir;
            }
            Fs::atomicCopy($src, $dst, fileperms($src) & 0777);
            if (Fs::fingerprint($dst) !== $target[$p]) {
                throw new XneException("$p does not match the release after it was written");
            }
            if ($chown) {
                foreach (array_merge([$dst], $created) as $x) {
                    @chown($x, $owner['uid']);
                    @chgrp($x, $owner['gid']);
                }
            }
            $n++;
        }
        // links last: their targets are written by then (Windows needs the target to make a folder link)
        foreach ($links as $p => $dst) {
            if (!is_link($dst) && !file_exists($dst)) {
                Fs::mkdir(dirname($dst));
                if (!Fs::symlink(substr($target[$p], 5), $dst)) {
                    throw new XneException("cannot create the link $p");
                }
            }
            $n++;
        }
        $removed = 0;
        foreach ($diff['remove'] as $p) {
            Guard::guardedMoveAside($root, $p, $aside);
            $removed++;
        }
        return ['written' => $n, 'removed' => $removed];
    }
}
