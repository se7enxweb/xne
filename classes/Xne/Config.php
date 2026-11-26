<?php
declare(strict_types=1);

namespace Xne;

/**
 * The settings of one run, from (later wins): the built-in defaults, the package's etc/xne.conf, the server's
 * /etc/xne/xne.conf (or $XNE_CONF), the install's settings/override/xne.ini.append.php [NetworkSettings] (read-only,
 * the INI system's own convention, so one install can choose its channel or maintenance mode), the XNE_* environment
 * and the command line. Keys and meanings: etc/xne.conf.example.
 */
final class Config
{
    public const DEFAULTS = [
        'state_dir' => '',
        'cache_dir' => '',
        'sources' => 'gitlab,github,packagist',
        'github_repo' => 'se7enxweb/exponential',
        'github_token_file' => '',
        'gitlab_url' => 'https://gitlab.com',
        'gitlab_project' => '',
        'gitlab_token_file' => '',
        'packagist_package' => 'se7enxweb/exponential',
        'local_source' => '',
        'channel' => 'stable',
        'method' => 'auto',
        'maintenance' => 'keep',
        'maintenance_until' => '30m',
        'backup_keep' => '5',
        'php' => '',
        // the Composer phar the kernel's libraries stage runs (under the install's PHP); '' = found by xne
        'composer' => '',
        'run_as' => 'auto',
        'finish' => 'ezcache',
        'db_upgrade' => 'auto',
        'libraries' => 'auto',
        'health_url' => '',
        'protect' => 'settings/override,settings/siteaccess,var,config.php,config.cluster.php,.htaccess',
        'cache_tags' => 'ini,template,content',
        'index_ttl' => '3600',
        'timeout' => '3600',
        'network_url' => 'https://network.se7enx.com/api/v1',
        'report_level' => 'health',
        'notify' => 'auto',
        // notify hooks (Client\Notify): command lines run by "xne check"; empty = not run
        'notify_problem_cmd' => '',
        'notify_ok_cmd' => '',
        'notify_event_cmd' => '',
        'server_data' => '/var/lib/xne-server',
        'server_owner' => '',
        'server_peers' => '',
        'mirror_from' => 'https://github.com/se7enxweb/exponential.git',
        'mirror_to' => '',
    ];

    /** INI key in [NetworkSettings] => config key */
    private const INI_KEYS = [
        'Channel' => 'channel', 'Method' => 'method', 'Maintenance' => 'maintenance', 'MaintenanceUntil' => 'maintenance_until',
        'BackupKeep' => 'backup_keep', 'Finish' => 'finish', 'DatabaseUpgrade' => 'db_upgrade', 'Libraries' => 'libraries',
        'HealthURL' => 'health_url', 'Sources' => 'sources', 'RunAs' => 'run_as', 'PHP' => 'php', 'Composer' => 'composer', 'CacheTags' => 'cache_tags', 'NetworkURL' => 'network_url', 'ReportLevel' => 'report_level', 'Notify' => 'notify',
    ];

    /** @var array<string,string> */
    private $v;
    /** @var array<string,string> keys of the conf files this release does not know (older or newer settings) */
    private $extra = [];
    /** @var list<string> where values came from, for "xne config" */
    public $origins = [];

    /** @param array<string,string> $cli */
    public function __construct(string $home, array $cli = [], ?string $installRoot = null)
    {
        $this->v = self::DEFAULTS;
        $this->origins[] = 'defaults';
        $sys = getenv('XNE_CONF');
        foreach ([$home . '/etc/xne.conf', $sys !== false && $sys !== '' ? $sys : '/etc/xne/xne.conf'] as $f) {
            if (is_file($f)) {
                $this->merge(self::parseConf((string) file_get_contents($f)));
                $this->origins[] = $f;
            }
        }
        if ($installRoot !== null) {
            $this->mergeInstall($installRoot);
        }
        foreach (array_keys(self::DEFAULTS) as $k) {
            $e = getenv('XNE_' . strtoupper($k));
            if ($e !== false && $e !== '') {
                $this->v[$k] = $e;
            }
        }
        $this->merge($cli);
        if ($this->v['state_dir'] === '') {
            $this->v['state_dir'] = self::defaultDir('lib');
        }
        if ($this->v['cache_dir'] === '') {
            $this->v['cache_dir'] = self::defaultDir('cache');
        }
    }

    public function mergeInstall(string $root): void
    {
        $ini = Ini::load(Ini::candidates($root . '/settings/override', 'xne.ini'));
        if (!$ini->files) {
            return;
        }
        $set = [];
        foreach ($ini->section('NetworkSettings') as $k => $val) {
            if (isset(self::INI_KEYS[$k])) {
                $set[self::INI_KEYS[$k]] = is_array($val) ? implode(',', $val) : (string) $val;
            }
        }
        $this->merge($set);
        $this->origins[] = implode(', ', $ini->files);
    }

    private static function defaultDir(string $kind): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $base = (string) (getenv('ProgramData') ?: getenv('APPDATA') ?: 'C:\\ProgramData');
            return $base . '\\xne\\' . $kind;
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return '/var/' . $kind . '/xne';
        }
        $home = (string) (getenv('HOME') ?: sys_get_temp_dir());
        return $home . '/.xne/' . $kind;
    }

    /** @return array<string,string> */
    public static function parseConf(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || $t[0] === ';') {
                continue;
            }
            $p = strpos($t, '=');
            if ($p === false) {
                continue;
            }
            $val = trim(substr($t, $p + 1));
            // one pair of quotes around the whole value is dropped; quotes inside it (a hook's words) stay
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]
                && strpos(substr($val, 1, -1), $val[0]) === false) {
                $val = substr($val, 1, -1);
            }
            $out[trim(substr($t, 0, $p))] = $val;
        }
        return $out;
    }

    /** @param array<string,string> $set */
    public function merge(array $set): void
    {
        foreach ($set as $k => $val) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $this->v[$k] = (string) $val;
            } else {
                $this->extra[(string) $k] = (string) $val;
            }
        }
    }

    public function get(string $k): string
    {
        return $this->v[$k] ?? '';
    }

    /** A key of the conf files that is not a setting of this release, or ''. */
    public function extra(string $k): string
    {
        return $this->extra[$k] ?? '';
    }

    public function int(string $k): int
    {
        return (int) $this->get($k);
    }

    /** @return list<string> */
    public function list(string $k): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($k))), 'strlen'));
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->v;
    }

    /** The token in a file (0600) named by $key, or '' — never printed or logged. */
    public function secretFile(string $key): string
    {
        $f = $this->get($key);
        if ($f === '' || !is_readable($f)) {
            return '';
        }
        return trim((string) file_get_contents($f));
    }
}
