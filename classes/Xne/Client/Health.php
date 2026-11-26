<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Filelist;
use Xne\Fs;
use Xne\Http;
use Xne\XneException;

/**
 * The health check after an upgrade; any failure starts the automatic rollback.
 *   files     every written file equals the release, every retired file is gone from its place
 *   manifest  share/filelist.md5 is the target's own (the live manifest lands with every upgrade)
 *   bootstrap the install starts from the CLI with the new code (ezcache.php --list-tags)
 *   kernel    exp:upgrade:status: no step failed and none of the stages that ran is still pending
 *   urls      every health_url answers 200 to 399 (when set)
 */
final class Health
{
    /**
     * @param array<string,mixed> $diff @param array<string,string> $target @param list<string> $ranStages
     * @return array<string,string> check => result
     */
    public static function run(Config $cfg, string $root, Kernel $k, ?array $diff, array $target, array $ranStages, string $method): array
    {
        $out = [];
        if ($diff !== null && $method === 'filepatch') {
            foreach (array_merge($diff['add'], $diff['update'], $diff['local_edits']) as $p) {
                if (isset($target[$p]) && Fs::fingerprint($root . '/' . $p) !== $target[$p]) {
                    throw new XneException("health: $p differs from the release");
                }
            }
            foreach ($diff['remove'] as $p) {
                if (is_file($root . '/' . $p)) {
                    throw new XneException("health: the retired file $p is still in place");
                }
            }
            $out['files'] = 'ok (' . (count($diff['add']) + count($diff['update']) + count($diff['local_edits'])) . ' written, ' . count($diff['remove']) . ' retired)';
        }
        if (isset($target['share/filelist.md5'])) {
            if (Fs::fingerprint($root . '/share/filelist.md5') !== $target['share/filelist.md5']) {
                throw new XneException('health: share/filelist.md5 is not the release\'s own');
            }
            $mf = (array) Filelist::read($root . '/share/filelist.md5');
            // a release file that is a link in the install (a capture's arc/ layout: composer.json, .htaccess) is
            // left as a link by the file patch on purpose and listed in the plan as "links": it is no difference
            // and a release file in a Composer folder (or composer.json, composer.lock) follows composer.lock, not
            // the release: the libraries stage installed it (FilePatch "packages")
            $links = $diff !== null ? array_flip(array_merge((array) ($diff['links'] ?? []), (array) ($diff['packages'] ?? []))) : [];
            $bad = [];
            foreach ($mf as $p => $sum) {
                if (isset($target[$p]) && $target[$p] === $sum && !isset($links[$p]) && Fs::fingerprint($root . '/' . $p) !== $sum) {
                    $bad[] = $p;
                }
            }
            if ($bad) {
                throw new XneException('health: ' . count($bad) . ' kernel file(s) differ from share/filelist.md5: ' . implode(', ', array_slice($bad, 0, 10)) . (count($bad) > 10 ? ', ...' : ''));
            }
            $out['manifest'] = 'ok (' . count($mf) . ' entries)';
        }
        $k->bootstrapCheck();
        $out['bootstrap'] = 'ok';
        if ($ranStages) {
            $st = $k->upgradeStatus();
            if ($st !== null) {
                foreach ((array) ($st['stages'] ?? []) as $stage) {
                    $name = (string) ($stage['stage'] ?? $stage['name'] ?? $stage['id'] ?? '');
                    foreach ((array) ($stage['steps'] ?? []) as $step) {
                        $state = (string) ($step['state'] ?? '');
                        if ($state === 'failed' || ($state === 'pending' && in_array($name !== '' ? $name : (string) ($step['stage'] ?? ''), $ranStages, true))) {
                            throw new XneException('health: kernel upgrade step ' . ($step['id'] ?? '?') . ' is ' . $state);
                        }
                    }
                }
                $out['kernel'] = 'ok (' . json_encode($st['summary'] ?? null) . ')';
            }
        }
        foreach ($cfg->list('health_url') as $u) {
            $r = Http::get($u);
            if ($r['status'] < 200 || $r['status'] >= 400) {
                throw new XneException("health: $u answers HTTP $r[status]");
            }
            $out['url ' . $u] = 'HTTP ' . $r['status'];
        }
        return $out;
    }
}
