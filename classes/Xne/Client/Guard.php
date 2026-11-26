<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Fs;
use Xne\XneException;

/**
 * The only code in xne that removes or moves away files (tests/php/GuardTest.php fails on any unlink, rmdir or
 * rename-away anywhere else). Nothing of an install is ever deleted: a file an upgrade retires, or a file a rollback
 * takes back, is moved into the backup's "aside" folder, where it stays until the backup itself is rotated away.
 * Rotation removes only whole backup folders inside the xne state folder that carry xne's marker file.
 */
final class Guard
{
    public const MARKER = '.xne-backup';

    /** Removes one of xne's own temporary files ("<name>.xne-tmp-<pid>-<hex>"), nothing else. */
    public static function guardedRemoveTemp(string $path): void
    {
        if (!preg_match('/\.xne-tmp-\d+-[0-9a-f]{6}$/', $path)) {
            throw new XneException("refused to remove $path: not an xne temporary file");
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * Moves the install file $root/$rel to $asideRoot/$rel (inside a backup). The path must be safe and stay inside
     * the install (a symlinked folder that leads out of it is refused), and the aside folder must be a backup.
     */
    public static function guardedMoveAside(string $root, string $rel, string $asideRoot): void
    {
        if (!Fs::safeRel($rel)) {
            throw new XneException("refused to move $rel: unsafe path");
        }
        if (!is_file(dirname($asideRoot) . '/' . self::MARKER) && !is_file($asideRoot . '/' . self::MARKER)) {
            throw new XneException("refused to move $rel: $asideRoot is not inside an xne backup");
        }
        $src = $root . '/' . $rel;
        if (!is_file($src) && !is_link($src)) {
            return;
        }
        if (!Fs::inside($root, dirname($src))) {
            throw new XneException("refused to move $rel: its folder leads out of the install");
        }
        $dst = $asideRoot . '/' . $rel;
        Fs::mkdir(dirname($dst), 0700);
        if (file_exists($dst) || is_link($dst)) {
            $dst .= '.' . date('His') . '-' . bin2hex(random_bytes(2));
        }
        if (!@rename($src, $dst)) {
            if (is_link($src) || !@copy($src, $dst) || !@unlink($src)) {
                throw new XneException("cannot move $rel aside into the backup");
            }
        }
    }

    /**
     * Removes a whole backup folder during rotation: it must be inside $stateRoot (symlinks resolved) and carry the
     * marker file. Nothing outside the xne state folder can match.
     */
    public static function guardedRemoveBackup(string $stateRoot, string $dir): void
    {
        $real = realpath($dir);
        $state = realpath($stateRoot);
        if ($real === false || $state === false || $real === $state || !\Xne\Fs::under($state, $real)) {
            throw new XneException("refused to remove $dir: not inside the xne state folder");
        }
        if (!is_file($real . '/' . self::MARKER)) {
            throw new XneException("refused to remove $dir: no xne backup marker");
        }
        self::removeTree($real);
    }

    /** Removes a release cache folder (inside $cacheRoot, marked with .xne-release), e.g. a damaged extraction. */
    public static function guardedRemoveCache(string $cacheRoot, string $dir): void
    {
        $real = realpath($dir);
        $cache = realpath($cacheRoot);
        if ($real === false || $cache === false || $real === $cache || !\Xne\Fs::under($cache, $real) || !is_file($real . '/.xne-release')) {
            throw new XneException("refused to remove $dir: not a marked xne release cache");
        }
        self::removeTree($real);
    }

    private static function removeTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }
}
