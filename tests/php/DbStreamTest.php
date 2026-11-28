<?php
declare(strict_types=1);

// The database backup keeps a bounded memory whatever the size of a table (0.3.0.14: the dump of a PostgreSQL
// database grew xne to 6.9 GB RSS and earlyoom killed it). A table of XNE_TEST_STREAM_MB (default 300) MB is dumped,
// verified and restored, each step in a child PHP with memory_limit=128M; the child's peak RSS must stay under
// XNE_TEST_STREAM_RSS_MB (default 256). Needs a real server, so it runs only when one is named:
//   XNE_TEST_PGSQL='{"server":"/tmp","port":"5432","user":"root","password":"","database":"xne_test_stream"}'
//   XNE_TEST_MYSQL='{"server":"127.0.0.1","port":"3306","user":"root","password":"...","database":"xne_test_stream"}'
// The database must exist, be empty or a throwaway, and its name start with xne_test_ (it is emptied).

$tests = [];
$engines = ['postgresql' => ['XNE_TEST_PGSQL', 'ezpostgresql'], 'mysql' => ['XNE_TEST_MYSQL', 'ezmysqli']];
foreach ($engines as $engine => [$var, $impl]) {
    $json = getenv($var);
    if ($json === false || $json === '') {
        continue;
    }
    $tests["$engine: a dump, verify and restore of a table larger than memory_limit stay in bounded memory"] = static function () use ($engine, $impl, $json, $var): void {
        $s = json_decode($json, true);
        t_true(is_array($s), "$var is not a JSON object");
        t_true(strncmp((string) ($s['database'] ?? ''), 'xne_test_', 9) === 0, "$var: the database name must start with xne_test_ (it is emptied)");
        $s += ['engine' => $engine, 'implementation' => $impl];
        $mb = (int) (getenv('XNE_TEST_STREAM_MB') ?: 300);
        $rssMax = (int) (getenv('XNE_TEST_STREAM_RSS_MB') ?: 256) * 1048576;
        $d = \Xne\Db\Factory::driver($s);
        $pdo = $d->pdo();
        $pg = $engine === 'postgresql';
        foreach ($d->tables() as $t) {
            $pdo->exec('DROP TABLE ' . $d->quoteIdent($t) . ($pg ? ' CASCADE' : ''));
        }
        // rows of about 1 KB: one seed row, doubled until the table holds $mb MB, plus a bytea/blob column
        $pdo->exec($pg ? 'CREATE TABLE big (id bigserial PRIMARY KEY, v text NOT NULL, b bytea)'
            : 'CREATE TABLE big (id bigint AUTO_INCREMENT PRIMARY KEY, v mediumtext NOT NULL, b longblob) ENGINE=InnoDB');
        $pdo->exec($pg ? "INSERT INTO big (v, b) VALUES (repeat('x', 1000), '\\x00ff'::bytea)"
            : "INSERT INTO big (v, b) VALUES (REPEAT('x', 1000), X'00FF')");
        $rows = 1;
        while ($rows * 2 * 1024 <= $mb * 1048576) {
            $pdo->exec($pg ? 'INSERT INTO big (v, b) SELECT md5(id::text) || substr(v, 33), b FROM big'
                : 'INSERT INTO big (v, b) SELECT CONCAT(MD5(id), SUBSTR(v, 33)), b FROM big');
            $rows *= 2;
        }
        $pdo->exec('CREATE TABLE small (k varchar(20) PRIMARY KEY, n int)');
        $pdo->exec("INSERT INTO small VALUES ('a', 1), ('b', NULL)");
        $pdo = null;
        $d = null;

        $dir = t_scratch('stream-' . $engine);
        $file = $dir . '/db.sql.gz';
        $run = static function (string $step) use ($s, $file, $rssMax, $engine): array {
            $r = \Xne\Proc::run([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__) . '/stream/child.php', $step, $file],
                dirname(__DIR__, 2), ['XNE_STREAM_SETTINGS' => json_encode($s)], 7200);
            t_same(0, $r['exit'], "$engine $step in a child with memory_limit=128M: " . substr($r['err'] . $r['out'], -600));
            $o = json_decode(trim($r['out']), true);
            t_true(is_array($o), "$step printed no result: " . substr($r['out'], -300));
            if (isset($o['rss_peak'])) {
                t_true($o['rss_peak'] < $rssMax, sprintf('%s %s: peak RSS %.0f MB, at most %.0f MB expected', $engine, $step, $o['rss_peak'] / 1048576, $rssMax / 1048576));
            }
            fwrite(STDOUT, sprintf("      %s %s: %.1fs, PHP peak %.0f MB, RSS peak %s\n", $engine, $step, $o['seconds'], $o['php_peak'] / 1048576,
                isset($o['rss_peak']) ? sprintf('%.0f MB', $o['rss_peak'] / 1048576) : 'n/a'));
            return $o;
        };
        $before = $run('fingerprint')['fingerprint'];
        t_same($rows, $before['big']['rows']);
        $dump = $run('dump');
        t_same($rows + 2, $dump['rows']);
        t_true($dump['bytes'] > 0);
        $run('verify');
        $run('restore');
        t_same($before, $run('fingerprint')['fingerprint'], 'the restore brings back every row');

        $d = \Xne\Db\Factory::driver($s);
        foreach ($d->tables() as $t) {
            $d->pdo()->exec('DROP TABLE ' . $d->quoteIdent($t) . ($pg ? ' CASCADE' : ''));
        }
    };
}
return $tests;
