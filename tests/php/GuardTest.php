<?php
declare(strict_types=1);

use Xne\Client\Guard;

/**
 * The safety grep: no code of xne removes or moves away a file outside the guarded functions of Guard.php. rename()
 * is allowed for atomic writes (Fs.php), downloads (Http.php) and the release cache (Release.php) only. No shell rm.
 */
return [
    'no unlink, rmdir or rm outside the guarded functions; rename only where it is atomic' => static function (): void {
        $home = dirname(__DIR__, 2);
        $files = array_merge(glob($home . '/lib/Xne/*.php'), glob($home . '/classes/Xne/*.php'), glob($home . '/classes/Xne/*/*.php'), [$home . '/bin/xne']);
        $renameOk = ['Fs.php', 'Http.php', 'Release.php', 'Guard.php'];
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            $base = basename($f);
            $toks = token_get_all($src);
            $fn = '';
            $depth = 0;
            $fnDepth = -1;
            $n = count($toks);
            for ($i = 0; $i < $n; $i++) {
                $t = $toks[$i];
                if (is_array($t) && $t[0] === T_FUNCTION) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                            $fn = $toks[$j][1];
                            $fnDepth = $depth;
                            break;
                        }
                        if ($toks[$j] === '(') {
                            break;
                        }
                    }
                }
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === $fnDepth) {
                        $fn = '';
                    }
                }
                if (!is_array($t) || $t[0] !== T_STRING) {
                    continue;
                }
                $name = strtolower($t[1]);
                $prev = $toks[$i - 1] ?? null;
                if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }
                if (in_array($name, ['unlink', 'rmdir', 'shell_exec', 'exec', 'system', 'passthru', 'popen'], true)) {
                    $guarded = $base === 'Guard.php' && strncmp($fn, 'guarded', 7) === 0 || ($base === 'Guard.php' && $fn === 'removeTree');
                    t_true($guarded && in_array($name, ['unlink', 'rmdir'], true), "$base: $name() in " . ($fn ?: 'file scope') . ' (only Guard::guarded* may remove files)');
                }
                if ($name === 'rename') {
                    t_true(in_array($base, $renameOk, true), "$base: rename() outside the atomic-write and guard code");
                }
            }
            t_true(!preg_match('/[\'"]rm[\'"]|[\'"]rm\s+-|\brm -rf\b/', $src), "$base: names the rm program");
        }
    },
    'the guard refuses what is not its own' => static function (): void {
        $s = t_scratch('guard');
        file_put_contents($s . '/keep.txt', 'x');
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            Guard::guardedRemoveTemp($s . '/keep.txt');
        }, 'not a temp file');
        \Xne\Fs::mkdir($s . '/state/b1');
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            Guard::guardedRemoveBackup($s . '/state', $s . '/state/b1');
        }, 'no marker');
        \Xne\Fs::mkdir($s . '/other');
        file_put_contents($s . '/other/' . Guard::MARKER, 'x');
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            Guard::guardedRemoveBackup($s . '/state', $s . '/other');
        }, 'outside the state folder');
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            Guard::guardedMoveAside($s, '../x', $s . '/state/b1/aside');
        }, 'unsafe path');
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            Guard::guardedMoveAside($s, 'keep.txt', $s . '/other2/aside');
        }, 'aside not in a backup');
        t_true(is_file($s . '/keep.txt') && is_dir($s . '/state/b1') && is_dir($s . '/other'), 'nothing was removed');
    },
];
