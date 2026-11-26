<?php
declare(strict_types=1);

namespace Xne\Db;

use Xne\Ini;
use Xne\XneException;

/**
 * Reads [DatabaseSettings] of the install the way the kernel does (settings/site.ini, then the siteaccess, then
 * settings/override wins) and returns the driver of each distinct database. Drives MySQL/MariaDB,
 * PostgreSQL, SQLite, MongoDB and Oracle.
 */
final class Factory
{
    public const ENGINES = [
        'ezmysqli' => 'mysql', 'ezmysql' => 'mysql', 'mysqli' => 'mysql', 'mysql' => 'mysql',
        'ezpostgresql' => 'postgresql', 'postgresql' => 'postgresql', 'pgsql' => 'postgresql',
        'ezsqlite3' => 'sqlite', 'sqlite' => 'sqlite', 'sqlite3' => 'sqlite',
        'ezoracle' => 'oracle', 'oracle' => 'oracle', 'expmongodb' => 'mongodb', 'mongodb' => 'mongodb',
    ];

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
            'engine' => self::ENGINES[$impl] ?? 'unknown',
            'server' => (string) $ini->get('DatabaseSettings', 'Server', ''),
            'port' => (string) $ini->get('DatabaseSettings', 'Port', ''),
            'user' => (string) $ini->get('DatabaseSettings', 'User', ''),
            'password' => (string) $ini->get('DatabaseSettings', 'Password', ''),
            'database' => (string) $ini->get('DatabaseSettings', 'Database', ''),
            'socket' => (string) $ini->get('DatabaseSettings', 'Socket', ''),
        ];
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
