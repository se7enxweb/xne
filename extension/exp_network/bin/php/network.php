#!/usr/bin/env php
<?php
/**
 * Exponential Network inside an install: runs xne for this installation.
 *
 *   php extension/exp_network/bin/php/network.php check|plan|upgrade|rollback|backups|traps|key:status [options]
 *   ./bin/php/console ext:exp_network:network check
 *
 * The engine is the xne package (pure PHP); this script only finds it and passes --root=<this install>. Where xne
 * lives: [NetworkSettings] XnePath in settings/override/xne.ini.append.php, else XNE_BIN, else xne on PATH, else
 * /usr/local/bin/xne. Exit codes are xne's: 0 PASS, 1 FAIL, 2 usage, 3 refused.
 *
 * The kernel console finds it as ext:exp_network:network and, through these aliases, as exp:network:<command>
 * (the console tells the script the name it was called by in EXP_CONSOLE_COMMAND):
 * @alias exp:network:check
 * @alias exp:network:plan
 * @alias exp:network:upgrade
 * @alias exp:network:rollback
 * @alias exp:network:backups
 * @alias exp:network:traps
 * @alias exp:network:detect
 * @alias exp:network:report
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
$root = dirname(__DIR__, 4);
$xne = '';
$ini = $root . '/settings/override/xne.ini.append.php';
if (is_file($ini) && preg_match('/^\s*XnePath=(\S+)/m', (string) file_get_contents($ini), $m)) {
    $xne = $m[1];
}
if ($xne === '' && getenv('XNE_BIN')) {
    $xne = (string) getenv('XNE_BIN');
}
if ($xne === '') {
    foreach (array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/local/bin']) as $d) {
        if ($d !== '' && is_file($d . '/xne')) {
            $xne = $d . '/xne';
            break;
        }
    }
}
if ($xne === '' || !is_file($xne)) {
    fwrite(STDERR, "FAIL: xne is not installed (set [NetworkSettings] XnePath or XNE_BIN)\n");
    exit(1);
}
$args = array_slice($argv, 1);
$called = (string) getenv('EXP_CONSOLE_COMMAND');
if (preg_match('/^exp:network:([a-z:-]+)$/', $called, $m)) {
    array_unshift($args, $m[1]);
}
if (!$args) {
    $args = ['help'];
}
$cmd = array_merge([PHP_BINARY, $xne], $args, ['--root=' . $root]);
$p = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit(is_resource($p) ? proc_close($p) : 1);
