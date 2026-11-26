<?php
declare(strict_types=1);

/**
 * Throwaway Exponential installs for the integration tests (XNE_IT_DIR, a scratch folder on the test
 * node). Seeds are built once: the real release from GitHub (through xne's own release store), "composer install",
 * and for git a blobless clone with the main branch's blobs fetched. Every test copies a seed into
 * installs/<run>/<name> and creates its own database named xne_test_<run>_<name> on the node's local test
 * instances (SQLite file, MariaDB on 127.0.0.1:3306, PostgreSQL 16 on the 5432 socket). Never a production database:
 * every name is checked for the xne_test_ prefix before it is created or dropped.
 */
final class Fixture
{
    public static function dir(): string
    {
        return rtrim((string) getenv('XNE_IT_DIR'), '/\\');
    }

    public static function run(): string
    {
        static $run = null;
        if ($run === null) {
            $run = getenv('XNE_IT_RUN') ?: date('mdHis');
        }
        return $run;
    }

    /** PHP 8.1+ with pdo_pgsql etc. for the install's tools and for xne itself in these tests. */
    public static function php(): string
    {
        $p = getenv('XNE_IT_PHP');
        return $p !== false && $p !== '' ? $p : PHP_BINARY;
    }

    public static function env(string $name): array
    {
        return [
            'XNE_STATE_DIR' => self::dir() . '/state/' . self::run() . '-' . $name,
            'XNE_CACHE_DIR' => self::dir() . '/cache',
            'XNE_PHP' => self::php(),
            'XNE_CONF' => self::dir() . '/none.conf',
            'XNE_INDEX_TTL' => '86400',
        ];
    }

    private static function sh(array $argv, ?string $cwd = null, int $timeout = 3600, array $env = []): string
    {
        $r = \Xne\Proc::run($argv, $cwd, $env + ['COMPOSER_ALLOW_SUPERUSER' => '1', 'GIT_TERMINAL_PROMPT' => '0'], $timeout);
        if ($r['exit'] !== 0) {
            throw new TestFailure(implode(' ', array_slice($argv, 0, 4)) . ' failed (' . $r['exit'] . '): ' . substr($r['err'] . $r['out'], -1500));
        }
        return $r['out'];
    }

    private static function cfg(): \Xne\Config
    {
        putenv('XNE_CACHE_DIR=' . self::dir() . '/cache');
        putenv('XNE_STATE_DIR=' . self::dir() . '/state/seed');
        $c = new \Xne\Config(dirname(__DIR__, 2));
        putenv('XNE_CACHE_DIR');
        putenv('XNE_STATE_DIR');
        return $c;
    }


    /** composer.phar itself (a wrapper script cannot be run with a chosen PHP): XNE_IT_COMPOSER, Plesk's, or PATH. */
    private static function composer(): string
    {
        foreach ([(string) getenv('XNE_IT_COMPOSER'), '/usr/local/psa/var/modules/composer/composer.phar', '/usr/local/bin/composer.phar'] as $c) {
            if ($c !== '' && is_file($c)) {
                return $c;
            }
        }
        return (string) \Xne\Proc::which('composer');
    }
    /**
     * A copy of the tree $src at $dst in pure PHP (no "cp", so it runs on Windows too): files with their mode, links
     * as links where the system allows them; $noClobber keeps every file that exists at $dst already.
     */
    private static function copyTree(string $src, string $dst, bool $noClobber = false): void
    {
        \Xne\Fs::mkdir($dst);
        $files = \Xne\Fs::walk($src, static function (string $rel, bool $isDir) use ($dst): bool {
            if ($isDir) {
                \Xne\Fs::mkdir($dst . '/' . $rel);
            }
            return false;
        });
        foreach ($files as $rel) {
            $from = $src . '/' . $rel;
            $to = $dst . '/' . $rel;
            if (file_exists($to) || is_link($to)) {
                if ($noClobber) {
                    continue;
                }
                throw new TestFailure("$to exists already");
            }
            \Xne\Fs::mkdir(dirname($to));
            if (is_link($from) && \Xne\Fs::symlink(\Xne\Fs::readlink($from), $to)) {
                continue;
            }
            if (is_link($from) && !file_exists($from)) {
                continue; // a link whose target does not exist here: nothing to copy
            }
            $real = is_link($from) ? realpath($from) : false;
            if (is_dir($from) || ($real !== false && is_dir($real))) {
                // a folder link the system would not recreate (Windows without the right, or a link Windows made as
                // a file link): its content instead
                self::copyTree($real !== false ? $real : $from, $to, $noClobber);
                continue;
            }
            if (!@copy($real !== false ? $real : $from, $to)) {
                throw new TestFailure("cannot copy $from to $to (link " . var_export(is_link($from), true) . ', target ' . \Xne\Fs::readlink($from)
                    . ', real ' . var_export($real, true) . ', dir ' . var_export(is_dir($from), true) . ', file ' . var_export(is_file($from), true) . ')');
            }
            @chmod($to, fileperms($from) & 0777);
        }
    }

