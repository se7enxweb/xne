<?php
declare(strict_types=1);

namespace Xne;

/**
 * File helpers that never remove anything. Writes are atomic: a temporary file next to the target, then rename()
 * over it, so a reader sees the old or the new file, never half of one. Removing a file is not here on purpose:
 * only the guarded functions of Xne\Client\Guard remove or move away files (tests/php/GuardTest.php checks it).
 */
final class Fs
{
    public static function join(string ...$parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $out = $out === '' ? $p : rtrim($out, '/\\') . '/' . ltrim($p, '/\\');
        }
        return $out;
    }

    public static function mkdir(string $dir, int $mode = 0755): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new XneException("cannot create the folder $dir");
        }
    }

    /** A relative path is safe when it has no "..", no absolute start, no NUL and no backslash tricks. */
    public static function safeRel(string $rel): bool
    {
        if ($rel === '' || strpos($rel, "\0") !== false || $rel[0] === '/' || $rel[0] === '\\' || preg_match('#^[A-Za-z]:#', $rel)) {
            return false;
        }
        foreach (preg_split('#[/\\\\]#', $rel) as $seg) {
            if ($seg === '..') {
                return false;
            }
        }
        return true;
    }

    /** True when $path, with every symlink resolved, is inside $root (also resolved). A missing tail is allowed. */
    public static function inside(string $root, string $path): bool
    {
        $r = realpath($root);
        if ($r === false) {
            return false;
        }
        $p = $path;
        $tail = '';
        while (!file_exists($p) && !is_link($p)) {
            $tail = '/' . basename($p) . $tail;
            $parent = dirname($p);
            if ($parent === $p) {
                return false;
            }
            $p = $parent;
        }
        $rp = realpath($p);
        if ($rp === false) {
            return false;
        }
        return self::under($r, self::slashes($rp) . $tail);
    }

    /** A path with forward slashes only (Windows' realpath() answers with backslashes); unchanged elsewhere. */
    public static function slashes(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $path) : $path;
    }

    /** True when $path is $root or below it (both already resolved). Either separator; case-insensitive on Windows. */
    public static function under(string $root, string $path): bool
    {
        $root = rtrim(self::slashes($root), '/');
        $path = self::slashes($path);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }
        return $path === $root || strncmp($path, $root . '/', strlen($root) + 1) === 0;
    }

    public static function tmpName(string $path): string
    {
        return $path . '.xne-tmp-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    /** Atomic write of $data to $path; $mode null keeps the old file's mode (or 0644 for a new one). */
    public static function atomicWrite(string $path, string $data, ?int $mode = null): void
    {
        self::mkdir(dirname($path));
        if ($mode === null) {
            $mode = is_file($path) ? (fileperms($path) & 0777) : 0644;
        }
        $tmp = self::tmpName($path);
        if (@file_put_contents($tmp, $data) !== strlen($data)) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException("cannot write $path");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException("cannot replace $path");
        }
    }

    /** Atomic copy of the file $src to $dst (streamed, so a large file is never held in memory). */
    public static function atomicCopy(string $src, string $dst, ?int $mode = null): void
    {
        self::mkdir(dirname($dst));
        if ($mode === null) {
            $mode = fileperms($src) & 0777;
        }
        $tmp = self::tmpName($dst);
        if (!@copy($src, $tmp)) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException("cannot copy $src to $dst");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $dst)) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException("cannot replace $dst");
        }
    }


    /** Atomic write of one tar entry's data to $dst; returns its md5. */
    public static function atomicFromTar(TarStream $data, string $dst, int $mode): string
    {
        self::mkdir(dirname($dst));
        $tmp = self::tmpName($dst);
        try {
            $md5 = $data->copyTo($tmp);
        } catch (\Throwable $e) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw $e;
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $dst)) {
            Client\Guard::guardedRemoveTemp($tmp);
            throw new XneException("cannot replace $dst");
        }
        return $md5;
    }
    public static function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new XneException("cannot read $path");
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            throw new XneException("$path is not valid JSON");
        }
        return $d;
    }

    public static function writeJson(string $path, array $data, int $mode = 0600): void
    {
        self::atomicWrite($path, Json::encode($data) . "\n", $mode);
    }

    /**
     * Every regular file and symlink under $dir as relative paths (sorted). Symlinked folders are listed as links and
     * never followed. $skip(rel, isDir) returning true leaves an entry (and a folder's content) out.
     * @return list<string>
     */
    public static function walk(string $dir, ?callable $skip = null): array
    {
        $out = [];
        $stack = [''];
        while ($stack) {
            $rel = array_pop($stack);
            $abs = $rel === '' ? $dir : $dir . '/' . $rel;
            $h = @opendir($abs);
            if ($h === false) {
                continue;
            }
            while (($e = readdir($h)) !== false) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $r = $rel === '' ? $e : $rel . '/' . $e;
                $a = $dir . '/' . $r;
                $isDir = !is_link($a) && is_dir($a);
                if ($skip !== null && $skip($r, $isDir)) {
                    continue;
                }
                if ($isDir) {
                    $stack[] = $r;
                } else {
                    $out[] = $r;
                }
            }
            closedir($h);
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Creates the symbolic link $link to $target (as stored in a release: usually relative to the link's folder).
     * Windows resolves a relative target against the current folder to decide between a file and a folder link, so
     * the link's own folder is made current for the call there.
     */
    public static function symlink(string $target, string $link): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return @symlink($target, $link);
        }
        $abs = realpath(dirname($link) . '/' . $target);
        if ($abs !== false && is_dir($abs)) {
            // a folder: a junction, which every account may create and every program follows like the folder
            $r = Proc::run([(string) (getenv('ComSpec') ?: 'cmd.exe'), '/d', '/c', 'mklink', '/J', str_replace('/', '\\', $link), $abs], null, null, 60);
            return $r['exit'] === 0 && is_dir($link);
        }
        $cwd = getcwd();
        $ok = false;
        if (@chdir(dirname($link))) {
            $ok = @symlink(str_replace('/', '\\', $target), basename($link));
        }
        if ($cwd !== false) {
            @chdir($cwd);
        }
        return $ok;
    }

    /**
     * The target of a symbolic link as written into it, with forward slashes. Windows' readlink() answers with the
     * resolved absolute path; a target inside the link's own tree is given back relative to the link's folder, as
     * the release has it.
     */
    public static function readlink(string $link): string
    {
        $t = (string) @readlink($link);
        if (DIRECTORY_SEPARATOR !== '\\' || $t === '') {
            return $t;
        }
        if (strncmp($t, '\\\\?\\', 4) === 0) {
            $t = substr($t, 4);
        }
        $t = self::slashes($t);
        if (!preg_match('#^[A-Za-z]:/#', $t)) {
            return $t;
        }
        $from = explode('/', self::slashes((string) (realpath(dirname($link)) ?: dirname($link))));
        $to = explode('/', $t);
        $i = 0;
        while ($i < count($from) && $i < count($to) && strcasecmp($from[$i], $to[$i]) === 0) {
            $i++;
        }
        if ($i === 0) {
            return $t;
        }
        return implode('/', array_merge(array_fill(0, count($from) - $i, '..'), array_slice($to, $i)));
    }

    /** The md5 of a file, or "link:<target>" for a symlink, or null when it does not exist. */
    public static function fingerprint(string $path): ?string
    {
        if (is_link($path)) {
            return 'link:' . self::readlink($path);
        }
        if (!is_file($path)) {
            return null;
        }
        $m = @md5_file($path);
        return $m === false ? null : $m;
    }

    public static function humanBytes(float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < 4) {
            $b /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $b : number_format($b, 1)) . ' ' . $u[$i];
    }
}
