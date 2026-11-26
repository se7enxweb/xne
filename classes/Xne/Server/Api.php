<?php
declare(strict_types=1);

namespace Xne\Server;

use Xne\Signer;
use Xne\Version;

/**
 * The Exponential Network API v1 (design §2), one class with no framework, so it runs inside the network.se7enx.com
 * site module, behind server/public/index.php, or in the self-host mode with the same code.
 *
 *   GET  /api/v1/status                       the API is up, the index age, the xne version
 *   GET  /api/v1/releases                     the release index
 *   GET  /api/v1/advisories?version=6.0.14    the advisories that affect a version
 *   POST /api/v1/check     {"version": ...}   what each channel offers, the advisories, upgrade_available.
 *                                              Anonymous: only the version is read and only counted (Q13).
 *                                              Signed (X-Xne-*): also kept as the install's last check.
 *   POST /api/v1/report    {...}              signed only: the install's report at its key's level (Q12)
 *
 * Every answer is JSON {"ok": bool, ...}. A bad signature is 401, an unknown path 404, a bad body 400.
 */
final class Api
{
    /** @var Store */
    private $store;
    /** @var Registry */
    private $registry;
    /** @var int|null for tests */
    public $now;

    public function __construct(Store $store)
    {
        $this->store = $store;
        $this->registry = new Registry($store);
    }

    /**
     * @param array<string,string> $headers lower-case names
     * @return array{0: int, 1: array<string,mixed>}
     */
    public function handle(string $method, string $uri, array $headers, string $body): array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $path = '/' . trim((string) preg_replace('#^.*?/api/v1#', '', $path), '/');
        $method = strtoupper($method);
        $key = null;
        if (isset($headers['x-xne-key'])) {
            $key = $this->registry->get((string) $headers['x-xne-key']);
            if ($key === null || !Signer::verify((string) $key['secret'], $method, '/api/v1' . $path, (string) ($headers['x-xne-time'] ?? ''), $body, (string) ($headers['x-xne-signature'] ?? ''), $this->now)) {
                return [401, ['ok' => false, 'error' => 'the signature does not verify (unknown or revoked key, wrong secret, or a clock more than 5 minutes off)']];
            }
        }
        $index = $this->store->read('index.json');
        switch ($method . ' ' . $path) {
            case 'GET /status':
                return [200, ['ok' => true, 'api' => 'xne/1', 'version' => \Xne\Console\App::VERSION, 'index_built' => $index['built'] ?? null, 'releases' => count($index['releases'] ?? [])]];
            case 'GET /releases':
                return $index === null ? [503, ['ok' => false, 'error' => 'the release index is not built yet']] : [200, ['ok' => true] + $index];
            case 'GET /advisories':
                $v = (string) ($q['version'] ?? '');
                if (!Version::valid($v)) {
                    return [400, ['ok' => false, 'error' => 'version is required']];
                }
                return [200, ['ok' => true, 'version' => $v, 'advisories' => Advisories::forVersion($this->advisories(), $v)]];
            case 'POST /check':
                $d = json_decode($body, true);
                $v = is_array($d) ? (string) ($d['version'] ?? '') : '';
                if (!Version::valid($v)) {
                    return [400, ['ok' => false, 'error' => 'the body is {"version": "<x.y.z>"}']];
                }
                if ($index === null) {
                    return [503, ['ok' => false, 'error' => 'the release index is not built yet']];
                }
                $this->count('checks', $v);
                if ($key !== null) {
                    $this->store->write('installs/' . $key['id'] . '/last-check.json', ['at' => gmdate('c'), 'version' => $v, 'install' => (string) ($d['install'] ?? '')]);
                }
                $ch = Index::channels($index, $v);
                $adv = Advisories::forVersion($this->advisories(), $v);
                return [200, ['ok' => true, 'version' => $v, 'channels' => $ch, 'advisories' => $adv,
                    'upgrade_available' => $ch['stable'] !== null || $ch['security'] !== null, 'security' => $adv !== [], 'keyed' => $key !== null]];
            case 'POST /report':
                if ($key === null) {
                    return [401, ['ok' => false, 'error' => 'a report needs an install key (anonymous installs send their version only)']];
                }
                $d = json_decode($body, true);
                if (!is_array($d) || !Version::valid((string) ($d['version'] ?? ''))) {
                    return [400, ['ok' => false, 'error' => 'the body is a report with a version']];
                }
                $d = self::trimToLevel($d, (string) $key['level']);
                $this->store->write('installs/' . $key['id'] . '/report-' . gmdate('Ymd') . '.json', ['at' => gmdate('c'), 'account' => $key['account'], 'report' => $d]);
                return [200, ['ok' => true, 'stored' => true, 'level' => $key['level']]];
        }
        return [404, ['ok' => false, 'error' => "no such endpoint: $method /api/v1$path"]];
    }

    /** @return list<array<string,mixed>> */
    private function advisories(): array
    {
        return array_values((array) ($this->store->read('advisories.json') ?? []));
    }

    private function count(string $what, string $version): void
    {
        $f = 'counts/' . $what . '-' . gmdate('Ymd') . '.json';
        $c = $this->store->read($f) ?? [];
        $c[$version] = (int) ($c[$version] ?? 0) + 1;
        $this->store->write($f, $c);
    }

    /** Only what the key's level allows is kept (Q12); content and user data are never part of a report. */
    public static function trimToLevel(array $r, string $level): array
    {
        $keep = ['version', 'php', 'install_type', 'database_engine', 'health', 'manifest', 'install'];
        if ($level === 'full') {
            $keep = array_merge($keep, ['extensions', 'traps', 'backups', 'tools']);
        }
        return array_intersect_key($r, array_flip($keep));
    }
}
