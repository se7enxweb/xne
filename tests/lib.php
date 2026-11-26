<?php
declare(strict_types=1);

// Test helpers: assertions, a scratch folder under var/test, and a runner for bin/xne with an argv array.

final class TestFailure extends \RuntimeException
{
}

function t_true(bool $c, string $msg = 'expected true'): void
{
    if (!$c) {
        throw new TestFailure($msg);
    }
}

/** @param mixed $expected @param mixed $actual */
function t_same($expected, $actual, string $msg = ''): void
{
    if (is_array($expected) && is_array($actual) && $expected !== $actual) {
        $d = [];
        foreach ($expected as $k => $v) {
            if (!array_key_exists($k, $actual)) {
                $d[] = "missing $k";
            } elseif ($actual[$k] !== $v) {
                $d[] = "differs $k: " . substr(var_export($v, true), 0, 120) . ' -> ' . substr(var_export($actual[$k], true), 0, 120);
            }
        }
        foreach (array_diff_key($actual, $expected) as $k => $v) {
            $d[] = "extra $k";
        }
        throw new TestFailure(($msg !== '' ? "$msg: " : '') . count($d) . " difference(s): " . implode('; ', array_slice($d, 0, 25)));
    }
    if ($expected !== $actual) {
        throw new TestFailure(($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function t_throws(string $class, callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new TestFailure("$msg: expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new TestFailure("$msg: expected $class, nothing thrown");
}

/** A fresh scratch folder (never removed by the tests: var/ is the package's ignored scratch area). */
function t_scratch(string $name): string
{
    $d = dirname(__DIR__) . '/var/test/' . $name . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    \Xne\Fs::mkdir($d);
    return $d;
}

/**
 * Runs bin/xne with an argv array and the given environment (added to the current one).
 * @param list<string> $argv @param array<string,string> $env
 * @return array{0: int, 1: string, 2: string, 3: array|null}
 */
function t_xne(array $argv, array $env = [], ?string $php = null, ?string $stdin = null): array
{
    $home = dirname(__DIR__);
    $r = \Xne\Proc::run(array_merge([$php ?? PHP_BINARY, $home . '/bin/xne'], $argv), $home, $env, 7200, $stdin);
    $json = null;
    if (in_array('--json', $argv, true)) {
        $json = json_decode($r['out'], true);
        if (!is_array($json)) {
            throw new TestFailure('--json output is not one JSON object: ' . substr($r['out'], 0, 400) . ' | ' . substr($r['err'], -400));
        }
    }
    return [$r['exit'], $r['out'], $r['err'], $json];
}

/** A minimal fake install tree (lib/version.php, kernel/, settings/, index.php) with an SQLite database. */
function t_fake_install(string $dir, string $version = '6.0.14', array $files = []): string
{
    [$maj, $min, $rel] = explode('.', $version);
    $vfile = "<?php\nclass eZPublishSDK\n{\n    const VERSION_MAJOR = $maj;\n    const VERSION_MINOR = $min;\n    const VERSION_RELEASE = $rel;\n    const VERSION_STATE = 'stable';\n}\n";
    \Xne\Fs::atomicWrite($dir . '/lib/version.php', $vfile);
    \Xne\Fs::mkdir($dir . '/kernel');
    \Xne\Fs::atomicWrite($dir . '/index.php', "<?php\n");
    \Xne\Fs::atomicWrite($dir . '/settings/site.ini', "[DatabaseSettings]\nDatabaseImplementation=ezmysqli\n");
    \Xne\Fs::atomicWrite($dir . '/settings/override/site.ini.append.php', "<?php /* #?ini charset=\"utf-8\"?\n[DatabaseSettings]\nDatabaseImplementation=ezsqlite3\nDatabase=test.db\n[SiteAccessSettings]\nAvailableSiteAccessList[]=site\n*/ ?>\n");
    \Xne\Fs::mkdir($dir . '/var/storage/sqlite3');
    $pdo = new \PDO('sqlite:' . $dir . '/var/storage/sqlite3/test.db');
    $pdo->exec("CREATE TABLE ezsite_data (name TEXT PRIMARY KEY, value TEXT)");
    $pdo->exec("INSERT INTO ezsite_data VALUES ('ezpublish-version', '$version')");
    foreach ($files as $p => $c) {
        \Xne\Fs::atomicWrite($dir . '/' . $p, $c);
    }
    return $dir;
}

/**
 * A fake release: a tree (lib/version.php, kernel tools that answer like the real ones, the given files) packed as
 * <dir>/<ref>.tar.gz in GitHub's layout, and listed in <dir>/index.json. @param array<string,string> $files
 */
/** @param list<string> $unlisted files of the release left out of its share/filelist.md5 (as composer.lock in a real one) */
function t_fake_release(string $dir, string $ref, string $kind, string $version, array $files, array $unlisted = []): void
{
    [$maj, $min, $rel] = explode('.', $version);
    $base = [
        'lib/version.php' => "<?php\nclass eZPublishSDK\n{\n    const VERSION_MAJOR = $maj;\n    const VERSION_MINOR = $min;\n    const VERSION_RELEASE = $rel;\n    const VERSION_STATE = 'stable';\n}\n",
        'index.php' => "<?php\n",
        'kernel/common/x.php' => "<?php // kernel $version\n",
        'bin/php/ezcache.php' => "<?php\n// test stand-in for the kernel's ezcache.php\nif (getenv('XNE_TEST_EZCACHE_FAIL') === '1') { fwrite(STDERR, \"Fatal error: test\\n\"); exit(1); }\nif (getenv('XNE_TEST_EZCACHE_TOUCH')) { file_put_contents(getenv('XNE_TEST_EZCACHE_TOUCH'), \"<?php // rewritten\\n\"); }\necho 'ezcache ', implode(' ', array_slice(\$argv, 1)), \"\\n\";\nexit(0);\n",
        'bin/php/ezpgenerateautoloads.php' => "<?php\necho \"autoloads\\n\";\nexit(0);\n",
        'settings/site.ini' => "[DatabaseSettings]\nDatabaseImplementation=ezmysqli\n",
    ];
    $files = $files + $base;
    $md5 = [];
    foreach ($files as $p => $c) {
        if (!in_array($p, $unlisted, true)) {
            $md5[$p] = md5($c);
        }
    }
    $files['share/filelist.md5'] = \Xne\Filelist::format($md5);
    \Xne\Fs::mkdir($dir);
    $tw = \Xne\Tar::create($dir . '/' . preg_replace('/[^\w.-]/', '_', $ref) . '.tar.gz');
    foreach ($files as $p => $c) {
        $tw->addString('se7enxweb-exponential-' . substr(md5($ref), 0, 7) . '/' . $p, $c, strpos($p, 'bin/') === 0 ? 0755 : 0644);
    }
    $tw->close();
    $idx = is_file($dir . '/index.json') ? \Xne\Fs::readJson($dir . '/index.json') : [];
    $idx[] = ['ref' => $ref, 'kind' => $kind, 'sha' => sha1($ref . $version . serialize($files))];
    \Xne\Fs::writeJson($dir . '/index.json', $idx, 0644);
}

/** md5 of every file of an install outside var/ (the tree a rollback must give back exactly). */
function t_tree(string $root): array
{
    $out = [];
    foreach (\Xne\Fs::walk($root, static function (string $r, bool $isDir) {
        return $r === 'var' || $r === '.git';
    }) as $p) {
        $out[$p] = \Xne\Fs::fingerprint($root . '/' . $p);
    }
    return $out;
}

function t_db(string $root): array
{
    return \Xne\Db\Factory::driver(\Xne\Db\Factory::settings($root)[0])->fingerprint();
}

/** An install made from a fake release tarball, with settings/override and an SQLite database. */
function t_install_from(string $relDir, string $ref, string $dest, string $version): string
{
    \Xne\Tar::read($relDir . '/' . $ref . '.tar.gz', static function (array $e, $data) use ($dest): void {
        $rel = substr($e['name'], (int) strpos($e['name'], '/') + 1);
        if ($e['type'] === 'file' && $rel !== '') {
            \Xne\Fs::mkdir(dirname($dest . '/' . $rel));
            $data->copyTo($dest . '/' . $rel);
            chmod($dest . '/' . $rel, $e['mode']);
        }
    });
    \Xne\Fs::atomicWrite($dest . '/settings/override/site.ini.append.php', "<?php /* #?ini charset=\"utf-8\"?\n[DatabaseSettings]\nDatabaseImplementation=ezsqlite3\nDatabase=test.db\n[SiteAccessSettings]\nAvailableSiteAccessList[]=site\n*/ ?>\n");
    \Xne\Fs::mkdir($dest . '/var/storage/sqlite3');
    $pdo = new \PDO('sqlite:' . $dest . '/var/storage/sqlite3/test.db');
    $pdo->exec("CREATE TABLE ezsite_data (name TEXT PRIMARY KEY, value TEXT)");
    $pdo->exec("INSERT INTO ezsite_data VALUES ('ezpublish-version', '$version')");
    $pdo->exec("CREATE TABLE ezcontentobject (id INTEGER PRIMARY KEY, name TEXT, data BLOB)");
    $pdo->exec("INSERT INTO ezcontentobject VALUES (1, 'Home', X'00FF10'), (2, 'Über ''uns''', NULL)");
    return $dest;
}

/** The common fake world: releases v6.0.13, v6.0.14, v6.0.15, v6.0.16beta1 (failing SQL), main; returns [relDir, env]. */
function t_world(string $name): array
{
    $s = t_scratch($name);
    $rel = $s . '/releases';
    t_fake_release($rel, 'v6.0.13', 'tag', '6.0.13', ['kernel/old.php' => "<?php // 13\n"]);
    $v14 = ['kernel/old.php' => "<?php // retired in 15\n", 'kernel/edited.php' => "<?php // 14\n", 'kernel/same.php' => "<?php // same\n"];
    t_fake_release($rel, 'v6.0.14', 'tag', '6.0.14', $v14);
    $v15 = ['kernel/new.php' => "<?php // new in 15\n", 'kernel/edited.php' => "<?php // 15\n", 'kernel/same.php' => "<?php // same\n",
        'update/database/sqlite/6.0/dbupdate-6.0.0-6.0.15.sql' => "CREATE TABLE IF NOT EXISTS expapikey (id INTEGER PRIMARY KEY, label TEXT);\n-- a comment; with a semicolon\nUPDATE ezsite_data SET value='6.0.15stable' WHERE name='ezpublish-version';\n"];
    t_fake_release($rel, 'v6.0.15', 'tag', '6.0.15', $v15);
    t_fake_release($rel, 'v6.0.16beta1', 'tag', '6.0.16', $v15 + ['update/database/sqlite/6.0/dbupdate-6.0.15-6.0.16.sql' => "CREATE TABLE xne_ok (id INTEGER);\nINSERT INTO no_such_table VALUES (1);\n"]);
    t_fake_release($rel, 'main', 'branch', '6.0.15', $v15 + ['kernel/nightly.php' => "<?php // nightly\n"]);
    $env = ['XNE_STATE_DIR' => $s . '/state', 'XNE_CACHE_DIR' => $s . '/cache', 'XNE_SOURCES' => 'local', 'XNE_LOCAL_SOURCE' => $rel, 'XNE_CONF' => $s . '/none.conf'];
    return [$s, $rel, $env];
}

/** Releases 6.0.14 and 6.0.15 that both carry extension/shx/a.php (changed in 15), for the shared-folder tests. */
function t_world_shared(string $name): array
{
    $s = t_scratch($name);
    $rel = $s . '/releases';
    t_fake_release($rel, 'v6.0.14', 'tag', '6.0.14', ['kernel/same.php' => "<?php // same\n", 'extension/shx/a.php' => "<?php // ext 14\n"]);
    t_fake_release($rel, 'v6.0.15', 'tag', '6.0.15', ['kernel/same.php' => "<?php // same\n", 'kernel/new.php' => "<?php // new\n", 'extension/shx/a.php' => "<?php // ext 15\n"]);
    $env = ['XNE_STATE_DIR' => $s . '/state', 'XNE_CACHE_DIR' => $s . '/cache', 'XNE_SOURCES' => 'local', 'XNE_LOCAL_SOURCE' => $rel, 'XNE_CONF' => $s . '/none.conf'];
    return [$s, $rel, $env];
}

/** A 6.0.14 install whose extension/shx is a link to a folder outside it ($s/elsewhere/shx), as a capture's arc/ tree. */
function t_install_shared(string $rel, string $s): string
{
    $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
    \Xne\Fs::mkdir($s . '/elsewhere');
    rename($root . '/extension/shx', $s . '/elsewhere/shx');
    symlink($s . '/elsewhere/shx', $root . '/extension/shx');
    return $root;
}


/** A fake tool (a PHP script with a shebang) as something the system can start: on Windows its .bat wrapper. */
function t_fake_bin(string $script): string
{
    return DIRECTORY_SEPARATOR === '\\' ? $script . '.bat' : $script;
}

/** File modes are checked where the system has them; Windows keeps access in ACLs (fileperms() says 0666). */
function t_mode(int $expected, string $file): void
{
    if (DIRECTORY_SEPARATOR !== '\\') {
        t_same($expected, fileperms($file) & 0777, "mode of $file");
    }
}
