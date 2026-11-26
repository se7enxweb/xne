<?php
declare(strict_types=1);

namespace Xne\Server;

use Xne\Signer;
use Xne\XneException;

/**
 * Install keys (owner Q11): issued per account, each with a level (health or full, Q12). A key is
 * keys/<id>.json (0600) in the server data; issuing writes "<id>:<secret>" once into a 0600 file for the account's
 * install, and nothing prints the secret. Revoked keys stay on file with "revoked".
 */
final class Registry
{
    /** @var Store */
    private $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /** @var list<string> ssh targets that keep a copy of the registry (server_peers) */
    private $peers = [];

    /** @param list<string> $peers */
    public function setPeers(array $peers): void
    {
        $this->peers = $peers;
    }

    /**
     * Copies one key record to every peer (the other nodes behind the same API name): over ssh, the record on stdin
     * only, imported there with "xne server:key:import". @return array<string,string> peer => ok or the error
     */
    public function replicate(array $record): array
    {
        $out = [];
        foreach ($this->peers as $peer) {
            $argv = array_merge(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20'], preg_split('/\s+/', trim($peer)), ['xne', 'server:key:import']);
            $r = \Xne\Proc::run($argv, null, null, 120, \Xne\Json::encode($record, false));
            $out[$peer] = $r['exit'] === 0 ? 'ok' : 'failed (exit ' . $r['exit'] . self::lastLine($r['err']) . ')';
        }
        return $out;
    }

    /** Stores a key record received from a peer. */
    public function import(array $record): string
    {
        if (!preg_match('/^xk_[0-9a-f]{16}$/', (string) ($record['id'] ?? '')) || !preg_match('/^[0-9a-f]{64}$/', (string) ($record['secret'] ?? ''))
            || !in_array($record['level'] ?? '', ['health', 'full'], true)) {
            throw XneException::usage('not a key record');
        }
        $this->store->write('keys/' . $record['id'] . '.json', array_intersect_key($record, array_flip(['id', 'secret', 'account', 'level', 'created', 'revoked'])));
        return (string) $record['id'];
    }

    /** @return array{id: string, fingerprint: string, file: string} */
    public function issue(string $account, string $level, string $outFile): array
    {
        if (!preg_match('/^[\w.@-]{1,80}$/', $account)) {
            throw XneException::usage('account: letters, digits, . _ @ - (at most 80)');
        }
        if (!in_array($level, ['health', 'full'], true)) {
            throw XneException::usage('level: health or full');
        }
        if (file_exists($outFile)) {
            throw XneException::refused("$outFile exists; choose a new file for the key");
        }
        $k = Signer::newKey();
        $this->store->write('keys/' . $k['id'] . '.json', ['id' => $k['id'], 'secret' => $k['secret'], 'account' => $account, 'level' => $level, 'created' => gmdate('c'), 'revoked' => null]);
        \Xne\Fs::atomicWrite($outFile, $k['id'] . ':' . $k['secret'] . "\n", 0600);
        $rec = $this->store->read('keys/' . $k['id'] . '.json');
        return ['id' => $k['id'], 'fingerprint' => Signer::fingerprint($k['secret']), 'file' => $outFile, 'replicated' => $this->replicate((array) $rec)];
    }


    /**
     * Issues a key for a customer install and puts it on the install's server in one step: the key file stays in
     * server_data/issued/ (0600), the key travels to "<ssh target> xne key:set --domain=<domain>" on stdin only, and
     * the fingerprint the install reports back must match. A provisioning system calls this when a customer install goes live.
     * @return array<string,mixed>
     */
    public function provision(string $account, string $level, string $domain, string $target): array
    {
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', strtolower($domain))) {
            throw XneException::usage('domain is not a valid domain name');
        }
        if ($target === '' || preg_match('/[;&|`$<>]/', $target)) {
            throw XneException::usage('target is an ssh destination (host or "-p port user@host")');
        }
        $file = $this->store->path('issued/' . strtolower($domain) . '-' . gmdate('YmdHis') . '.key');
        \Xne\Fs::mkdir(dirname($file), 0700);
        $k = $this->issue($account, $level, $file);
        $line = trim((string) file_get_contents($file));
        $argv = array_merge(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20'], preg_split('/\s+/', trim($target)), ['xne', 'key:set', '--domain=' . strtolower($domain), '--json']);
        $r = \Xne\Proc::run($argv, null, null, 120, $line . "\n");
        $j = json_decode($r['out'], true);
        $ok = $r['exit'] === 0 && is_array($j) && ($j['result']['fingerprint'] ?? '') === $k['fingerprint'];
        return $k + ['domain' => strtolower($domain), 'target' => $target, 'installed' => $ok,
            'install_error' => $ok ? null : (is_array($j) ? (string) ($j['result']['error'] ?? 'fingerprint differs') : 'exit ' . $r['exit'] . self::lastLine($r['err']))];
    }

    /** ": <the last line of a tool's error output>", or '' (for a message that says why an ssh step failed). */
    private static function lastLine(string $err): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $err) ?: []), 'strlen'));
        return $lines ? ': ' . substr($lines[count($lines) - 1], 0, 200) : '';
    }
    /** @return array<string,mixed>|null a key that is not revoked */
    public function get(string $id): ?array
    {
        if (!preg_match('/^xk_[0-9a-f]{16}$/', $id)) {
            return null;
        }
        $k = $this->store->read('keys/' . $id . '.json');
        return ($k === null || !empty($k['revoked'])) ? null : $k;
    }

    public function revoke(string $id): void
    {
        $k = $this->store->read('keys/' . $id . '.json');
        if ($k === null) {
            throw XneException::usage("no key $id");
        }
        $k['revoked'] = gmdate('c');
        $this->store->write('keys/' . $id . '.json', $k);
        $this->replicate($k);
    }

    /** @return list<array<string,mixed>> without secrets */
    public function list(): array
    {
        $out = [];
        foreach ((array) glob($this->store->dir . '/keys/xk_*.json') as $f) {
            $k = \Xne\Fs::readJson((string) $f);
            $out[] = ['id' => $k['id'], 'account' => $k['account'], 'level' => $k['level'], 'created' => $k['created'], 'revoked' => $k['revoked'], 'fingerprint' => Signer::fingerprint((string) $k['secret'])];
        }
        return $out;
    }
}
