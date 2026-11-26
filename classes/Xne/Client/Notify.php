<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Proc;

/**
 * Notifications of "xne check" (owner Q15) through notify hooks: commands that the server's settings name, so any
 * alerting, chat or ticket tool can be plugged in without xne knowing it.
 *
 *   notify_problem_cmd  runs while an upgrade or a security advisory is open (on every check, so a tool can fold repeats)
 *   notify_ok_cmd       runs when the install is clean (to resolve what the problem hook opened)
 *   notify_event_cmd    runs once per check while something is open (a one-off event)
 *
 * A hook is a command line split into words (whitespace; "..." or '...' keep spaces in one word) and started
 * directly, never through a shell. In each word {name} is replaced by a value, and every value is also in the
 * hook's environment as XNE_NOTIFY_<NAME>: state (problem|ok), key (xne-<install id>), install (the root), version,
 * title, severity (crit for a security advisory, warn for an upgrade, ok), security (1|0), action, channels (comma
 * list of "channel ref"), advisories (comma list of ids) and body (JSON: version, channels, advisory ids).
 * notify=auto (default) runs the hooks that are set; notify=none runs none. The dashboard notice and mail to
 * AdminEmail come with exp_network (phase 3).
 */
final class Notify
{
    public const HOOKS = ['notify_problem_cmd', 'notify_ok_cmd', 'notify_event_cmd'];

    /** @param array<string,mixed> $check @return list<string> what was sent */
    public static function send(Config $cfg, string $root, array $check): array
    {
        if ($cfg->get('notify') === 'none') {
            return [];
        }
        $hooks = [];
        foreach (self::HOOKS as $h) {
            $hooks[$h] = $cfg->get($h);
        }
        // the notification settings of earlier releases, kept working on servers that still have them
        $legacy = __NAMESPACE__ . '\\NotifyLegacy';
        if (class_exists($legacy)) {
            $hooks = $legacy::hooks($cfg, $hooks);
        }
        $open = $check['upgrade_available'] || $check['security'];
        $v = self::values($root, $check, $open);
        $env = [];
        foreach ($v as $k => $val) {
            $env['XNE_NOTIFY_' . strtoupper($k)] = $val;
        }
        $sent = [];
        foreach ($open ? ['notify_problem_cmd', 'notify_event_cmd'] : ['notify_ok_cmd'] as $h) {
            if (trim((string) ($hooks[$h] ?? '')) === '') {
                continue;
            }
            $r = Proc::run(self::command((string) $hooks[$h], $v), null, $env, 60);
            $sent[] = substr($h, 7, -4) . ' hook ' . $v['state'] . ($r['exit'] === 0 ? '' : ' (failed: exit ' . $r['exit'] . ')');
        }
        return $sent;
    }

    /** @param array<string,mixed> $check @return array<string,string> */
    public static function values(string $root, array $check, bool $open): array
    {
        $channels = array_filter($check['channels']);
        $list = implode(', ', array_map(static function ($c, $t) {
            return $c . ' ' . $t['ref'];
        }, array_keys($channels), $channels));
        $ids = array_column($check['advisories'], 'id');
        return [
            'state' => $open ? 'problem' : 'ok',
            'key' => 'xne-' . Backup::installId($root),
            'install' => $root,
            'version' => (string) $check['version'],
            'title' => 'Exponential ' . $check['version'] . ' at ' . $root . ': '
                . ($open ? ($check['security'] ? 'security advisory' : 'upgrade available') . ' (' . $list . ')' : 'up to date'),
            'severity' => $open ? ($check['security'] ? 'crit' : 'warn') : 'ok',
            'security' => $check['security'] ? '1' : '0',
            'action' => 'xne plan --root=' . $root . ', then xne upgrade with the go of the site\'s owner',
            'channels' => $list,
            'advisories' => implode(',', $ids),
            'body' => \Xne\Json::encode(['version' => $check['version'], 'channels' => $check['channels'], 'advisories' => $ids], false),
        ];
    }

    /**
     * The hook's words with the placeholders filled in; an unknown {name} stays as it is.
     * @param array<string,string> $v @return list<string>
     */
    public static function command(string $line, array $v): array
    {
        $words = [];
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $line, $m, PREG_SET_ORDER);
        foreach ($m as $w) {
            $word = isset($w[3]) ? $w[3] : (isset($w[2]) ? $w[2] . $w[1] : $w[1]);
            $words[] = (string) preg_replace_callback('/\{([a-z]+)\}/', static function ($p) use ($v) {
                return array_key_exists($p[1], $v) ? $v[$p[1]] : $p[0];
            }, $word);
        }
        return $words;
    }
}
