<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Fs;
use Xne\Signer;
use Xne\XneException;

/**
 * The install key (owner Q11): a key id and its secret issued for the install's account, stored in
 * <state_dir>/installs/<install>/key.json with mode 0600, read from stdin only (never from the command line, so it is
 * never in a process list or a shell history), and never printed: only its id and a short fingerprint are shown.
 * Inside an install the exp_network extension (phase 3) keeps the same key with the kernel's expSecrets.
 */
final class Key
{
    public static function file(Config $cfg, string $root): string
    {
        return Fs::join($cfg->get('state_dir'), 'installs', Backup::installId($root), 'key.json');
    }

    /** @return array{id: string, secret: string}|null */
    public static function load(Config $cfg, string $root): ?array
    {
        $f = self::file($cfg, $root);
        if (!is_file($f)) {
            return null;
        }
        // Windows keeps access in ACLs (fileperms() always reports 0666 there); the state folder's ACL protects it
        if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($f) & 0077) !== 0) {
            throw XneException::refused("$f is readable by others; chmod 0600 it");
        }
        $d = Fs::readJson($f);
        if (!isset($d['id'], $d['secret']) || !preg_match('/^xk_[0-9a-f]{16}$/', (string) $d['id'])) {
            throw new XneException("$f is not an xne key");
        }
        return ['id' => (string) $d['id'], 'secret' => (string) $d['secret']];
    }

    /** Parses "<id>:<secret>" (one line from stdin) and stores it 0600. @return array{id: string, fingerprint: string} */
    public static function store(Config $cfg, string $root, string $line): array
    {
        $line = trim($line);
        if (!preg_match('/^(xk_[0-9a-f]{16}):([0-9a-f]{64})$/', $line, $m)) {
            throw XneException::usage('the key is "<id>:<secret>" on stdin (xk_ and 16 hex, a colon, 64 hex)');
        }
        $f = self::file($cfg, $root);
        Fs::mkdir(dirname($f), 0700);
        Fs::writeJson($f, ['id' => $m[1], 'secret' => $m[2], 'stored' => date('c')], 0600);
        return ['id' => $m[1], 'fingerprint' => Signer::fingerprint($m[2])];
    }
}
