<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Db\Driver;
use Xne\Db\Sqlite;
use Xne\Fs;
use Xne\Tar;
use Xne\XneException;

/**
 * Backup (owner Q9): always automatic before an upgrade. One folder per backup in
 * <state_dir>/installs/<install>/backups/<id>/ (mode 0700) with:
 *
 *   .xne-backup        the marker the guarded rotation looks for
 *   backup.json        what it holds: versions, the file lists, checksums, the database meta, the state
 *   files.tar.gz       the current content of every file the upgrade will change or retire, settings/override and
 *                      settings/siteaccess (a kernel step may write there), and libraries when
 *                      Composer will run: vendor/, composer.json/.lock and the Composer-managed extension folders)
 *   listing.json       the file listing of those library folders, so a rollback can move aside what was added
 *   db.sql.gz          MySQL/PostgreSQL: xne's framed dump; SQLite: db.sqlite, a consistent copy
 *   kept/              a plain copy of every local edit the upgrade replaces, to diff and merge by hand
 *   aside/             files the upgrade retired (moved here, never deleted)
 *
 * A backup is verified before anything is changed: the archive is read back to its end and counted, every
 * checksum compared, the dump read to its end mark and recounted (SQLite: integrity_check and counts).
 */
final class Backup
{
    /** @var Config */
    private $cfg;
    /** @var string */
    private $root;

    public function __construct(Config $cfg, string $root)
    {
        $this->cfg = $cfg;
        $this->root = $root;
    }

