<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Http;
use Xne\Json;
use Xne\Signer;
use Xne\Source\Ranker;
use Xne\XneException;

/**
 * check and report (design §2, owner Q11-Q13, Q15): what each channel offers the install and which advisories affect
 * it, from the Exponential Network server when network_url answers, else from the release sources directly (no
 * advisories then). A keyed install signs its requests; an anonymous one sends its version only. report sends the
 * install's report at its key's level: version, PHP, install type, database engine, manifest and health, plus the
 * extensions and traps for full support. Never content, users or settings values.
 */
final class Check
{
    /** @return array<string,mixed> */
    public static function run(Config $cfg, string $root): array
    {
        $det = Detect::run($root, false);
        $v = (string) $det['version'];
        $out = ['version' => $v, 'root' => $root, 'via' => null, 'channels' => [], 'advisories' => [], 'upgrade_available' => false, 'security' => false, 'keyed' => false];
        $key = Key::load($cfg, $root);
        $url = rtrim($cfg->get('network_url'), '/');
        if ($url !== '') {
            try {
                $body = Json::encode(['version' => $v, 'install' => Backup::installId($root)], false);
                $r = Http::postJson($url . '/check', $body, $key === null ? [] : Signer::headers($key['id'], $key['secret'], 'POST', self::apiPath($url) . '/check', $body));
                if ($r['status'] === 200 && is_array($r['json']) && !empty($r['json']['ok'])) {
                    $j = $r['json'];
                    return array_merge($out, ['via' => 'server', 'channels' => $j['channels'], 'advisories' => $j['advisories'],
                        'upgrade_available' => (bool) $j['upgrade_available'], 'security' => (bool) $j['security'], 'keyed' => (bool) $j['keyed']]);
                }
                if ($r['status'] === 401) {
                    throw XneException::refused('the server refuses the install key: ' . (string) ($r['json']['error'] ?? 'HTTP 401'));
                }
                $out['server_error'] = 'HTTP ' . $r['status'];
            } catch (XneException $e) {
                if ($e->getCode() === XneException::REFUSED) {
                    throw $e;
                }
                $out['server_error'] = $e->getMessage();
            }
        }
        [$src, $refs] = (new Ranker($cfg))->first();
        foreach (Ranker::CHANNELS as $c) {
            try {
                $t = Ranker::target($refs, $v, $c);
                $out['channels'][$c] = $t === null ? null : ['ref' => $t['ref'], 'sha' => $t['sha'], 'version' => $t['version']];
            } catch (XneException $e) {
                $out['channels'][$c] = null;
            }
        }
        $out['via'] = 'source ' . $src->name();
        $out['upgrade_available'] = $out['channels']['stable'] !== null || $out['channels']['security'] !== null;
        return $out;
    }

    /** "/api/v1" of "https://host/api/v1". */
    public static function apiPath(string $url): string
    {
        $p = (string) parse_url($url, PHP_URL_PATH);
        return '/' . trim($p, '/');
    }

    /** The report at a level (version, health, full); only facts about the install, never its content or users. */
    public static function report(Config $cfg, string $root, string $level): array
    {
        $det = Detect::run($root);
        $r = ['version' => $det['version'], 'install' => Backup::installId($root)];
        if ($level === 'version') {
            return $r;
        }
        $r += ['php' => $det['php']['cli'], 'install_type' => $det['install_type'], 'database_engine' => $det['databases'][0]['engine'] ?? null,
            'manifest' => $det['manifest'], 'health' => ['database_reachable' => (bool) ($det['databases'][0]['reachable'] ?? false)]];
        if ($level === 'full') {
            $r['extensions'] = array_map(static function ($e) {
                return ['name' => $e['name'], 'version' => $e['version'], 'kind' => $e['kind'], 'active' => $e['active']];
            }, $det['extensions']);
            $r['traps'] = array_map(static function ($t) {
                return ['id' => $t['id'], 'level' => $t['level']];
            }, Traps::run($det));
            $r['backups'] = count((new Backup($cfg, $root))->list());
            $r['tools'] = array_keys(array_filter($det['tools']));
        }
        return $r;
    }

    /** @return array<string,mixed> the server's answer */
    public static function send(Config $cfg, string $root): array
    {
        $key = Key::load($cfg, $root);
        if ($key === null) {
            throw XneException::refused('a report needs an install key (xne key:set); anonymous installs send their version only with xne check');
        }
        $url = rtrim($cfg->get('network_url'), '/');
        if ($url === '') {
            throw XneException::usage('network_url is not set');
        }
        $body = Json::encode(self::report($cfg, $root, $cfg->get('report_level')), false);
        $r = Http::postJson($url . '/report', $body, Signer::headers($key['id'], $key['secret'], 'POST', self::apiPath($url) . '/report', $body));
        if ($r['status'] !== 200 || empty($r['json']['ok'])) {
            throw new XneException('the report was not taken: HTTP ' . $r['status'] . ' ' . (string) ($r['json']['error'] ?? ''));
        }
        return $r['json'];
    }
}
