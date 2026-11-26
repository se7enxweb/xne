<?php
declare(strict_types=1);

// Integration on real Exponential releases from GitHub, on throwaway installs with throwaway databases
// (XNE_IT_DIR). 6.0.14 -> the latest 6.0.x (the main branch, 6.0.15) by file patch on SQLite, MySQL and PostgreSQL
// and by git; 6.0.13 -> 6.0.14 on the stable channel; a real failing database step that must roll back.

$x = static function (array $argv, string $name, array $env = []): array {
    return t_xne($argv, Fixture::env($name) + $env, Fixture::php());
};
$version = static function (string $root): string {
    return (string) \Xne\Version::fromTree($root);
};

return [
    'seeds: v6.0.14 and v6.0.13 files (composer install) and the v6.0.14 git clone' => static function (): void {
        Fixture::filesSeed('v6.0.14');
        Fixture::filesSeed('v6.0.13');
        Fixture::gitSeed('v6.0.14');
    },
    'sqlite: plan --json and dry run 6.0.14 -> main change nothing' => static function () use ($x): void {
        $root = Fixture::install('sqliteplan', Fixture::filesSeed('v6.0.14'), 'sqlite');
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, $out, $err, $j] = $x(['plan', '--root=' . $root, '--channel=main', '--json'], 'sqliteplan');
        t_same(0, $c, $out . $err);
        t_same('6.0.15', $j['result']['target']['version']);
        t_same('kernel', $j['result']['database']['mode']);
        t_same([], $j['result']['blocked']);
        [$c, $out, $err] = $x(['upgrade', '--root=' . $root, '--channel=main', '--dry-run'], 'sqliteplan');
        t_same(0, $c, $out . $err);
        t_same($tree, t_tree($root), 'dry run: files unchanged');
        t_same($db, t_db($root), 'dry run: database unchanged');
    },
    'sqlite: plan --json, dry run, upgrade 6.0.14 -> main by file patch, local edit kept, rollback exact' => static function () use ($x, $version): void {
        $root = Fixture::install('sqlite', Fixture::filesSeed('v6.0.14'), 'sqlite');
        file_put_contents($root . '/kernel/content/view.php', "\n// a local edit on the server\n", FILE_APPEND);
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, $out, $err, $j] = $x(['plan', '--root=' . $root, '--channel=main', '--json'], 'sqlite');
        t_same(0, $c, $err);
        t_same('6.0.15', $j['result']['target']['version']);
        t_true(in_array('kernel/content/view.php', $j['result']['local_edits'], true), 'local edit found');
        t_same('kernel', $j['result']['database']['mode']);
        [$c, , $err] = $x(['upgrade', '--root=' . $root, '--channel=main', '--dry-run'], 'sqlite');
        t_same(0, $c, $err);
        t_same($tree, t_tree($root), 'dry run: files unchanged');
        t_same($db, t_db($root), 'dry run: database unchanged');
        [$c, $out, $err, $j] = $x(['upgrade', '--root=' . $root, '--channel=main', '--json'], 'sqlite');
        t_same(0, $c, substr($out, -3000) . substr($err, -3000));
        t_same('6.0.15', $version($root));
        t_true(in_array('kernel/content/view.php', $j['result']['kept_aside'], true));
        t_true(is_file($j['result']['backup']['dir'] . '/kept/kernel/content/view.php'));
        $pdo = new PDO('sqlite:' . glob($root . '/var/storage/sqlite3/*.db')[0]);
        t_true((bool) $pdo->query("SELECT name FROM sqlite_master WHERE name = 'exp_migration'")->fetchColumn(), 'kernel database steps ran');
        [$c, $out, $err] = $x(['rollback', '--root=' . $root], 'sqlite');
        t_same(0, $c, $out . $err);
        t_same('6.0.14', $version($root));
        t_same($tree, t_tree($root), 'rollback: files exact');
        t_same($db, t_db($root), 'rollback: database exact');
    },
    'mysql: upgrade 6.0.14 -> main by file patch, then rollback exact' => static function () use ($x, $version): void {
        $root = Fixture::install('mysql', Fixture::filesSeed('v6.0.14'), 'mysql');
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, $out, $err] = $x(['upgrade', '--root=' . $root, '--channel=main'], 'mysql');
        t_same(0, $c, substr($out, -3000) . substr($err, -3000));
        t_same('6.0.15', $version($root));
        [$c, $out, $err] = $x(['rollback', '--root=' . $root], 'mysql');
        t_same(0, $c, $out . $err);
        t_same($tree, t_tree($root));
        t_same($db, t_db($root));
    },
    'mysql: a real failing database step rolls back files and database automatically' => static function () use ($x, $version): void {
        $root = Fixture::install('mysqlfail', Fixture::filesSeed('v6.0.14'), 'mysql');
        // a real kernel step that fails on its data: 6.0.15-review-hold needs the eng-GB language for its state group
        Fixture::exec($root, "UPDATE ezcontent_language SET locale = 'xne-XX' WHERE locale = 'eng-GB'");
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, $out, $err, $j] = $x(['upgrade', '--root=' . $root, '--channel=main', '--json'], 'mysqlfail');
        t_same(1, $c, substr($out, -3000) . substr($err, -2000));
        t_true($j['result']['rolled_back'] === true, json_encode($j['result']));
        t_true(strpos($j['result']['error'], 'exp:upgrade failed') !== false, $j['result']['error']);
        t_same('6.0.14', $version($root));
        t_same($tree, t_tree($root), 'files exact after the automatic rollback');
        t_same($db, t_db($root), 'database exact after the automatic rollback');
    },
    'postgresql: upgrade 6.0.14 -> main by file patch, then rollback exact' => static function () use ($x, $version): void {
        $root = Fixture::install('pgsql', Fixture::filesSeed('v6.0.14'), 'postgresql');
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, $out, $err] = $x(['upgrade', '--root=' . $root, '--channel=main'], 'pgsql');
        t_same(0, $c, substr($out, -3000) . substr($err, -3000));
        t_same('6.0.15', $version($root));
        [$c, $out, $err] = $x(['rollback', '--root=' . $root], 'pgsql');
        t_same(0, $c, $out . $err);
        t_same($tree, t_tree($root));
        t_same($db, t_db($root));
    },
    'postgresql: a failure after the files rolls back in one transaction' => static function () use ($x, $version): void {
        $root = Fixture::install('pgfail', Fixture::filesSeed('v6.0.14'), 'postgresql');
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, , , $j] = $x(['upgrade', '--root=' . $root, '--channel=main', '--json'], 'pgfail', ['XNE_TEST_FAIL_AFTER' => 'database']);
        t_same(1, $c);
        t_true($j['result']['rolled_back'] === true);
        t_same($tree, t_tree($root));
        t_same($db, t_db($root));
    },
    'git: 6.0.14 -> main by git fast-forward, then xne rollback and rollback by tag' => static function () use ($x, $version): void {
        $root = Fixture::install('git', Fixture::gitSeed('v6.0.14'), 'sqlite');
        $head = trim(\Xne\Proc::run(['git', '-C', $root, 'rev-parse', 'HEAD'])['out']);
        $db = t_db($root);
        [$c, $out, $err, $j] = $x(['plan', '--root=' . $root, '--channel=main', '--json'], 'git');
        t_same(0, $c, $err);
        t_same('git', $j['result']['method']);
        [$c, $out, $err] = $x(['upgrade', '--root=' . $root, '--channel=main'], 'git');
        t_same(0, $c, substr($out, -3000) . substr($err, -3000));
        t_same('6.0.15', $version($root));
        [$c, $out, $err] = $x(['rollback', '--root=' . $root], 'git');
        t_same(0, $c, $out . $err);
        t_same($head, trim(\Xne\Proc::run(['git', '-C', $root, 'rev-parse', 'HEAD'])['out']));
        t_same($db, t_db($root));
        [$c, $out, $err] = $x(['upgrade', '--root=' . $root, '--channel=main'], 'git');
        t_same(0, $c, substr($err, -2000));
        [$c, $out, $err] = $x(['rollback', '--root=' . $root, '--git-tag=v6.0.14'], 'git');
        t_same(0, $c, $out . $err);
        t_same('6.0.14', $version($root));
    },
    'git: local changes in tracked files refuse the fast-forward (exit 3), nothing changed' => static function () use ($x): void {
        $root = Fixture::install('gitdirty', Fixture::gitSeed('v6.0.14'), 'sqlite');
        file_put_contents($root . '/index.php', "\n// local\n", FILE_APPEND);
        $tree = t_tree($root);
        [$c] = $x(['upgrade', '--root=' . $root, '--channel=main'], 'gitdirty');
        t_same(3, $c);
        t_same($tree, t_tree($root));
    },
    'stable channel: 6.0.13 -> 6.0.14 by file patch with the SQL path, then rollback exact' => static function () use ($x, $version): void {
        $root = Fixture::install('stable', Fixture::filesSeed('v6.0.13'), 'sqlite');
        $tree = t_tree($root);
        $db = t_db($root);
        [$c, $out, $err, $j] = $x(['plan', '--root=' . $root, '--json'], 'stable');
        t_same(0, $c, $err);
        t_same('v6.0.14', $j['result']['target']['ref']);
        t_same('sql', $j['result']['database']['mode']);
        [$c, $out, $err] = $x(['upgrade', '--root=' . $root], 'stable');
        t_same(0, $c, substr($out, -3000) . substr($err, -3000));
        t_same('6.0.14', $version($root));
        [$c, $out, $err] = $x(['rollback', '--root=' . $root], 'stable');
        t_same(0, $c, $out . $err);
        t_same($tree, t_tree($root));
        t_same($db, t_db($root));
    },
];
