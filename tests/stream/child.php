<?php
declare(strict_types=1);

// Child of DbStreamTest: one dump, verify or restore of a real database, run with a small memory_limit. The
// connection settings come from the environment (XNE_STREAM_SETTINGS, JSON with the password), never the command
// line. Prints one JSON line: the step's result, PHP's peak memory and the process's peak RSS (VmHWM, Linux).
// Usage: php -d memory_limit=128M tests/stream/child.php dump|verify|restore|fingerprint <file>
$home = dirname(__DIR__, 2);
require $home . '/lib/Xne/Autoload.php';
\Xne\Autoload::register($home);

$step = $argv[1] ?? '';
$file = $argv[2] ?? '';
$s = json_decode((string) getenv('XNE_STREAM_SETTINGS'), true);
if (!is_array($s) || $file === '') {
    fwrite(STDERR, "usage: XNE_STREAM_SETTINGS=<json> php tests/stream/child.php dump|verify|restore|fingerprint <file>\n");
    exit(2);
}
$d = \Xne\Db\Factory::driver($s);
$t0 = microtime(true);
$out = ['step' => $step];
switch ($step) {
    case 'dump':
        $meta = $d->dump($file);
        file_put_contents($file . '.json', json_encode($meta));
        $out['rows'] = array_sum($meta['tables']);
        $out['bytes'] = $meta['bytes'];
        break;
    case 'verify':
        \Xne\Db\Driver::verifyDump($file, json_decode((string) file_get_contents($file . '.json'), true));
        break;
    case 'restore':
        $d->restore($file);
        break;
    case 'fingerprint':
        $out['fingerprint'] = $d->fingerprint();
        break;
    default:
        fwrite(STDERR, "unknown step $step\n");
        exit(2);
}
$out['seconds'] = round(microtime(true) - $t0, 1);
$out['php_peak'] = memory_get_peak_usage(true);
$out['memory_limit'] = ini_get('memory_limit');
$st = @file_get_contents('/proc/self/status');
if (is_string($st) && preg_match('/^VmHWM:\s+(\d+) kB/m', $st, $m)) {
    $out['rss_peak'] = (int) $m[1] * 1024;
}
echo json_encode($out), "\n";