    /** A plain-files seed of a release tag, with composer install done. */
    public static function filesSeed(string $tag): string
    {
        $seed = self::dir() . '/seeds/files-' . $tag;
        if (is_file($seed . '/.xne-seed-ready-2')) {
            return $seed;
        }
        $cfg = self::cfg();
        $src = new \Xne\Source\GitHub($cfg);
        $ref = \Xne\Source\Ranker::target($src->refs(), '0.0.0', 'stable', $tag);
        $rel = \Xne\Client\Release::ensure($cfg, $src, $ref);
        \Xne\Fs::mkdir(dirname($seed));
        if (!is_dir($seed)) {
            self::copyTree($rel->tree(), $seed);
        }
        self::sh([self::php(), self::composer(), 'install', '--no-interaction', '--no-progress', '--no-dev'], $seed, 5400, ['COMPOSER_PROCESS_TIMEOUT' => '1800']);
        if (!is_file($seed . '/vendor/autoload.php')) {
            throw new TestFailure("composer install left no vendor/autoload.php in $seed");
        }
        file_put_contents($seed . '/.xne-seed-ready-2', date('c') . "\n");
        return $seed;
    }

    /** A git seed: blobless clone on branch "site" at $tag, main's blobs fetched, vendor from the files seed. */
    public static function gitSeed(string $tag): string
    {
        $seed = self::dir() . '/seeds/git-' . $tag;
        if (is_file($seed . '/.git/xne-seed-ready-2')) {
            return $seed;
        }
        $files = self::filesSeed($tag);
        if (!is_dir($seed . '/.git')) {
            self::sh(['git', 'clone', '--quiet', '--filter=blob:none', '--no-checkout', 'https://github.com/se7enxweb/exponential.git', $seed], null, 5400);
        }
        self::sh(['git', '-C', $seed, 'checkout', '--quiet', '-B', 'main', 'origin/main'], null, 5400);
        self::sh(['git', '-C', $seed, 'checkout', '--quiet', '-B', 'site', $tag], null, 5400);
        self::copyTree($files, $seed, true);
        file_put_contents($seed . '/.git/xne-seed-ready-2', date('c') . "\n");
        return $seed;
    }

    private static function secret(string $name): string
    {
        $f = self::dir() . '/secrets/' . $name;
        if (!is_file($f)) {
            \Xne\Fs::mkdir(dirname($f), 0700);
            \Xne\Fs::atomicWrite($f, bin2hex(random_bytes(16)), 0600);
        }
        return trim((string) file_get_contents($f));
    }

    private static function dbName(string $name): string
    {
        $db = 'xne_test_' . self::run() . '_' . preg_replace('/\W/', '_', $name);
        if (strncmp($db, 'xne_test_', 9) !== 0 || strlen($db) > 60) {
            throw new TestFailure("refused database name $db");
        }
        return $db;
    }

    private static function mysqlAdmin(): \PDO
    {
        $pw = trim((string) file_get_contents('/etc/psa/.psa.shadow'));
        $pdo = new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'admin', $pw);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private static function pgAdmin(string $sql): void
    {
        self::sh(['runuser', '-u', 'postgres', '--', 'psql', '-p', '5432', '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-c', $sql]);
    }

    /** Loads SQL files into a PDO, statement by statement. */
    private static function load(\PDO $pdo, array $files, bool $mysql): void
    {
        foreach ($files as $f) {
            foreach (\Xne\Db\Driver::splitSql((string) file_get_contents($f), $mysql) as $st) {
                $pdo->exec($st);
            }
        }
    }

