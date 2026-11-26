<?php
declare(strict_types=1);

// The kernel's libraries stage reported pending with an unchanged composer.lock (vendor/ not what the lock names, as
// on a kickstarted install, the Oracle proof of 2026-11-24): exp:upgrade refuses the database stage until libraries
// ran. A fake kernel (upgrade.php and upgradestatus.php stand-ins that keep that order rule) checks that xne runs the
// stage first, with the library folders in the backup, and that a rollback gives back files, vendor/ and database.

/** Releases v6.0.14 and v6.0.15 with the same composer.lock and the fake kernel upgrade tools. */
function t_libs_world(string $name): array
{
    $s = t_scratch($name);
    $rel = $s . '/releases';
    $status = <<<'PHP'
<?php
// test stand-in for exp:upgrade:status: libraries is pending while vendor/installed.txt is not composer.lock
$lock = @file_get_contents('composer.lock');
$p = ($lock !== false && $lock !== @file_get_contents('vendor/installed.txt')) ? 1 : 0;
echo json_encode(['stages' => [['stage' => 'libraries', 'pending' => $p, 'steps' => [['id' => '6.0.15-libraries-install', 'state' => $p ? 'pending' : 'present']]],
    ['stage' => 'database', 'pending' => 0, 'steps' => []]]]), "\n";
exit(0);
PHP;
    $upgrade = <<<'PHP'
<?php
// test stand-in for exp:upgrade: the kernel's order rule (an earlier stage left out must have nothing pending)
$stages = [];
foreach ($argv as $a) {
    if (strpos($a, '--stages=') === 0) {
        $stages = explode(',', substr($a, 9));
    }
}
$pending = file_get_contents('composer.lock') !== @file_get_contents('vendor/installed.txt');
if ($pending && !in_array('libraries', $stages, true)) {
    echo json_encode(['errors' => ['the stage libraries has 1 pending step(s): run it first (or --force-order)'], 'stages' => []]), "\n";
    exit(1);
}
if (in_array('libraries', $stages, true)) {
    file_put_contents('vendor/installed.txt', file_get_contents('composer.lock'));
    @mkdir('vendor/acme', 0755, true);
    file_put_contents('vendor/acme/new.php', "<?php // installed by composer\n");
}
if (in_array('database', $stages, true)) {
    $pdo = new PDO('sqlite:var/storage/sqlite3/test.db');
    $pdo->exec("UPDATE ezsite_data SET value='6.0.15stable' WHERE name='ezpublish-version'");
}
echo json_encode(['summary' => ['applied' => count($stages)], 'stages' => []]), "\n";
exit(0);
PHP;
    $common = ['bin/php/upgrade.php' => $upgrade, 'bin/php/upgradestatus.php' => $status,
        'composer.json' => "{\"name\": \"test/site\"}\n", 'composer.lock' => "{\"packages\": [{\"name\": \"acme/lib\", \"version\": \"2.0.0\"}]}\n"];
    t_fake_release($rel, 'v6.0.14', 'tag', '6.0.14', $common + ['kernel/edited.php' => "<?php // 14\n"]);
    t_fake_release($rel, 'v6.0.15', 'tag', '6.0.15', $common + ['kernel/edited.php' => "<?php // 15\n", 'kernel/new.php' => "<?php // new\n"]);
    $env = ['XNE_STATE_DIR' => $s . '/state', 'XNE_CACHE_DIR' => $s . '/cache', 'XNE_SOURCES' => 'local', 'XNE_LOCAL_SOURCE' => $rel, 'XNE_CONF' => $s . '/none.conf'];
    $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
    // vendor/ still holds the libraries of an older lock: the kernel's libraries stage is pending
    \Xne\Fs::atomicWrite($root . '/vendor/installed.txt', "{\"packages\": [{\"name\": \"acme/lib\", \"version\": \"1.0.0\"}]}\n");
    \Xne\Fs::atomicWrite($root . '/vendor/acme/old.php', "<?php // acme/lib 1.0.0\n");
    return [$s, $root, $env];
}

return [
    'a pending libraries stage (same composer.lock) is planned first, in the kernel order' => static function (): void {
        [, $root, $env] = t_libs_world('libplan');
        [$x, , $err, $j] = t_xne(['plan', '--root=' . $root, '--json'], $env);
        t_same(0, $x, $err);
        $r = $j['result'];
        t_same('kernel', $r['database']['mode']);
        t_same(['libraries', 'database', 'scripts'], $r['database']['stages']);
        t_true(strpos((string) $r['database']['libraries_reason'], 'pending libraries') !== false, json_encode($r['database']));
        t_true($r['libraries']['needed'] === true && in_array('vendor', $r['libraries']['dirs'], true), 'vendor/ is backed up');
    },
    'upgrade runs libraries before database, then xne rollback gives back files, vendor/ and database exactly' => static function (): void {
        [, $root, $env] = t_libs_world('libup');
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, $out, $err, $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env);
        t_same(0, $x, $out . $err);
        $r = $j['result'];
        t_true($r['ok'] === true, json_encode($r));
        t_true((bool) preg_grep('/^kernel exp:upgrade libraries,database,scripts/', $r['steps']), json_encode($r['steps']));
        t_same(file_get_contents($root . '/composer.lock'), file_get_contents($root . '/vendor/installed.txt'), 'libraries installed');
        t_true(is_file($root . '/vendor/acme/new.php'));
        $pdo = new PDO('sqlite:' . $root . '/var/storage/sqlite3/test.db');
        t_same('6.0.15stable', $pdo->query("SELECT value FROM ezsite_data WHERE name='ezpublish-version'")->fetchColumn());
        [$x2, , $err2] = t_xne(['rollback', '--root=' . $root, '--json'], $env);
        t_same(0, $x2, $err2);
        t_same($tree, t_tree($root), 'files and vendor/ back exactly');
        t_same($db, t_db($root), 'database back exactly');
    },
    'a failure after the libraries stage rolls back vendor/ and database automatically' => static function (): void {
        [, $root, $env] = t_libs_world('libfail');
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, , , $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env + ['XNE_TEST_FAIL_AFTER' => 'health']);
        t_same(1, $x);
        t_true($j['result']['rolled_back'] === true, json_encode($j['result']));
        t_same($tree, t_tree($root), 'files and vendor/ back exactly');
        t_same($db, t_db($root), 'database back exactly');
    },
    'libraries=skip leaves the stage out (the kernel then refuses, and xne rolls back)' => static function (): void {
        [$s, $root, $env] = t_libs_world('libskip');
        file_put_contents($s . '/none.conf', "libraries=skip\n");
        $tree = t_tree($root);
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env);
        t_same(1, $x, $err);
        t_true(strpos((string) $j['result']['error'], 'libraries has 1 pending') !== false, (string) $j['result']['error']);
        t_same($tree, t_tree($root));
    },
];
