<?php
declare(strict_types=1);

namespace Xne;

/**
 * Runs a program with an argument list (never a shell string, so nothing is ever quoted or interpreted), in a given
 * folder, with an optional environment and a time limit. Used only for the install's own tools (the kernel's PHP
 * scripts, git, composer); the engine itself needs no external program.
 */
final class Proc
{
    /** @var list<array{argv: list<string>, exit: int, ms: int}> the commands run, for --json reports and tests */
    public static $log = [];

    /**
     * @param list<string> $argv
     * @param array<string,string>|null $env extra variables added to the current environment
     * @return array{exit: int, out: string, err: string, ms: int, timeout: bool}
     */
    public static function run(array $argv, ?string $cwd = null, ?array $env = null, int $timeout = 3600, ?string $stdin = null): array
    {
        $t0 = microtime(true);
        $fullEnv = null;
        if ($env !== null) {
            $fullEnv = [];
            foreach (getenv() as $k => $v) {
                $fullEnv[$k] = $v;
            }
            foreach ($env as $k => $v) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    // Windows names are case-insensitive ("Path" and "PATH" are one variable): replace, never add a twin
                    foreach (array_keys($fullEnv) as $have) {
                        if (strcasecmp((string) $have, (string) $k) === 0 && $have !== $k) {
                            unset($fullEnv[$have]);
                        }
                    }
                }
                $fullEnv[$k] = $v;
            }
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            // CreateProcess finds only NAME.exe on PATH; a shell also finds NAME.bat and NAME.cmd (composer, git wrappers)
            if ($argv && strpbrk($argv[0], '/\\') === false) {
                $argv[0] = self::which($argv[0]) ?? $argv[0];
            }
            return self::runWindows($argv, $cwd, $fullEnv, $timeout, $stdin, $t0);
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($argv, $spec, $pipes, $cwd, $fullEnv);
        if (!is_resource($p)) {
            self::$log[] = ['argv' => $argv, 'exit' => 127, 'ms' => 0];
            return ['exit' => 127, 'out' => '', 'err' => 'cannot start ' . $argv[0], 'ms' => 0, 'timeout' => false];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $timedOut = false;
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            $n = @stream_select($r, $w, $e, 1);
            if ($n) {
                foreach ($r as $s) {
                    $chunk = fread($s, 65536);
                    if ($chunk !== false && $chunk !== '') {
                        if ($s === $pipes[1]) {
                            $out .= $chunk;
                        } else {
                            $err .= $chunk;
                        }
                    }
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) - $t0 > $timeout) {
                $timedOut = true;
                proc_terminate($p, 15);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($timedOut) {
            $code = 124;
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        self::$log[] = ['argv' => $argv, 'exit' => $code, 'ms' => $ms];
        return ['exit' => $code, 'out' => $out, 'err' => $err, 'ms' => $ms, 'timeout' => $timedOut];
    }

    /**
     * Windows: stream_select() and non-blocking reads do not work on process pipes there, so a command whose output
     * fills a pipe would block, and one that runs long could never be timed out. Output and errors go to two of xne's
     * temporary files instead; the process is polled until it ends or its time is up.
     *
     * @param list<string> $argv
     * @param array<string,string>|null $env
     * @return array{exit: int, out: string, err: string, ms: int, timeout: bool}
     */
    private static function runWindows(array $argv, ?string $cwd, ?array $env, int $timeout, ?string $stdin, float $t0): array
    {
        $tmp = rtrim(sys_get_temp_dir(), '/\\');
        $outFile = Fs::tmpName($tmp . DIRECTORY_SEPARATOR . 'xne-out');
        $errFile = Fs::tmpName($tmp . DIRECTORY_SEPARATOR . 'xne-err');
        $spec = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
        $p = @proc_open($argv, $spec, $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (!is_resource($p)) {
            Client\Guard::guardedRemoveTemp($outFile);
            Client\Guard::guardedRemoveTemp($errFile);
            self::$log[] = ['argv' => $argv, 'exit' => 127, 'ms' => 0];
            return ['exit' => 127, 'out' => '', 'err' => 'cannot start ' . $argv[0], 'ms' => 0, 'timeout' => false];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $timedOut = false;
        $code = -1;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) {
                $code = (int) $st['exitcode'];
                break;
            }
            if (microtime(true) - $t0 > $timeout) {
                $timedOut = true;
                proc_terminate($p);
                break;
            }
            usleep(20000);
        }
        $closed = proc_close($p);
        if ($code === -1) {
            $code = $closed;
        }
        if ($timedOut) {
            $code = 124;
        }
        $out = (string) @file_get_contents($outFile);
        $err = (string) @file_get_contents($errFile);
        Client\Guard::guardedRemoveTemp($outFile);
        Client\Guard::guardedRemoveTemp($errFile);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        self::$log[] = ['argv' => $argv, 'exit' => $code, 'ms' => $ms];
        return ['exit' => $code, 'out' => $out, 'err' => $err, 'ms' => $ms, 'timeout' => $timedOut];
    }

    /** The first executable of that name on PATH, or null. Pure PHP (no "which"). */
    public static function which(string $name): ?string
    {
        if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return is_file($name) && is_executable($name) ? $name : null;
        }
        $win = DIRECTORY_SEPARATOR === '\\';
        // Windows: the extensions of PATHEXT, in its order (PHP's is_executable() is false for a .bat or .cmd there)
        $exts = $win ? array_values(array_filter(array_map('strtolower', explode(';', (string) (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'))), 'strlen')) : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            foreach ($exts as $x) {
                $f = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $name . $x;
                if ($d !== '' && is_file($f) && ($win || is_executable($f))) {
                    return $f;
                }
            }
        }
        return null;
    }
}
