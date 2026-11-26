<?php
declare(strict_types=1);

// End to end on fake releases (a local release folder) and an SQLite install: plan, dry run, upgrade, local edits,
// rollback, forced failures with automatic rollback, traps, rotation and --json on every command. No network.

return [
    'plan --json: target, file counts, SQL file, valid JSON' => static function (): void {
        [$s, $rel, $env] = t_world('plan');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        file_put_contents($root . '/kernel/edited.php', "<?php // edited on the server\n");
        [$x, $out, $err, $j] = t_xne(['plan', '--root=' . $root, '--json'], $env);
        t_same(0, $x, $err);
        $r = $j['result'];
        t_same('6.0.15', $r['target']['version']);
        t_same('release v6.0.14', $r['base']);
        t_same(['kernel/edited.php'], $r['local_edits']);
        t_same(1, $r['files']['remove'], 'kernel/old.php retires');
        t_same(['update/database/sqlite/6.0/dbupdate-6.0.0-6.0.15.sql'], $r['database']['sql_files']);
        t_true(!isset($r['diff']), 'the raw diff stays internal');
        t_same('local', $r['target']['source']);
    },
    'upgrade --dry-run changes nothing' => static function (): void {
        [$s, $rel, $env] = t_world('dry');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--dry-run', '--json'], $env);
        t_same(0, $x, $err);
        t_true($j['result']['dry_run'] === true);
        t_same($tree, t_tree($root));
        t_same($db, t_db($root));
        t_true(!is_dir($s . '/state/installs'), 'no backup, no lock');
    },
    'upgrade by file patch, local edit kept aside, then xne rollback gives back files and database' => static function (): void {
        [$s, $rel, $env] = t_world('up');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        file_put_contents($root . '/kernel/edited.php', "<?php // edited on the server\n");
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, $out, $err, $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env);
        t_same(0, $x, $out . $err);
        $r = $j['result'];
        t_true($r['ok']);
        t_same(['kernel/edited.php'], $r['kept_aside']);
        t_same("<?php // edited on the server\n", file_get_contents($r['backup']['dir'] . '/kept/kernel/edited.php'));
        t_same("<?php // 15\n", file_get_contents($root . '/kernel/edited.php'));
        t_true(!is_file($root . '/kernel/old.php') && is_file($r['backup']['dir'] . '/aside/kernel/old.php'), 'retired file moved aside');
        t_true(is_file($root . '/kernel/new.php'));
        $pdo = new PDO('sqlite:' . $root . '/var/storage/sqlite3/test.db');
        t_same('6.0.15stable', $pdo->query("SELECT value FROM ezsite_data WHERE name='ezpublish-version'")->fetchColumn());
        [$x2, , $err2, $j2] = t_xne(['rollback', '--root=' . $root, '--json'], $env);
        t_same(0, $x2, $err2);
        t_same($tree, t_tree($root), 'files back exactly');
        t_same($db, t_db($root), 'database back exactly');
        t_true(is_file($r['backup']['dir'] . '/aside-rollback/kernel/new.php'), 'the added file is aside, not deleted');
        [$x3, , , $j3] = t_xne(['backups', '--root=' . $root, '--json'], $env);
        t_same(0, $x3);
        t_same('rolled-back', $j3['result']['backups'][0]['state']);
    },
    'a failing DB update script rolls back files and database automatically' => static function (): void {
        [$s, $rel, $env] = t_world('dbfail');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, $out, $err, $j] = t_xne(['upgrade', '--root=' . $root, '--to=v6.0.16beta1', '--json'], $env);
        t_same(1, $x, 'a failed upgrade exits 1');
        $r = $j['result'];
        t_true(strpos($r['error'], 'no_such_table') !== false, $r['error'] ?? '');
        t_true($r['rolled_back'] === true, json_encode($r));
        t_true($r['rollback']['database'] === true);
        t_same($tree, t_tree($root), 'files back exactly');
        t_same($db, t_db($root), 'database back exactly (xne_ok is gone)');
    },
    'a failure after files, finish or health rolls back too' => static function (): void {
        foreach (['files', 'finish', 'health'] as $step) {
            [$s, $rel, $env] = t_world('fail' . $step);
            $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
            $tree = t_tree($root);
            $db = t_db($root);
            [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env + ['XNE_TEST_FAIL_AFTER' => $step]);
            t_same(1, $x, $step);
            t_true($j['result']['rolled_back'] === true, $step);
            t_same($tree, t_tree($root), "$step: files");
            t_same($db, t_db($root), "$step: database");
        }
    },
    'a kernel tool that fails (cache clear) rolls back' => static function (): void {
        [$s, $rel, $env] = t_world('ezfail');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $tree = t_tree($root);
        [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env + ['XNE_TEST_EZCACHE_FAIL' => '1']);
        t_same(1, $x);
        t_true(strpos($j['result']['error'], 'ezcache') !== false, $j['result']['error']);
        t_same($tree, t_tree($root));
    },
    'a blocking trap refuses (exit 3) until it is accepted' => static function (): void {
        [$s, $rel, $env] = t_world('trap');
        $root = t_install_from($rel, 'v6.0.14', $s . '/acct/public_html/site', '6.0.14');
        \Xne\Fs::atomicWrite($s . '/acct/public_html/lib/version.php', (string) file_get_contents($root . '/lib/version.php'));
        $tree = t_tree($root);
        [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env);
        t_same(3, $x);
        t_true(strpos(json_encode($j), 'public-lib-copy') !== false);
        t_same($tree, t_tree($root));
        [$x2] = t_xne(['plan', '--root=' . $root, '--accept=public-lib-copy', '--json'], $env);
        t_same(0, $x2);
    },
    'a database implementation the target kernel does not know is a blocking trap' => static function (): void {
        [$s, $rel, $env] = t_world('impl');
        t_fake_release($rel, 'v6.0.17', 'tag', '6.0.17', ['settings/site.ini' => "[DatabaseSettings]\nImplementationAlias[]\nImplementationAlias[sqlite3]=eZSQLite3DB\nImplementationAlias[ezmysqli]=eZMySQLiDB\n"]);
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        [$x, , , $j] = t_xne(['plan', '--root=' . $root, '--to=v6.0.17', '--json'], $env);
        t_same(3, $x);
        t_true(strpos(json_encode($j['result']['blocked']), 'database-implementation') !== false, json_encode($j['result']['blocked']));
    },
    'exp_network: the extension script runs xne for its own install' => static function (): void {
        [$s, $rel, $env] = t_world('ext');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        \Xne\Fs::atomicCopy(dirname(__DIR__, 2) . '/extension/exp_network/bin/php/network.php', $root . '/extension/exp_network/bin/php/network.php');
        $r = \Xne\Proc::run([PHP_BINARY, 'extension/exp_network/bin/php/network.php', 'plan', '--json'], $root, $env + ['XNE_BIN' => dirname(__DIR__, 2) . '/bin/xne']);
        t_same(0, $r['exit'], $r['out'] . $r['err']);
        $j = json_decode($r['out'], true);
        t_same(\Xne\Fs::slashes((string) realpath($root)), \Xne\Fs::slashes($j['result']['root']));
        t_same('6.0.15', $j['result']['target']['version']);
        $r = \Xne\Proc::run([PHP_BINARY, 'extension/exp_network/bin/php/network.php', '--json', '--quiet'], $root, $env + ['XNE_BIN' => dirname(__DIR__, 2) . '/bin/xne', 'EXP_CONSOLE_COMMAND' => 'exp:network:traps']);
        t_same(0, $r['exit'], $r['out'] . $r['err']);
        t_same('traps', json_decode($r['out'], true)['command'], 'exp:network:traps runs xne traps');
    },
    'an alias an active extension registers (ezoracle) satisfies the implementation trap' => static function (): void {
        [$s, $rel, $env] = t_world('implext');
        t_fake_release($rel, 'v6.0.17', 'tag', '6.0.17', ['settings/site.ini' => "[DatabaseSettings]\nImplementationAlias[]\nImplementationAlias[sqlite3]=eZSQLite3DB\n"]);
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        \Xne\Fs::atomicWrite($root . '/extension/myengine/settings/site.ini.append.php', "<?php /*\n[DatabaseSettings]\nImplementationAlias[ezsqlite3]=eZSQLite3DB\n*/ ?>\n");
        [$x] = t_xne(['plan', '--root=' . $root, '--to=v6.0.17'], $env);
        t_same(3, $x, 'not active: still blocked');
        \Xne\Fs::atomicWrite($root . '/settings/override/site.ini.append.php', (string) file_get_contents($root . '/settings/override/site.ini.append.php') . "<?php /*\n[ExtensionSettings]\nActiveExtensions[]=myengine\n*/ ?>\n");
        [$x, $out, $err] = t_xne(['plan', '--root=' . $root, '--to=v6.0.17'], $env);
        t_same(0, $x, $out . $err);
    },
    'channel main warns, stable channel 6.0.13 -> 6.0.15, up to date answers PASS' => static function (): void {
        [$s, $rel, $env] = t_world('chan');
        $root = t_install_from($rel, 'v6.0.13', $s . '/site', '6.0.13');
        [, , , $j] = t_xne(['plan', '--root=' . $root, '--channel=main', '--json'], $env);
        t_true(strpos(implode(' ', $j['result']['warnings']), 'nightly') !== false);
        t_same('main', $j['result']['target']['ref']);
        [$x, $out, $err] = t_xne(['upgrade', '--root=' . $root, '--to=v6.0.15'], $env);
        t_same(0, $x, $out . $err);
        t_true(strpos($out, 'PASS: upgraded 6.0.13 -> 6.0.15') !== false, $out);
        [$x2, , , $j2] = t_xne(['upgrade', '--root=' . $root, '--channel=security', '--json'], $env);
        t_same(0, $x2);
        t_true($j2['result']['up_to_date'] === true);
    },
    'backups rotate to backup_keep, newest kept' => static function (): void {
        [$s, $rel, $env] = t_world('rot');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $env['XNE_BACKUP_KEEP'] = '2';
        foreach (['v6.0.15', 'v6.0.15', 'v6.0.15'] as $i => $to) {
            file_put_contents($root . '/kernel/same.php', "<?php // drift $i\n");
            [$x, $out, $err] = t_xne(['upgrade', '--root=' . $root, '--to=' . $to], $env);
            t_same(0, $x, $out . $err);
            sleep(1);
        }
        [, , , $j] = t_xne(['backups', '--root=' . $root, '--json'], $env);
        t_same(2, count($j['result']['backups']));
        [$x2] = t_xne(['verify-backup', '--root=' . $root, '--json'], $env);
        t_same(0, $x2);
    },
    'every command answers --json with one valid object' => static function (): void {
        [$s, $rel, $env] = t_world('json');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        foreach ([['detect'], ['traps'], ['config'], ['sources'], ['backups'], ['db-fingerprint'], ['plan'], ['backup', '--dry-run'], ['backup'], ['verify-backup'],
            ['rollback', '--dry-run'], ['help'], ['help', 'plan'], ['version'], ['nope'], ['plan', '--channel=nightly']] as $a) {
            [$x, $out, , $j] = t_xne(array_merge($a, ['--root=' . $root, '--json']), $env);
            t_same('xne', $j['tool'], implode(' ', $a));
            t_same($x, $j['exit'], implode(' ', $a));
            t_same($x === 0, $j['ok']);
        }
        [$x] = t_xne(['nope', '--root=' . $root], $env);
        t_same(2, $x, 'unknown command is a usage error');
        [, $out] = t_xne(['config', '--root=' . $root, '--json'], $env + ['XNE_GITHUB_TOKEN_FILE' => '/nonexistent/.config/never-shown']);
        t_true(strpos($out, 'Password') === false);
    },
    'xne runs on PHP 7.4 (plan + upgrade + rollback)' => static function (): void {
        $php = '/opt/plesk/php/7.4/bin/php';
        if (!is_file($php)) {
            echo "      (skipped here: no $php)\n";
            return;
        }
        [$s, $rel, $env] = t_world('php74');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $tree = t_tree($root);
        $env['XNE_PHP'] = PHP_BINARY;
        $env['XNE_REEXEC'] = '1'; // xne itself stays on PHP 7.4; only the install's tools use XNE_PHP
        [$x, $out, $err] = t_xne(['upgrade', '--root=' . $root], $env, $php);
        t_same(0, $x, $out . $err);
        [$x2, $out2, $err2] = t_xne(['rollback', '--root=' . $root], $env, $php);
        t_same(0, $x2, $out2 . $err2);
        t_same($tree, t_tree($root));
    },
    '--shared=write: a failure after the files restores the shared folder through its link (recorded in the backup)' => static function (): void {
        [$s, $rel, $env] = t_world_shared('sharedfail');
        $root = t_install_shared($rel, $s);
        $tree = t_tree($root);
        [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env);
        t_same(3, $x, 'without --shared the shared folder blocks');
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--shared=write', '--json'], $env + ['XNE_TEST_FAIL_AFTER' => 'files']);
        t_same(1, $x, $err);
        t_true($j['result']['rolled_back'] === true, json_encode($j['result']));
        t_true(!isset($j['result']['rollback_error']), 'no rollback error');
        t_same("<?php // ext 14\n", file_get_contents($s . '/elsewhere/shx/a.php'), 'the shared file is back');
        t_same($tree, t_tree($root));
    },
    'an older backup of a --shared=write upgrade: rollback refuses without --shared=write and changes nothing, restores with it' => static function (): void {
        [$s, $rel, $env] = t_world_shared('sharedold');
        $root = t_install_shared($rel, $s);
        $tree = t_tree($root);
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--shared=write', '--json'], $env);
        t_same(0, $x, $err);
        t_same("<?php // ext 15\n", file_get_contents($s . '/elsewhere/shx/a.php'), 'written through the link');
        $bj = $j['result']['backup']['dir'] . '/backup.json';
        $b = \Xne\Fs::readJson($bj);
        t_true(!empty($b['shared_write']) && in_array('extension/shx/a.php', $b['shared_files'], true), 'the backup records the shared files');
        unset($b['shared_write'], $b['shared_files']);
        \Xne\Fs::writeJson($bj, $b);
        $after = t_tree($root);
        [$x, , , $j2] = t_xne(['rollback', '--root=' . $root, '--json'], $env);
        t_same(3, $x, json_encode($j2));
        t_same($after, t_tree($root), 'refused before any change');
        t_same("<?php // ext 15\n", file_get_contents($s . '/elsewhere/shx/a.php'));
        [$x, , $err] = t_xne(['rollback', '--root=' . $root, '--shared=write', '--json'], $env);
        t_same(0, $x, $err);
        t_same("<?php // ext 14\n", file_get_contents($s . '/elsewhere/shx/a.php'));
        t_same($tree, t_tree($root));
    },
    'a rollback run as root gives the restored files back to the install owner (recorded in the backup)' => static function (): void {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            t_true(true, 'needs root; skipped');
            return;
        }
        [$s, $rel, $env] = t_world('ownerback');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        \Xne\Proc::run(['chown', '-R', 'nobody', $root]);
        $nobody = (int) posix_getpwnam('nobody')['uid'];
        $tree = t_tree($root);
        [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env + ['XNE_TEST_FAIL_AFTER' => 'files']);
        t_same(1, $x);
        t_true($j['result']['rolled_back'] === true, json_encode($j['result']));
        t_same($tree, t_tree($root));
        $b = \Xne\Fs::readJson($j['result']['backup']['dir'] . '/backup.json');
        t_same($nobody, (int) $b['owner']['uid'], 'the backup records the owner');
        $wrong = [];
        foreach (\Xne\Fs::walk($root, static function (string $r, bool $isDir) {
            return false;
        }) as $r) {
            if (fileowner($root . '/' . $r) !== $nobody) {
                $wrong[] = $r;
            }
        }
        t_same([], $wrong, 'every file is owned by the install owner after the rollback');
        t_same($nobody, fileowner($root . '/index.php'));
    },
    'a release file that is a link in the install (capture layout) is left as a link and passes the health check' => static function (): void {
        [$s, $rel, $env] = t_world('linkhealth');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        \Xne\Fs::mkdir($s . '/arc');
        rename($root . '/kernel/same.php', $s . '/arc/same.php');
        file_put_contents($s . '/arc/same.php', "<?php // the site's own copy\n");
        symlink($s . '/arc/same.php', $root . '/kernel/same.php');
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(0, $x, $err . json_encode($j['result'] ?? null));
        t_true(is_link($root . '/kernel/same.php'), 'still a link');
        t_same("<?php // the site's own copy\n", file_get_contents($s . '/arc/same.php'), 'the linked file is untouched');
    },
    'the health check names the files that differ from the manifest' => static function (): void {
        [$s, $rel, $env] = t_world('healthnames');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        // a cache clear that rewrites a release file after the patch (as a kernel step could)
        $env['XNE_TEST_EZCACHE_TOUCH'] = $root . '/kernel/same.php';
        [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(1, $x);
        t_true(strpos((string) $j['result']['error'], 'kernel/same.php') !== false, (string) $j['result']['error']);
        t_true($j['result']['rolled_back'] === true);
    },
];
