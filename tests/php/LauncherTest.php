<?php
declare(strict_types=1);

// The launchers a user starts xne with: bin/xne on Linux and macOS, bin\xne.bat (cmd) and bin\xne.ps1 (PowerShell) on
// Windows. Each must start xne with the PHP on PATH or XNE_PHP, pass every argument through unchanged (also one with
// spaces) and give back xne's exit code.

$launchers = static function (): array {
    $home = dirname(__DIR__, 2);
    if (DIRECTORY_SEPARATOR !== '\\') {
        return ['bin/xne' => [PHP_BINARY, $home . '/bin/xne']];
    }
    $out = ['bin\\xne.bat' => [$home . '\\bin\\xne.bat']];
    foreach (['powershell', 'pwsh'] as $ps) {
        if (\Xne\Proc::which($ps) !== null) {
            $out["bin\\xne.ps1 ($ps)"] = [$ps, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $home . '\\bin\\xne.ps1'];
        }
    }
    return $out;
};

return [
    'every launcher starts xne, passes the arguments through and returns its exit code' => static function () use ($launchers): void {
        $s = t_scratch('launch');
        $root = t_fake_install($s . '/an install with spaces');
        $env = ['XNE_PHP' => PHP_BINARY, 'XNE_STATE_DIR' => $s . '/state', 'XNE_CACHE_DIR' => $s . '/cache', 'XNE_CONF' => $s . '/none.conf'];
        foreach ($launchers() as $name => $argv) {
            $r = \Xne\Proc::run(array_merge($argv, ['version', '--json']), null, $env, 300);
            t_same(0, $r['exit'], "$name version: " . $r['err']);
            $j = json_decode($r['out'], true);
            t_true(is_array($j) && $j['result']['version'] === \Xne\Console\App::VERSION, "$name version --json: " . $r['out']);
            // PowerShell 7 splits "--root=C:\\x" at the drive colon (it reads "-name:value" as a parameter); the
            // documented PowerShell form is "--root C:\\x", which every launcher takes
            $rootArgs = strpos($name, 'ps1') !== false ? ['--root', $root] : ['--root=' . $root];
            $r = \Xne\Proc::run(array_merge($argv, ['detect'], $rootArgs, ['--json']), null, $env, 300);
            t_same(0, $r['exit'], "$name detect: " . $r['out'] . $r['err']);
            $j = json_decode($r['out'], true);
            t_same('6.0.14', $j['result']['version'] ?? null, "$name detect --json");
            $r = \Xne\Proc::run(array_merge($argv, ['no-such-command']), null, $env, 300);
            t_same(2, $r['exit'], "$name: a usage error gives exit 2 through the launcher");
        }
    },
];
