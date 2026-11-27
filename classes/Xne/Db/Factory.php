<?php
declare(strict_types=1);

namespace Xne\Db;

use Xne\Ini;
use Xne\XneException;

/**
 * Reads [DatabaseSettings] of the install the way the kernel does (settings/site.ini, then the siteaccess, then
 * settings/override wins) and returns the driver of each distinct database. Drives MySQL/MariaDB,
 * PostgreSQL, SQLite, MongoDB and Oracle. A site reached over ODBC (DatabaseImplementation=odbc, kernel 6.0.15) is
 * its [ODBCSettings] Dialect's engine: ODBC is only the site's transport, the backup reads the same server through
 * the engine's own PDO driver.
 */
final class Factory
{
    public const ENGINES = [
        'ezmysqli' => 'mysql', 'ezmysql' => 'mysql', 'mysqli' => 'mysql', 'mysql' => 'mysql',
        'ezpostgresql' => 'postgresql', 'postgresql' => 'postgresql', 'pgsql' => 'postgresql',
        'ezsqlite3' => 'sqlite', 'sqlite' => 'sqlite', 'sqlite3' => 'sqlite',
        'ezoracle' => 'oracle', 'oracle' => 'oracle', 'expmongodb' => 'mongodb', 'mongodb' => 'mongodb',
    ];

    /** [ODBCSettings] Dialect (and its other names) => engine, for DatabaseImplementation=odbc */
    public const ODBC_DIALECTS = [
        'postgresql' => 'postgresql', 'pgsql' => 'postgresql', 'postgres' => 'postgresql',
        'mysql' => 'mysql', 'mariadb' => 'mysql', 'mysqli' => 'mysql',
        'sqlserver' => 'sqlserver', 'mssql' => 'sqlserver', 'sqlsrv' => 'sqlserver',
        'db2' => 'db2', 'sqlite' => 'sqlite', 'oracle' => 'oracle', 'msaccess' => 'msaccess', 'access' => 'msaccess',
    ];

    /** The engine of an implementation (pure); for odbc the dialect's, 'unknown' for anything else. */
    public static function engineOf(string $implementation, string $dialect = ''): string
    {
        $implementation = strtolower(trim($implementation));
        if ($implementation === 'odbc') {
            return self::ODBC_DIALECTS[strtolower(trim($dialect))] ?? 'unknown';
        }
        return self::ENGINES[$implementation] ?? 'unknown';
    }

    /**
     * Every distinct database the install uses: the global one first, then any a siteaccess uses instead.
     * The global one (settings/site.ini + override) counts only when a listed siteaccess resolves to it, or when no
     * listed siteaccess has a settings folder: when every siteaccess sets its own [DatabaseSettings], the kernel's
     * default (Database=nextgen, user root) is used by nothing and is not a database of the install.
     * @return list<array<string,string>> settings maps (with "siteaccesses")
     */
    public static function settings(string $root): array
    {
        $base = [$root . '/settings/site.ini'];
        $over = Ini::candidates($root . '/settings/override', 'site.ini');
        $global = Ini::load(array_merge($base, $over));
        $first = self::fromIni($global, $root);
        $first['siteaccesses'] = '';
        $firstKey = self::key($first);
        $list = [$firstKey => $first];
        $resolved = 0;
        $sas = (array) $global->get('SiteAccessSettings', 'AvailableSiteAccessList', []);
        foreach ($sas as $sa) {
            $sa = (string) $sa;
            if ($sa === '' || !\Xne\Fs::safeRel($sa)) {
                continue;
            }
            $dir = $root . '/settings/siteaccess/' . $sa;
            if (!is_dir($dir)) {
                continue;
            }
            $s = self::fromIni(Ini::load(array_merge($base, Ini::candidates($dir, 'site.ini'), $over)), $root);
            $k = self::key($s);
            if (!isset($list[$k])) {
                $s['siteaccesses'] = '';
                $list[$k] = $s;
            }
            $list[$k]['siteaccesses'] = trim($list[$k]['siteaccesses'] . ',' . $sa, ',');
            $resolved++;
        }
        if ($resolved > 0 && $list[$firstKey]['siteaccesses'] === '') {
            unset($list[$firstKey]);
        }
        return array_values($list);
    }

    /** @return array<string,string> */
    private static function fromIni(Ini $ini, string $root): array
    {
        $impl = strtolower((string) $ini->get('DatabaseSettings', 'DatabaseImplementation', 'ezmysqli'));
        $s = [
            'implementation' => $impl,
            'engine' => self::engineOf($impl, (string) $ini->get('ODBCSettings', 'Dialect', '')),
            'server' => (string) $ini->get('DatabaseSettings', 'Server', ''),
            'port' => (string) $ini->get('DatabaseSettings', 'Port', ''),
            'user' => (string) $ini->get('DatabaseSettings', 'User', ''),
            'password' => (string) $ini->get('DatabaseSettings', 'Password', ''),
            'database' => (string) $ini->get('DatabaseSettings', 'Database', ''),
            'socket' => (string) $ini->get('DatabaseSettings', 'Socket', ''),
        ];
        if ($impl === 'odbc') {
            $s['transport'] = 'odbc';
        }
        if ($s['engine'] === 'sqlite') {
            $db = $s['database'];
            $s['file'] = ($db !== '' && ($db[0] === '/' || $db[0] === '\\' || preg_match('#^[A-Za-z]:[/\\\\]#', $db))) ? $db : $root . '/var/storage/sqlite3/' . $db;
        }
        return $s;
    }

    /** @param array<string,string> $s */
    private static function key(array $s): string
    {
        return implode('|', [$s['engine'], $s['server'], $s['port'], $s['socket'], $s['database'], $s['file'] ?? '']);
    }

    /** @param array<string,string> $s */
    public static function driver(array $s): Driver
    {
        switch ($s['engine']) {
            case 'mysql':
                return new Mysql($s);
            case 'postgresql':
                return new Pgsql($s);
            case 'sqlite':
                return new Sqlite($s);
            case 'mongodb':
                return new Mongo($s);
            case 'oracle':
                return new Oracle($s);
        }
        throw XneException::refused('the ' . $s['engine'] . ' database (' . $s['implementation'] . ') has no verified xne backup yet; xne refuses to upgrade it automatically');
    }
}