    /**
     * kernel/sql/common/cleandata.sql (and SQLite's cleandata.sql) are written with MySQL string escapes (\' \" \n \\).
     * For SQLite and PostgreSQL every quoted string is rewritten in standard SQL ('' for a quote, real newlines).
     */
    private static function standardStrings(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $inQ = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if (!$inQ) {
                $out .= $c;
                if ($c === "'") {
                    $inQ = true;
                }
                continue;
            }
            if ($c === '\\' && $i + 1 < $len) {
                $n = $sql[++$i];
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", "'" => "''", '"' => '"', '\\' => '\\'];
                $out .= $map[$n] ?? $n;
                continue;
            }
            if ($c === "'") {
                if (($sql[$i + 1] ?? '') === "'") {
                    $out .= "''";
                    $i++;
                    continue;
                }
                $inQ = false;
            }
            $out .= $c;
        }
        return $out;
    }

    /** Loads MySQL-escaped SQL files into SQLite or PostgreSQL. */
    private static function loadConverted(\PDO $pdo, array $files): void
    {
        foreach ($files as $f) {
            foreach (\Xne\Db\Driver::splitSql((string) file_get_contents($f), true) as $st) {
                if (stripos($st, 'sqlite_sequence') !== false) {
                    continue;
                }
                $pdo->exec(self::standardStrings($st));
            }
        }
    }

    /**
     * A throwaway install: a copy of $seed with its own database. @return string the install root
     */
    public static function install(string $name, string $seed, string $engine): string
    {
        $root = self::dir() . '/installs/' . self::run() . '/' . $name;
        \Xne\Fs::mkdir(dirname($root));
        if (is_dir($root)) {
            throw new TestFailure("$root exists already (XNE_IT_RUN reused?)");
        }
        self::copyTree($seed, $root);
        $db = self::dbName($name);
        $writeIni = static function (string $set) use ($root): void {
            $ini = "<?php /* #?ini charset=\"utf-8\"?\n\n[DatabaseSettings]\n$set\n[SiteSettings]\nSiteURL=xne-test.invalid\nDefaultAccess=site\n\n"
                . "[SiteAccessSettings]\nCheckValidity=false\nAvailableSiteAccessList[]\nAvailableSiteAccessList[]=site\nMatchOrder=uri\n\n"
                . "[ExtensionSettings]\nActiveExtensions[]\nActiveExtensions[]=ezjscore\nActiveExtensions[]=ezoe\n*/ ?>\n";
            \Xne\Fs::atomicWrite($root . '/settings/override/site.ini.append.php', $ini, 0600);
            \Xne\Fs::atomicWrite($root . '/settings/siteaccess/site/site.ini.append.php', "<?php /* #?ini charset=\"utf-8\"?\n[SiteSettings]\nSiteName=xne test\n*/ ?>\n");
        };
        switch ($engine) {
            case 'sqlite':
                $set = "DatabaseImplementation=sqlite3\nDatabase=$db.db\n";
                $writeIni($set);
                \Xne\Fs::mkdir($root . '/var/storage/sqlite3');
                $pdo = new \PDO('sqlite:' . $root . "/var/storage/sqlite3/$db.db");
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::loadConverted($pdo, [$root . '/kernel/sql/sqlite/schema.sql', $root . '/kernel/sql/sqlite/cleandata.sql']);
                break;
            case 'mysql':
                $pw = self::secret('mysql');
                $a = self::mysqlAdmin();
                $a->exec("DROP DATABASE IF EXISTS `$db`");
                $a->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                foreach (['localhost', '127.0.0.1'] as $h) {
                    $a->exec("CREATE USER IF NOT EXISTS 'xne_test'@'$h' IDENTIFIED BY " . $a->quote($pw));
                    $a->exec("ALTER USER 'xne_test'@'$h' IDENTIFIED BY " . $a->quote($pw));
                    $a->exec("GRANT ALL PRIVILEGES ON `xne\\_test\\_%`.* TO 'xne_test'@'$h'");
                }
                $set = "DatabaseImplementation=ezmysqli\nServer=127.0.0.1\nPort=3306\nUser=xne_test\nPassword=$pw\nDatabase=$db\n";
                $writeIni($set);
                $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=$db;charset=utf8mb4", 'xne_test', $pw);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::load($pdo, [$root . '/kernel/sql/mysql/kernel_schema.sql', $root . '/kernel/sql/common/cleandata.sql'], true);
                break;
            case 'postgresql':
                self::pgAdmin("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'root') THEN CREATE ROLE root LOGIN; END IF; END \$\$");
                self::pgAdmin("DROP DATABASE IF EXISTS $db");
                self::pgAdmin("CREATE DATABASE $db OWNER root ENCODING 'UTF8' TEMPLATE template0");
                $set = "DatabaseImplementation=ezpostgresql\nServer=/var/run/postgresql\nPort=5432\nUser=root\nPassword=\nDatabase=$db\n";
                $writeIni($set);
                $pdo = new \PDO("pgsql:host=/var/run/postgresql;port=5432;dbname=$db", 'root', '');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::loadConverted($pdo, [$root . '/kernel/sql/postgresql/kernel_schema.sql', $root . '/kernel/sql/common/cleandata.sql', $root . '/kernel/sql/postgresql/setval.sql']);
                // as the kernel's installer does after loading data: every nextval() sequence after its table's highest id
                $pdo->exec("DO \$\$ DECLARE r record; BEGIN FOR r IN SELECT table_name AS t, column_name AS c,
                    substring(column_default from 'nextval\\(+''([^'']+)''') AS s FROM information_schema.columns
                    WHERE table_schema = current_schema() AND column_default LIKE 'nextval(%' LOOP
                    EXECUTE format('SELECT setval(%L, COALESCE((SELECT max(%I) FROM %I), 0) + 1, false)', r.s, r.c, r.t);
                    END LOOP; END \$\$");
                break;
            default:
                throw new TestFailure("unknown engine $engine");
        }
        return $root;
    }

    /** Runs a PDO statement against the install's own database (test setup only). */
    public static function exec(string $root, string $sql): void
    {
        \Xne\Db\Factory::driver(\Xne\Db\Factory::settings($root)[0])->pdo()->exec($sql);
    }
}
