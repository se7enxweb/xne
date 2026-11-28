<?php
declare(strict_types=1);

namespace Xne\Console;

use Xne\Client\Apply;
use Xne\Client\Backup;
use Xne\Client\Detect;
use Xne\Client\Plan;
use Xne\Client\Rollback;
use Xne\Client\Traps;
use Xne\Config;
use Xne\Db\Factory;
use Xne\Http;
use Xne\Json;
use Xne\Method\Git;
use Xne\Proc;
use Xne\Source\Ranker;
use Xne\XneException;

/**
 * The xne command line. Every command takes --json (one JSON object on stdout, progress on stderr), --dry-run and
 * --help. Exit codes: 0 PASS, 1 FAIL (an upgrade that failed and was rolled back is a FAIL), 2 usage, 3 refused by a
 * safety check (a blocking trap, a lock, an unsupported database).
 */
final class App
{
    public const VERSION = '0.3.0.14';

    private const USAGE = [
        'defaults' => "xne defaults [--root=DIR] --reference=DIR [--dry-run]\n  An older install's settings brought to a fresh install's of the same kernel, the site's own kept: the\n  reference's ActiveExtensions the install has but does not run (added after its own), admin4l and the access\n  extensions on every administration siteaccess, AllowedDeletionDirs for a var/ link out of the install. A\n  verified backup first (settings files + database), then the kernel's autoloads, its database stage (tables of\n  the newly active extensions) and the cache clears; any failure rolls back (xne rollback works on it too).",
        'detect' => "xne detect [--root=DIR]\n  The install: root, version, install type (git, composer, files), PHP, databases (from its own settings,\n  never the password), extensions (kernel, 7x, third-party), owner, and which kernel upgrade tools it has.",
        'plan' => "xne plan [--root=DIR] [--channel=stable|beta|main|security] [--to=REF] [--method=auto|filepatch|git]\n         [--shared=skip|write] [--accept=TRAP,...] [--refresh] [--allow-downgrade]\n  What an upgrade would do: the source used (gitlab, github, packagist, in that order), the target, the\n  file diff (add, update, retire, local edits kept aside), the libraries and database steps, the traps and\n  the ordered steps. Changes nothing in the install.",
        'upgrade' => "xne upgrade [plan options] [--dry-run]\n  Plan, verified backup, maintenance (setting), files, the kernel's exp:upgrade or the SQL update files,\n  autoloads and cache clears, health check. Any failure after the backup rolls back files and database\n  at once. --dry-run prints the plan and changes nothing.",
        'rollback' => "xne rollback [BACKUP-ID] [--no-db] [--dry-run] [--shared=write]\n  Files and database back to a backup (default: the newest). Files the upgrade added are moved aside into\n  the backup, never deleted. Every path is checked first; a file in a folder that leads out of the install is\n  restored only when the upgrade wrote it with --shared=write (recorded in the backup since xne 0.3.0.2), or\n  with --shared=write here for an older backup.\nxne rollback --git-tag=TAG [--dry-run]\n  A git install's code back to a tag (git reset --keep); the database is not touched.",
        'backup' => "xne backup [--root=DIR] [--dry-run]\n  A verified backup now: the database plus the files the current plan would change.",
        'backups' => "xne backups [--root=DIR]\n  The backups of the install, newest first, with state and size.",
        'verify-backup' => "xne verify-backup [BACKUP-ID]\n  Reads a backup back: archive checksums, every file's md5, the dump to its end mark with its row counts.",
        'traps' => "xne traps [--root=DIR]\n  The known upgrade traps of the install (public lib copy, Composer files entry, shared extension\n  folders, LiteSpeed PHP in .htaccess, Valkey cache, InstallationHash, kernel overrides, manifest).",
        'sources' => "xne sources [--refresh]\n  The release sources in rank order, which one answers, and the newest refs per channel.",
        'config' => "xne config [--root=DIR]\n  The effective settings and where they come from (secrets are never shown).",
        'db-fingerprint' => "xne db-fingerprint [--root=DIR]\n  Rows and an md5 per table of the install's database (diagnostics; compares a restore with its source).",
        'check' => "xne check [--root=DIR] [--no-notify]\n  What each channel offers the install and the advisories that affect it: from the Exponential Network\n  server (network_url), signed when the install has a key, else from the release sources. Runs the notify\n  hooks (notify_problem_cmd, notify_ok_cmd, notify_event_cmd; setting notify). Exit 0 also when an upgrade is available (see upgrade_available).",
        'report' => "xne report [--root=DIR] [--dry-run]\n  Sends the install's report at report_level (version, health, full) to the server, signed with the install\n  key. Never content, users or settings values. --dry-run shows the report and sends nothing.",
        'key:set' => "xne key:set [--root=DIR | --domain=DOMAIN] < keyfile\n  Stores the install key \"<id>:<secret>\" read from stdin (never an argument) in a 0600 file.",
        'key:status' => "xne key:status [--root=DIR]\n  The install key's id and fingerprint, never the secret.",
        'server:index' => "xne server:index [--set server_data=DIR]\n  Builds the server's release index from the ranked sources into server_data/index.json.",
        'server:key:issue' => "xne server:key:issue --account=ACCOUNT --level=health|full --out=FILE\n  Issues an install key for an account; writes \"<id>:<secret>\" once into FILE (0600). The secret is never printed.",
        'server:mirror' => "xne server:mirror [--dry-run]\n  Mirrors mirror_from (GitHub) to mirror_to (the GitLab release mirror): branches and tags, never a forced tag.",
        'server:key:import' => "xne server:key:import < record.json\n  Stores a key record sent by a peer node (server_peers replicate every issue and revoke over ssh stdin).",
        'server:key:provision' => "xne server:key:provision --account=ACCOUNT --level=health|full --domain=DOMAIN --target=SSH-TARGET\n  Issues a key and installs it on the domain's server (xne key:set --domain over ssh stdin); the fingerprint must match.\n  A provisioning system runs it when a customer install goes live.",
        'server:key:list' => "xne server:key:list\n  The issued keys: id, account, level, created, revoked, fingerprint.",
        'server:key:revoke' => "xne server:key:revoke <key id>\n  Revokes a key; its signed requests are refused from then on.",
        'version' => "xne version\n  The xne version.",
        'help' => "xne help [command]\n  This overview, or one command's manual.",
    ];

