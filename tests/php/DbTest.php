<?php
declare(strict_types=1);

use Xne\Db\Driver;
use Xne\Db\Factory;
use Xne\Db\Sqlite;

return [
    'the SQL splitter keeps quotes, comments and dollar quoting intact' => static function (): void {
        $sql = "-- head; comment\nCREATE TABLE a (x text default 'a;b');\n/* block; */INSERT INTO a VALUES ('it''s; fine');\nDO \$\$ BEGIN PERFORM 1; END \$\$;\nSELECT \"x;y\" FROM a";
        $st = Driver::splitSql($sql);
        t_same(4, count($st));
        t_same("CREATE TABLE a (x text default 'a;b')", $st[0]);
        t_true(strpos($st[1], "'it''s; fine'") !== false);
        t_same('DO $$ BEGIN PERFORM 1; END $$', $st[2]);
        t_same(["INSERT INTO t VALUES ('a\\';b')"], Driver::splitSql("INSERT INTO t VALUES ('a\\';b');", true));
    },
    'settings: the override wins, a siteaccess with its own database is found' => static function (): void {
        $s = t_scratch('dbset');
        \Xne\Fs::atomicWrite($s . '/settings/site.ini', "[DatabaseSettings]\nDatabaseImplementation=ezmysqli\nServer=localhost\n");
        \Xne\Fs::atomicWrite($s . '/settings/override/site.ini.append.php', "<?php /*\n[DatabaseSettings]\nDatabase=main\nUser=u\nPassword=secret\n[SiteAccessSettings]\nAvailableSiteAccessList[]=a\nAvailableSiteAccessList[]=b\n*/ ?>\n");
        \Xne\Fs::atomicWrite($s . '/settings/siteaccess/a/site.ini.append.php', "[DatabaseSettings]\nServer=other\n");
        \Xne\Fs::atomicWrite($s . '/settings/siteaccess/b/site.ini.append.php', "[SiteSettings]\nSiteName=b\n");
        $l = Factory::settings($s);
        t_same(2, count($l));
        t_same('mysql', $l[0]['engine']);
        t_same('main', $l[0]['database']);
        t_same('b', $l[0]['siteaccesses']);
        t_same('other', $l[1]['server']);
        t_true(!isset(Factory::driver($l[0])->describe()['password']), 'describe() never shows the password');
    },
    'the global database counts only when a siteaccess uses it (kernel default nextgen is not a second database)' => static function (): void {
        $s = t_scratch('dbset-sa-only');
        \Xne\Fs::atomicWrite($s . '/settings/site.ini', "[DatabaseSettings]\nDatabaseImplementation=ezmysqli\nServer=localhost\nUser=root\nDatabase=nextgen\n");
        \Xne\Fs::atomicWrite($s . '/settings/override/site.ini.append.php', "<?php /*\n[SiteAccessSettings]\nAvailableSiteAccessList[]=a\nAvailableSiteAccessList[]=b\nAvailableSiteAccessList[]=gone\n*/ ?>\n");
        \Xne\Fs::atomicWrite($s . '/settings/siteaccess/a/site.ini.append.php', "[DatabaseSettings]\nDatabase=site\nUser=u\n");
        \Xne\Fs::atomicWrite($s . '/settings/siteaccess/b/site.ini.append.php', "[DatabaseSettings]\nDatabase=site\nUser=u\n");
        $l = Factory::settings($s);
        t_same(1, count($l));
        t_same('site', $l[0]['database']);
        t_same('a,b', $l[0]['siteaccesses']);
        // one siteaccess without its own settings falls back to the global database: both count
        \Xne\Fs::atomicWrite($s . '/settings/siteaccess/b/site.ini.append.php', "[SiteSettings]\nSiteName=b\n");
        $l = Factory::settings($s);
        t_same(2, count($l));
        t_same('nextgen', $l[0]['database']);
        t_same('b', $l[0]['siteaccesses']);
        // no siteaccess folder at all: the global database is the install's database
        $e = t_scratch('dbset-none');
        \Xne\Fs::atomicWrite($e . '/settings/site.ini', "[DatabaseSettings]\nDatabaseImplementation=ezmysqli\nDatabase=main\n");
        $l = Factory::settings($e);
        t_same(1, count($l));
        t_same('main', $l[0]['database']);
    },
    'a site over ODBC is its dialect\'s engine; SQL Server and Access over ODBC are refused like their native drivers' => static function (): void {
        $s = t_scratch('dbset-odbc');
        \Xne\Fs::atomicWrite($s . '/settings/site.ini', "[DatabaseSettings]\nDatabaseImplementation=ezmysqli\n");
        \Xne\Fs::atomicWrite($s . '/settings/override/site.ini.append.php', "<?php /*\n[DatabaseSettings]\nDatabaseImplementation=odbc\nServer=db\nDatabase=site\nUser=u\n[ODBCSettings]\nDialect=postgresql\nDriver=PostgreSQL Unicode\n*/ ?>\n");
        $l = Factory::settings($s);
        t_same('postgresql', $l[0]['engine']);
        t_same('odbc', $l[0]['implementation']);
        t_same('odbc', $l[0]['transport']);
        t_true(Factory::driver($l[0]) instanceof \Xne\Db\Pgsql);
        t_same('mysql', Factory::engineOf('odbc', 'MariaDB'));
        t_same('sqlserver', Factory::engineOf('ODBC', 'mssql'));
        t_same('unknown', Factory::engineOf('odbc', ''));
        t_same('postgresql', Factory::engineOf('ezpostgresql', 'mysql'));
        t_throws(\Xne\XneException::class, static function (): void {
            Factory::driver(['engine' => 'sqlserver', 'implementation' => 'odbc', 'database' => 'x']);
        }, 'SQL Server over ODBC has no verified xne backup, as SQL Server has none');
    },
    'SQLite: backup copy verifies, restore brings back data and drops later tables' => static function (): void {
        $s = t_scratch('sqlite');
        t_fake_install($s . '/i');
        $d = Factory::driver(Factory::settings($s . '/i')[0]);
        $d->pdo()->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, v BLOB, f REAL)");
        $d->pdo()->exec("INSERT INTO t VALUES (1, X'00FF', 0.1), (2, NULL, 1e300)");
        $before = $d->fingerprint();
        $meta = $d->dump($s . '/b/db.sqlite');
        Sqlite::verifyCopy($s . '/b/db.sqlite', $meta);
        $d->pdo()->exec("DELETE FROM t WHERE id = 1");
        $d->pdo()->exec("CREATE TABLE later (x INTEGER)");
        $d->restore($s . '/b/db.sqlite');
        $d2 = Factory::driver(Factory::settings($s . '/i')[0]);
        t_same($before, $d2->fingerprint());
        file_put_contents($s . '/b/db.sqlite', 'x', FILE_APPEND);
        t_throws(\Xne\XneException::class, static function () use ($s, $meta): void {
            Sqlite::verifyCopy($s . '/b/db.sqlite', $meta);
        }, 'a changed copy fails its verification');
    },
    'every engine of the kernel has a driver (Oracle and MongoDB since 0.3.0.0); an unknown one is refused' => static function (): void {
        t_true(Factory::driver(['engine' => 'oracle', 'implementation' => 'ezoracle', 'database' => 'x']) instanceof \Xne\Db\Oracle);
        t_true(Factory::driver(['engine' => 'mongodb', 'implementation' => 'expmongodb', 'database' => 'x']) instanceof \Xne\Db\Mongo);
        t_throws(\Xne\XneException::class, static function (): void {
            Factory::driver(['engine' => 'unknown', 'implementation' => 'ezdb2']);
        });
    },
];