    public static function installId(string $root): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $root), '-');
        return substr($slug, -60) . '-' . substr(md5($root), 0, 8);
    }

    public function dir(): string
    {
        return Fs::join($this->cfg->get('state_dir'), 'installs', self::installId($this->root), 'backups');
    }

    /** @return list<array<string,mixed>> newest first */
    public function list(): array
    {
        $out = [];
        foreach ((array) @scandir($this->dir()) as $e) {
            $e = (string) $e;
            $f = $this->dir() . '/' . $e . '/backup.json';
            if ($e !== '' && $e[0] !== '.' && is_file($f)) {
                $b = Fs::readJson($f);
                $b['dir'] = $this->dir() . '/' . $e;
                $out[] = $b;
            }
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) $b['id'], (string) $a['id']);
        });
        return $out;
    }

    /** @return array<string,mixed> */
    public function load(string $id): array
    {
        if (!preg_match('/^[\w.-]+$/', $id)) {
            throw XneException::usage("no such backup: $id");
        }
        $f = $this->dir() . '/' . $id . '/backup.json';
        if (!is_file($f)) {
            throw XneException::usage("no such backup: $id (xne backups lists them)");
        }
        $b = Fs::readJson($f);
        $b['dir'] = $this->dir() . '/' . $id;
        return $b;
    }

    /**
     * Makes and verifies a backup. $files: the install paths to keep (changed or retired); $kept: local edits;
     * $libDirs: library folders to archive whole (Composer will change them); $db: the driver, or null.
     * @param list<string> $files @param list<string> $kept @param list<string> $libDirs @param array<string,mixed> $info
     * @return array<string,mixed> the backup.json content (with dir)
     */
    public function create(array $files, array $kept, array $libDirs, ?Driver $db, array $info, ?callable $progress = null): array
    {
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
        $dir = $this->dir() . '/' . $id;
        Fs::mkdir($dir, 0700);
        @chmod($this->dir(), 0700);
        file_put_contents($dir . '/' . Guard::MARKER, "xne backup of {$this->root}\n");
        $b = $info + ['id' => $id, 'created' => date('c'), 'root' => $this->root, 'state' => 'creating', 'xne' => \Xne\Console\App::VERSION];

        if ($progress) {
            $progress('backup: archiving ' . count($files) . ' file(s)' . ($libDirs ? ' and ' . count($libDirs) . ' library folder(s)' : ''));
        }
        $tw = Tar::create($dir . '/files.tar.gz');
        $sums = [];
        $present = [];
        $followed = [];
        // a file that is a link to a file outside the install (a capture's arc/ tree: composer.json, composer.lock)
        // is archived by its content, and the rollback writes that content back through the link
        $root = $this->root;
        $add = static function (string $p) use ($tw, $root, &$sums, &$followed): void {
            $abs = $root . '/' . $p;
            $real = is_link($abs) ? realpath($abs) : false;
            if ($real !== false && is_file($real) && !Fs::inside($root, dirname($real))) {
                $tw->addFile($p, $real);
                $sums[$p] = (string) Fs::fingerprint($real);
                $followed[] = $p;
                return;
            }
            $tw->addFile($p, $abs);
            $sums[$p] = (string) Fs::fingerprint($abs);
        };
        foreach (array_unique($files) as $p) {
            $abs = $this->root . '/' . $p;
            if (is_file($abs) || is_link($abs)) {
                $add($p);
                $present[] = $p;
            }
        }
        $listing = [];
        foreach ($libDirs as $ld) {
            if (is_file($this->root . '/' . $ld) || is_link($this->root . '/' . $ld)) {
                // a file, a link to a file (followed when it leads out of the install) or a link to a folder (kept
                // as the link, as before)
                $add($ld);
                continue;
            }
            if (!is_dir($this->root . '/' . $ld)) {
                $listing[$ld] = [];
                continue;
            }
            $listing[$ld] = [];
            foreach (Fs::walk($this->root . '/' . $ld, static function (string $r, bool $isDir) {
                return $isDir && basename($r) === '.git';
            }) as $r) {
                $p = $ld . '/' . $r;
                $tw->addFile($p, $this->root . '/' . $p);
                $sums[$p] = (string) Fs::fingerprint($this->root . '/' . $p);
                $listing[$ld][] = $r;
            }
        }
        $tw->close();
        @chmod($dir . '/files.tar.gz', 0600);
        Fs::writeJson($dir . '/listing.json', $listing);
        foreach ($kept as $p) {
            $abs = $this->root . '/' . $p;
            if (is_file($abs)) {
                Fs::atomicCopy($abs, $dir . '/kept/' . $p, 0600);
            }
        }
        $b['files'] = ['archive' => 'files.tar.gz', 'count' => count($sums), 'paths' => $present, 'lib_dirs' => $libDirs, 'kept' => $kept,
            'sha256' => (string) hash_file('sha256', $dir . '/files.tar.gz'), 'sums' => $sums, 'followed' => $followed];

        if ($db !== null) {
            if ($progress) {
                $progress('backup: dumping the ' . $db->engine() . ' database');
            }
            $name = $db->engine() === 'sqlite' ? 'db.sqlite' : ($db->engine() === 'mongodb' ? 'db.mongo.jsonl.gz' : ($db->engine() === 'oracle' ? 'db.oracle.jsonl.gz' : 'db.sql.gz'));
            $meta = $db->dump($dir . '/' . $name);
            $meta['file'] = $name;
            $meta['settings'] = $db->describe();
            $meta['site_version'] = $db->siteVersion();
            $b['db'] = $meta;
        } else {
            $b['db'] = null;
        }
        Fs::writeJson($dir . '/backup.json', $b);
        if ($progress) {
            $progress('backup: verifying');
        }
        self::verify($b + ['dir' => $dir]);
        $b['state'] = 'verified';
        $b['verified'] = date('c');
        Fs::writeJson($dir . '/backup.json', $b);
        $b['dir'] = $dir;
        return $b;
    }

    /** Throws when the backup is incomplete or damaged. @param array<string,mixed> $b */
    public static function verify(array $b): void
    {
        $dir = (string) $b['dir'];
        if (!is_file($dir . '/' . Guard::MARKER)) {
            throw new XneException("$dir is not an xne backup");
        }
        $arch = $dir . '/' . $b['files']['archive'];
        if (!is_file($arch) || hash_file('sha256', $arch) !== $b['files']['sha256']) {
            throw new XneException("the file archive of backup $b[id] is missing or its checksum differs");
        }
        $seen = [];
        Tar::read($arch, static function (array $e, $data) use (&$seen): void {
            if ($e['type'] === 'file') {
                $seen[$e['name']] = $data->md5();
            } elseif ($e['type'] === 'link') {
                $seen[$e['name']] = 'link:' . $e['link'];
            }
        });
        foreach ((array) $b['files']['sums'] as $p => $sum) {
            if (($seen[$p] ?? null) !== $sum) {
                throw new XneException("backup $b[id]: $p is missing from the archive or differs");
            }
        }
        if (!empty($b['db'])) {
            $f = $dir . '/' . $b['db']['file'];
            if ($b['db']['engine'] === 'oracle') {
                \Xne\Db\Oracle::verifyDump($f, $b['db']);
            } elseif ($b['db']['engine'] === 'mongodb') {
                \Xne\Db\Mongo::verifyDump($f, $b['db']);
            } elseif ($b['db']['engine'] === 'sqlite') {
                Sqlite::verifyCopy($f, $b['db']);
            } else {
                Driver::verifyDump($f, $b['db']);
            }
        }
    }

    public function setState(array $b, string $state, array $extra = []): array
    {
        $dir = (string) $b['dir'];
        unset($b['dir']);
        $b = $extra + $b;
        $b['state'] = $state;
        $b['state_at'] = date('c');
        Fs::writeJson($dir . '/backup.json', $b);
        $b['dir'] = $dir;
        return $b;
    }

    /** Keeps the newest $keep backups (and always $keepId); removes the older ones through the guard. @return list<string> */
    public function rotate(int $keep, string $keepId = ''): array
    {
        $keep = max(1, $keep);
        $removed = [];
        foreach (array_slice($this->list(), $keep) as $b) {
            if ($b['id'] === $keepId) {
                continue;
            }
            Guard::guardedRemoveBackup($this->cfg->get('state_dir'), (string) $b['dir']);
            $removed[] = (string) $b['id'];
        }
        return $removed;
    }
}