    /** @var string */
    private $home;
    /** @var bool */
    private $json = false;
    /** @var list<string> */
    private $messages = [];

    public function __construct(string $home)
    {
        $this->home = $home;
        Http::$userAgent = 'xne/' . self::VERSION . ' (Exponential Network client)';
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        [$cmd, $pos, $opt] = self::parse($argv);
        $this->json = isset($opt['json']);
        $other = self::reexecPhp($opt, getenv('XNE_PHP'), getenv('XNE_REEXEC'));
        if ($other !== null) {
            return self::reexec($other, $this->home . '/bin/xne', $argv);
        }
        try {
            if (isset($opt['help']) && $cmd !== 'help') {
                return $this->finish($cmd, 0, ['usage' => self::USAGE[$cmd] ?? null], self::USAGE[$cmd] ?? $this->overview());
            }
            switch ($cmd) {
                case 'help':
                    $c = $pos[0] ?? '';
                    return $this->finish('help', 0, ['usage' => self::USAGE[$c] ?? null], self::USAGE[$c] ?? $this->overview());
                case 'version':
                case '--version':
                    return $this->finish('version', 0, ['version' => self::VERSION, 'php' => PHP_VERSION], 'xne ' . self::VERSION . ' (PHP ' . PHP_VERSION . ')');
            }
            if (!isset(self::USAGE[$cmd])) {
                throw XneException::usage("unknown command $cmd (xne help)");
            }
            $method = 'cmd' . str_replace(' ', '', ucwords(str_replace(['-', ':'], ' ', $cmd)));
            Http::$timeout = 60;
            if (strncmp($cmd, 'server:', 7) === 0) {
                $cfg = new Config($this->home, self::cfgFromOpt($opt));
                return $this->$method($cfg, '', $pos, $opt);
            }
            $root = isset($opt['domain']) && !isset($opt['root']) ? Detect::rootForDomain($opt['domain']) : Detect::findRoot($opt['root'] ?? (string) getcwd());
            $cfg = new Config($this->home, self::cfgFromOpt($opt), $root);
            return $this->$method($cfg, $root, $pos, $opt);
        } catch (XneException $e) {
            $code = $e->getCode() ?: 1;
            return $this->finish($cmd, (int) $code, ['error' => $e->getMessage()], ($code === 3 ? 'REFUSED' : ($code === 2 ? 'USAGE' : 'FAIL')) . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            return $this->finish($cmd, 1, ['error' => get_class($e) . ': ' . $e->getMessage()], 'FAIL: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /** @return array{0: string, 1: list<string>, 2: array<string,string>} */
    public static function parse(array $argv): array
    {
        $flags = ['json', 'dry-run', 'help', 'refresh', 'no-db', 'allow-downgrade', 'no-notify', 'quiet', 'h', 'n', 'q'];
        $opt = [];
        $pos = [];
        $sets = [];
        for ($i = 0, $n = count($argv); $i < $n; $i++) {
            $a = (string) $argv[$i];
            if ($a === '-h') {
                $opt['help'] = '1';
                continue;
            }
            if ($a === '-n') {
                $opt['dry-run'] = '1';
                continue;
            }
            if (strncmp($a, '--', 2) === 0) {
                $kv = substr($a, 2);
                $eq = strpos($kv, '=');
                if ($eq !== false) {
                    $k = substr($kv, 0, $eq);
                    $v = substr($kv, $eq + 1);
                } elseif (in_array($kv, $flags, true)) {
                    $k = $kv;
                    $v = '1';
                } else {
                    $k = $kv;
                    $v = (string) ($argv[++$i] ?? '');
                }
                if ($k === 'set') {
                    $sets[] = $v;
                } else {
                    $opt[$k] = $v;
                }
                continue;
            }
            $pos[] = $a;
        }
        if ($sets) {
            $opt['set'] = implode("\n", $sets);
        }
        $cmd = $pos ? array_shift($pos) : 'help';
        return [$cmd, $pos, $opt];
    }

    /**
     * The PHP to run this whole command under, when --php (--set php=, or XNE_PHP) names another runnable PHP than the
     * one running now: xne's own checks (the database driver, the backup) run in its process, so a PHP without the
     * install's PDO driver refused the database although --php named one that has it. null: stay in this process.
     *
     * @param array<string,string> $opt
     * @param string|false $env XNE_PHP
     * @param string|false $marker XNE_REEXEC, set by reexec() so there is no second hop
     */
    public static function reexecPhp(array $opt, $env, $marker): ?string
    {
        if ($marker === '1') {
            return null;
        }
        $php = self::cfgFromOpt($opt)['php'] ?? '';
        if ($php === '' && is_string($env)) {
            $php = $env;
        }
        if ($php === '' || !is_file($php) || !is_executable($php)) {
            return null;
        }
        $want = realpath($php);
        $have = realpath(PHP_BINARY);
        return ($want !== false && $want !== $have) ? $want : null;
    }

    /**
     * Runs xne again under $php with the same arguments and the same standard streams; returns its exit status.
     *
     * @param list<string> $argv
     */
    private static function reexec(string $php, string $xne, array $argv): int
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];
        $env['XNE_REEXEC'] = '1';
        $p = proc_open(array_merge([$php, $xne], $argv), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
        if (!is_resource($p)) {
            fwrite(STDERR, "xne: could not start $php\n");
            return 1;
        }
        return proc_close($p);
    }

    /** @param array<string,string> $opt @return array<string,string> */
    private static function cfgFromOpt(array $opt): array
    {
        $c = [];
        foreach (['channel', 'method', 'maintenance', 'php', 'composer', 'state-dir', 'cache-dir', 'sources', 'finish', 'run-as', 'network-url', 'server-data', 'notify', 'report-level'] as $k) {
            if (isset($opt[$k])) {
                $c[str_replace('-', '_', $k)] = $opt[$k];
            }
        }
        foreach (explode("\n", $opt['set'] ?? '') as $s) {
            $eq = strpos($s, '=');
            if ($eq !== false) {
                $c[trim(substr($s, 0, $eq))] = trim(substr($s, $eq + 1));
            }
        }
        return $c;
    }

    /** @param array<string,string> $opt @return array<string,string> */
    private static function planOpt(array $opt): array
    {
        $p = [];
        foreach (['to', 'channel', 'method', 'shared', 'accept'] as $k) {
            if (isset($opt[$k])) {
                $p[$k] = $opt[$k];
            }
        }
        $p['refresh'] = isset($opt['refresh']) ? '1' : '';
        if (isset($opt['allow-downgrade'])) {
            $p['allow_downgrade'] = '1';
        }
        return $p;
    }

    private function progress(): callable
    {
        return function (string $m): void {
            fwrite(STDERR, '  .. ' . $m . "\n");
        };
    }

    private function overview(): string
    {
        $s = "xne " . self::VERSION . " - Exponential Network client: detect, plan, upgrade, rollback.\n"
            . "Global options: --root=DIR  --json  --dry-run  --help  --set key=value (any xne.conf key)\n"
            . "Setting shorthands: --channel --method --maintenance --php --composer --state-dir --cache-dir --sources --finish\n"
            . "  --run-as --network-url --server-data --notify --report-level\n"
            . "Exit 0 PASS, 1 FAIL, 2 usage, 3 refused.\n\n";
        foreach (self::USAGE as $u) {
            $s .= '  ' . strtok($u, "\n") . "\n";
        }
        return $s . "\nManual: " . $this->home . "/doc/cli.md; for AI assistants: doc/AI.md";
    }

    /** @param array<string,mixed> $result */
    private function finish(string $cmd, int $exit, array $result, string $human): int
    {
        if ($this->json) {
            $out = ['tool' => 'xne', 'version' => self::VERSION, 'command' => $cmd, 'ok' => $exit === 0, 'exit' => $exit, 'result' => $result];
            if ($this->messages) {
                $out['messages'] = $this->messages;
            }
            fwrite(STDOUT, Json::encode($out) . "\n");
        } else {
            fwrite($exit === 0 ? STDOUT : STDERR, rtrim($human) . "\n");
        }
        return $exit;
    }

    private function line(string $s): void
    {
        if ($this->json) {
            $this->messages[] = $s;
            return;
        }
        fwrite(STDOUT, $s . "\n");
    }

    // ---- commands -----------------------------------------------------------------------------------------------

    private function cmdDefaults(Config $cfg, string $root, array $pos, array $opt): int
    {
        if (empty($opt['reference'])) {
            throw XneException::usage('xne defaults needs --reference=<the root of a fresh install of the same kernel>');
        }
        $ref = rtrim((string) $opt['reference'], '/');
        $plan = \Xne\Client\Defaults::plan($root, $ref);
        $lines = [];
        foreach ($plan['warnings'] as $w) {
            $lines[] = "warning: $w";
        }
        $s = $plan['summary'];
        $lines[] = 'extensions added: ' . (implode(', ', $s['extensions_added']) ?: '-');
        foreach ($s['admin_siteaccesses'] as $sa => $x) {
            $lines[] = "admin siteaccess $sa: {$x['design']}; access extensions added: " . (implode(', ', $x['access_extensions_added']) ?: '-');
        }
        $lines[] = 'AllowedDeletionDirs added: ' . (implode(', ', $s['allowed_deletion_dirs']) ?: '-');
        foreach (array_keys($plan['files']) as $f) {
            $lines[] = "file: $f";
        }
        if ($plan['up_to_date']) {
            // the settings are there; the kernel's database steps for them may not be (a run before 0.3.0.7)
            $k = new \Xne\Client\Kernel($root, $cfg, \Xne\Client\Detect::owner($root));
            $pend = $k->has('bin/php/upgradestatus.php') ? $k->pendingStages(['database']) : null;
            if ($pend === null || ($pend['database'] ?? 0) === 0) {
                return $this->finish('defaults', 0, $plan, implode("\n", $lines) . "\nPASS: the settings have the defaults already");
            }
            $lines[] = 'the kernel reports ' . $pend['database'] . ' pending database step(s): the database stage runs';
            $plan['database_pending'] = $pend['database'];
        }
        if (isset($opt['dry-run'])) {
            return $this->finish('defaults', 0, $plan + ['dry_run' => true], implode("\n", $lines) . "\nPASS: dry run, nothing changed");
        }
        $r = \Xne\Client\Defaults::apply($cfg, $plan, $this->progress());
        $out = $plan + ['result' => $r];
        if (!$r['ok']) {
            return $this->finish('defaults', 1, $out, implode("\n", $lines) . "\nFAIL: {$r['error']}" . (!empty($r['rolled_back']) ? ' (rolled back)' : ' -- ROLLBACK FAILED: ' . ($r['rollback_error'] ?? '?')));
        }
        return $this->finish('defaults', 0, $out, implode("\n", $lines) . "\nPASS: defaults applied (backup {$r['backup']['id']})");
    }

    private function cmdDetect(Config $cfg, string $root, array $pos, array $opt): int
    {
        $d = Detect::run($root);
        if (!$this->json) {
            $this->line("Exponential {$d['version']} at {$d['root']} ({$d['install_type']}, owner {$d['owner']['user']}, PHP {$d['php']['cli']})");
            foreach ($d['databases'] as $db) {
                $this->line('database: ' . $db['engine'] . ' ' . $db['database'] . (isset($db['site_version']) ? ' (ezsite_data ' . $db['site_version'] . ')' : '') . (isset($db['error']) ? ' - ' . $db['error'] : ''));
            }
            $kinds = [];
            foreach ($d['extensions'] as $e) {
                $kinds[$e['kind']] = ($kinds[$e['kind']] ?? 0) + 1;
            }
            $this->line('extensions: ' . count($d['extensions']) . ' (' . http_build_query($kinds, '', ', ') . ')');
            $tools = array_keys(array_filter($d['tools']));
            $this->line('kernel tools: ' . implode(', ', $tools));
            $this->line('manifest: ' . ($d['manifest']['present'] ? $d['manifest']['entries'] . ' entries' : 'none'));
        }
        return $this->finish('detect', 0, $d, 'PASS: detected');
    }

    private function cmdPlan(Config $cfg, string $root, array $pos, array $opt): int
    {
        $p = Plan::make($cfg, $root, self::planOpt($opt), $this->progress());
        $d = $p->data;
        unset($d['diff']);
        $this->printPlan($p->data);
        $ok = $d['up_to_date'] || !$d['blocked'];
        return $this->finish('plan', $ok ? 0 : 3, $d, $d['up_to_date'] ? 'PASS: up to date (' . $d['install']['version'] . ')' : ($ok ? 'PASS: plan ' . $d['install']['version'] . ' -> ' . $d['target']['version'] : 'REFUSED: the plan is blocked (see above)'));
    }

    /** @param array<string,mixed> $d */
    private function printPlan(array $d): void
    {
        if ($this->json) {
            return;
        }
        foreach ($d['sources'] as $s) {
            $this->line('source ' . $s['source'] . ': ' . $s['status']);
        }
        if ($d['up_to_date']) {
            $this->line('up to date: ' . $d['install']['version'] . ' on the ' . $d['channel'] . ' channel');
            return;
        }
        $t = $d['target'];
        $this->line("upgrade {$d['install']['version']} -> {$t['version']} ({$t['kind']} {$t['ref']}, " . substr((string) $t['sha'], 0, 12) . ", from {$t['source']}), method {$d['method']}" . (isset($d['base']) ? ", base {$d['base']}" : ''));
        if (isset($d['files'])) {
            $f = $d['files'];
            $this->line("files: {$f['add']} add, {$f['update']} update, {$f['remove']} retire, {$f['local_edits']} local edit(s) kept aside, {$f['kept_retired']} retired but edited (kept), {$f['protected']} protected, {$f['shared']} shared, " . ($f['packages'] ?? 0) . " Composer's (left to the libraries stage), {$f['same']} unchanged");
            if (!empty($d['composer'])) {
                $c = $d['composer'];
                $this->line('composer.json: ' . ($c['write'] ? 'follows the release' : 'unchanged') . '; new: ' . (implode(', ', $c['added']) ?: '-') . '; constraint changed: ' . count($c['changed'])
                    . '; site extensions kept: ' . (implode(', ', array_keys($c['kept'])) ?: '-') . '; dropped: ' . (implode(', ', $c['dropped']) ?: '-'));
            }
            foreach (array_slice($d['local_edits'], 0, 20) as $x) {
                $this->line("  local edit: $x");
            }
        }
        $db = $d['database'];
        $this->line('database: ' . $db['engine'] . ', ' . ($db['mode'] === 'kernel' ? $db['kernel_command'] : ($db['mode'] === 'sql' ? count($db['sql_files']) . ' SQL file(s) ' . implode(' ', $db['sql_files']) : 'none')));
        if (isset($d['php'])) {
            $ph = $d['php'];
            $vid = (int) ($ph['version'] ?? 0);
            $this->line('php: ' . ($ph['binary'] === null ? 'none' : $ph['binary'] . ' (PHP ' . intdiv($vid, 10000) . '.' . intdiv($vid % 10000, 100) . '.' . ($vid % 100) . ')') . ': ' . $ph['reason']);
            foreach ((array) ($ph['web_from'] ?? []) as $wv => $hosts) {
                $this->line("  web PHP $wv: " . implode(', ', (array) $hosts));
            }
            foreach ((array) ($ph['skipped'] ?? []) as $b => $why) {
                $this->line("  skipped $b: $why");
            }
        }
        if ($d['libraries']['needed']) {
            $this->line('libraries: Composer runs (kernel libraries stage); backed up: ' . implode(', ', $d['libraries']['dirs']));
        }
        foreach ($d['traps'] as $t) {
            if ($t['level'] !== 'info') {
                $this->line('trap ' . $t['level'] . ' ' . $t['id'] . ': ' . $t['message']);
            }
        }
        foreach ($d['warnings'] as $w) {
            $this->line('warning: ' . $w);
        }
        $this->line('steps: ' . implode(' > ', $d['steps']));
        foreach ($d['blocked'] as $b) {
            $this->line('BLOCKED: ' . $b);
        }
    }

    private function cmdUpgrade(Config $cfg, string $root, array $pos, array $opt): int
    {
        $po = self::planOpt($opt);
        $p = Plan::make($cfg, $root, $po, $this->progress());
        $this->printPlan($p->data);
        if (isset($opt['dry-run'])) {
            $d = $p->data;
            unset($d['diff']);
            $ok = $d['up_to_date'] || !$d['blocked'];
            return $this->finish('upgrade', $ok ? 0 : 3, ['dry_run' => true, 'plan' => $d], $ok ? 'PASS: dry run, nothing changed' : 'REFUSED: the plan is blocked');
        }
        $r = (new Apply($cfg, $root, $this->progress()))->run($p, $po);
        if (!$this->json) {
            foreach ($r['steps'] as $s) {
                $this->line('done: ' . $s);
            }
            foreach ($r['kept_aside'] ?? [] as $x) {
                $this->line('kept aside: ' . $x . ' (copy in ' . ($r['backup']['dir'] ?? '') . '/kept/)');
            }
        }
        if (!empty($r['up_to_date'])) {
            return $this->finish('upgrade', 0, $r, 'PASS: up to date');
        }
        if ($r['ok']) {
            return $this->finish('upgrade', 0, $r, "PASS: upgraded {$r['from']} -> {$r['to']} (backup {$r['backup']['id']})");
        }
        $msg = 'FAIL: ' . ($r['error'] ?? 'upgrade failed') . '; ' . (!empty($r['rolled_back']) ? 'rolled back to backup ' . $r['backup']['id'] : 'ROLLBACK FAILED: ' . ($r['rollback_error'] ?? '?') . ' - run xne rollback ' . ($r['backup']['id'] ?? ''));
        return $this->finish('upgrade', 1, $r, $msg);
    }

    private function cmdRollback(Config $cfg, string $root, array $pos, array $opt): int
    {
        $dry = isset($opt['dry-run']);
        if (isset($opt['git-tag'])) {
            $g = new Git($root);
            $sha = $g->tagCommit($opt['git-tag']);
            $problems = $g->check('');
            if ($problems) {
                throw XneException::refused('git: ' . implode('; ', $problems));
            }
            if ($dry) {
                return $this->finish('rollback', 0, ['git_tag' => $opt['git-tag'], 'commit' => $sha, 'dry_run' => true], "PASS: dry run, would reset the code to {$opt['git-tag']} ($sha)");
            }
            $lock = Apply::lock($cfg, $root);
            $g->resetKeep($sha);
            flock($lock, LOCK_UN);
            return $this->finish('rollback', 0, ['git_tag' => $opt['git-tag'], 'commit' => $sha], "PASS: code reset to {$opt['git-tag']} ($sha); the database is unchanged");
        }
        $bk = new Backup($cfg, $root);
        if (isset($pos[0])) {
            $b = $bk->load($pos[0]);
        } else {
            $list = $bk->list();
            if (!$list) {
                throw XneException::usage('the install has no xne backup');
            }
            $b = $list[0];
        }
        $lock = $dry ? null : Apply::lock($cfg, $root);
        $r = (new Rollback($cfg, $root, $this->progress()))->run($b, !isset($opt['no-db']), $dry, ($opt['shared'] ?? '') === 'write');
        if (!$dry) {
            $bk->setState($b, 'rolled-back', ['rollback' => $r]);
            flock($lock, LOCK_UN);
        }
        return $this->finish('rollback', 0, $r, $dry ? "PASS: dry run, nothing changed (backup {$b['id']})" : "PASS: rolled back to backup {$b['id']} ({$r['restored']} file(s) restored, " . count($r['moved_aside']) . ' moved aside' . ($r['database'] ? ', database restored' : '') . ')');
    }

    private function cmdBackup(Config $cfg, string $root, array $pos, array $opt): int
    {
        $p = Plan::make($cfg, $root, self::planOpt($opt), $this->progress());
        $d = $p->data;
        $files = [];
        if (!$d['up_to_date'] && isset($d['diff'])) {
            $files = array_merge($d['diff']['update'], $d['diff']['local_edits'], $d['diff']['remove']);
        }
        if (isset($opt['dry-run'])) {
            return $this->finish('backup', 0, ['dry_run' => true, 'files' => count($files), 'database' => $d['install']['databases'][0]['engine'] ?? null], 'PASS: dry run, would back up ' . count($files) . ' file(s) and the database');
        }
        $lock = Apply::lock($cfg, $root);
        $drv = Factory::driver(Factory::settings($root)[0]);
        $b = (new Backup($cfg, $root))->create($files, [], [], $drv, ['from_version' => $d['install']['version'], 'target' => null, 'method' => 'manual', 'git' => null], $this->progress());
        flock($lock, LOCK_UN);
        unset($b['files']['sums']);
        return $this->finish('backup', 0, $b, "PASS: backup {$b['id']} verified in {$b['dir']}");
    }

    private function cmdBackups(Config $cfg, string $root, array $pos, array $opt): int
    {
        $list = (new Backup($cfg, $root))->list();
        $rows = [];
        foreach ($list as $b) {
            $rows[] = ['id' => $b['id'], 'state' => $b['state'], 'from' => $b['from_version'] ?? '', 'to' => $b['target']['version'] ?? '', 'method' => $b['method'] ?? '', 'files' => $b['files']['count'] ?? 0, 'db' => $b['db']['engine'] ?? null, 'dir' => $b['dir']];
            $this->line(sprintf('%-22s %-12s %s -> %s  %s, %d file(s), db %s', $b['id'], $b['state'], $b['from_version'] ?? '', $b['target']['version'] ?? '-', $b['method'] ?? '', $b['files']['count'] ?? 0, $b['db']['engine'] ?? 'none'));
        }
        return $this->finish('backups', 0, ['backups' => $rows], 'PASS: ' . count($rows) . ' backup(s)');
    }

    private function cmdVerifyBackup(Config $cfg, string $root, array $pos, array $opt): int
    {
        $bk = new Backup($cfg, $root);
        $list = $bk->list();
        $b = isset($pos[0]) ? $bk->load($pos[0]) : ($list[0] ?? null);
        if ($b === null) {
            throw XneException::usage('the install has no xne backup');
        }
        Backup::verify($b);
        return $this->finish('verify-backup', 0, ['id' => $b['id'], 'verified' => true], "PASS: backup {$b['id']} verified");
    }

    private function cmdTraps(Config $cfg, string $root, array $pos, array $opt): int
    {
        $t = Traps::run(Detect::run($root));
        foreach ($t as $x) {
            $this->line(sprintf('%-6s %-24s %s%s', $x['level'], $x['id'], $x['message'], $x['fix'] !== '' ? "\n       fix: " . $x['fix'] : ''));
        }
        $blocking = Traps::blocking($t, []);
        return $this->finish('traps', 0, ['traps' => $t, 'blocking' => count($blocking)], 'PASS: ' . count($t) . ' trap check(s), ' . count($blocking) . ' blocking');
    }

    private function cmdSources(Config $cfg, string $root, array $pos, array $opt): int
    {
        $r = new Ranker($cfg);
        [$src, $refs] = $r->first(isset($opt['refresh']));
        $current = (string) Detect::run($root, false)['version'];
        $ch = [];
        foreach (Ranker::CHANNELS as $c) {
            try {
                $t = Ranker::target($refs, $current, $c);
                $ch[$c] = $t === null ? 'up to date' : $t['ref'] . ' ' . substr((string) $t['sha'], 0, 12);
            } catch (XneException $e) {
                $ch[$c] = 'n/a: ' . $e->getMessage();
            }
            $this->line(sprintf('%-9s %s', $c, $ch[$c]));
        }
        foreach ($r->report as $s) {
            $this->line('source ' . $s['source'] . ': ' . $s['status']);
        }
        return $this->finish('sources', 0, ['sources' => $r->report, 'used' => $src->name(), 'current' => $current, 'channels' => $ch], 'PASS: source ' . $src->name());
    }

    private function cmdConfig(Config $cfg, string $root, array $pos, array $opt): int
    {
        $all = $cfg->all();
        foreach ($all as $k => $v) {
            $this->line(sprintf('%-20s %s', $k, $v));
        }
        $this->line('from: ' . implode('; ', $cfg->origins));
        return $this->finish('config', 0, ['settings' => $all, 'origins' => $cfg->origins], 'PASS: config');
    }

    private function cmdDbFingerprint(Config $cfg, string $root, array $pos, array $opt): int
    {
        $drv = Factory::driver(Factory::settings($root)[0]);
        $fp = $drv->fingerprint();
        $all = md5(Json::encode($fp, false));
        $this->line('tables: ' . count($fp) . ', fingerprint ' . $all);
        return $this->finish('db-fingerprint', 0, ['engine' => $drv->engine(), 'tables' => $fp, 'fingerprint' => $all], 'PASS: fingerprint ' . $all);
    }

    // ---- phase 2: check, report, keys, server ---------------------------------------------------------------------

    private function cmdCheck(Config $cfg, string $root, array $pos, array $opt): int
    {
        $c = \Xne\Client\Check::run($cfg, $root);
        $c['notified'] = isset($opt['no-notify']) ? [] : \Xne\Client\Notify::send($cfg, $root, $c);
        foreach ($c['channels'] as $ch => $t) {
            $this->line(sprintf('%-9s %s', $ch, $t === null ? 'up to date' : $t['ref'] . ($t['version'] !== null ? ' (' . $t['version'] . ')' : '')));
        }
        foreach ($c['advisories'] as $a) {
            $this->line('advisory ' . ($a['id'] ?? '?') . ' [' . ($a['severity'] ?? '?') . '] ' . ($a['title'] ?? '') . (isset($a['fixed_in']) ? ' (fixed in ' . $a['fixed_in'] . ')' : ''));
        }
        if (isset($c['server_error'])) {
            $this->line('server: ' . $c['server_error'] . ' (answered from the release sources)');
        }
        foreach ($c['notified'] as $n) {
            $this->line('notified: ' . $n);
        }
        $msg = $c['security'] ? 'security advisory for ' . $c['version'] : ($c['upgrade_available'] ? 'upgrade available for ' . $c['version'] : 'up to date (' . $c['version'] . ')');
        return $this->finish('check', 0, $c, 'PASS: ' . $msg . ' (via ' . $c['via'] . ')');
    }

    private function cmdReport(Config $cfg, string $root, array $pos, array $opt): int
    {
        if (isset($opt['dry-run'])) {
            $r = \Xne\Client\Check::report($cfg, $root, $cfg->get('report_level'));
            $this->line(\Xne\Json::encode($r));
            return $this->finish('report', 0, ['dry_run' => true, 'level' => $cfg->get('report_level'), 'report' => $r], 'PASS: dry run, nothing sent');
        }
        $a = \Xne\Client\Check::send($cfg, $root);
        return $this->finish('report', 0, $a, 'PASS: report taken at level ' . ($a['level'] ?? '?'));
    }

    private function cmdKeySet(Config $cfg, string $root, array $pos, array $opt): int
    {
        $line = (string) fgets(STDIN);
        $k = \Xne\Client\Key::store($cfg, $root, $line);
        return $this->finish('key:set', 0, $k, "PASS: key {$k['id']} stored (fingerprint {$k['fingerprint']})");
    }

    private function cmdKeyStatus(Config $cfg, string $root, array $pos, array $opt): int
    {
        $k = \Xne\Client\Key::load($cfg, $root);
        $r = $k === null ? ['key' => null] : ['key' => $k['id'], 'fingerprint' => \Xne\Signer::fingerprint($k['secret'])];
        return $this->finish('key:status', 0, $r, $k === null ? 'PASS: no install key (anonymous: version-only checks)' : "PASS: key {$r['key']} (fingerprint {$r['fingerprint']})");
    }

    private function cmdServerIndex(Config $cfg, string $root, array $pos, array $opt): int
    {
        $store = new \Xne\Server\Store($cfg->get('server_data'), $cfg->get('server_owner'));
        $idx = \Xne\Server\Index::build($cfg);
        if (!isset($opt['dry-run'])) {
            $store->write('index.json', $idx, 0644);
        }
        return $this->finish('server:index', 0, ['releases' => count($idx['releases']), 'branches' => count($idx['branches']), 'source' => $idx['source'], 'dry_run' => isset($opt['dry-run'])],
            'PASS: index of ' . count($idx['releases']) . ' releases from ' . $idx['source'] . (isset($opt['dry-run']) ? ' (dry run, not written)' : ''));
    }

    private function cmdServerKeyIssue(Config $cfg, string $root, array $pos, array $opt): int
    {
        foreach (['account', 'level', 'out'] as $k) {
            if (($opt[$k] ?? '') === '') {
                throw XneException::usage("--$k is required (xne help server:key:issue)");
            }
        }
        $r = ($this->registry($cfg))->issue($opt['account'], $opt['level'], $opt['out']);
        return $this->finish('server:key:issue', 0, $r, "PASS: key {$r['id']} (fingerprint {$r['fingerprint']}) written to {$r['file']} (0600)");
    }

    private function cmdServerKeyList(Config $cfg, string $root, array $pos, array $opt): int
    {
        $l = ($this->registry($cfg))->list();
        foreach ($l as $k) {
            $this->line(sprintf('%s  %-24s %-6s %s%s  %s', $k['id'], $k['account'], $k['level'], $k['created'], $k['revoked'] ? ' REVOKED' : '', $k['fingerprint']));
        }
        return $this->finish('server:key:list', 0, ['keys' => $l], 'PASS: ' . count($l) . ' key(s)');
    }

    private function cmdServerKeyRevoke(Config $cfg, string $root, array $pos, array $opt): int
    {
        if (!isset($pos[0])) {
            throw XneException::usage('xne server:key:revoke <key id>');
        }
        ($this->registry($cfg))->revoke($pos[0]);
        return $this->finish('server:key:revoke', 0, ['revoked' => $pos[0]], "PASS: key {$pos[0]} revoked");
    }

    private function cmdServerMirror(Config $cfg, string $root, array $pos, array $opt): int
    {
        $r = \Xne\Server\Mirror::run($cfg, isset($opt['dry-run']));
        foreach ($r['tags']['rejected'] as $x) {
            $this->line('tag not overwritten (tags are permanent): ' . $x);
        }
        return $this->finish('server:mirror', $r['tags']['rejected'] ? 1 : 0, $r, ($r['tags']['rejected'] ? 'FAIL: ' . count($r['tags']['rejected']) . ' tag(s) differ on the mirror' : 'PASS: ' . $r['from'] . ' mirrored to ' . $r['to']) . ($r['dry_run'] ? ' (dry run)' : ''));
    }

    private function registry(Config $cfg): \Xne\Server\Registry
    {
        $r = new \Xne\Server\Registry(new \Xne\Server\Store($cfg->get('server_data'), $cfg->get('server_owner')));
        $r->setPeers($cfg->list('server_peers'));
        return $r;
    }

    private function cmdServerKeyImport(Config $cfg, string $root, array $pos, array $opt): int
    {
        $rec = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($rec)) {
            throw XneException::usage('a key record (JSON) on stdin');
        }
        $id = $this->registry($cfg)->import($rec);
        return $this->finish('server:key:import', 0, ['imported' => $id], "PASS: key $id imported");
    }

    private function cmdServerKeyProvision(Config $cfg, string $root, array $pos, array $opt): int
    {
        foreach (['account', 'level', 'domain', 'target'] as $k) {
            if (($opt[$k] ?? '') === '') {
                throw XneException::usage("--$k is required (xne help server:key:provision)");
            }
        }
        $r = $this->registry($cfg)->provision($opt['account'], $opt['level'], $opt['domain'], $opt['target']);
        return $this->finish('server:key:provision', $r['installed'] ? 0 : 1, $r, $r['installed']
            ? "PASS: key {$r['id']} (fingerprint {$r['fingerprint']}) installed for {$r['domain']} on {$r['target']}"
            : "FAIL: key {$r['id']} issued, not installed on {$r['target']}: {$r['install_error']}");
    }
}
