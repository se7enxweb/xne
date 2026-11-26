<?php
declare(strict_types=1);

namespace Xne\Db;

use Xne\Fs;
use Xne\XneException;

/**
 * SQLite (DatabaseImplementation ezsqlite3): the database is one file, under var/storage/sqlite3/ unless the setting
 * is an absolute path. A backup is a consistent copy (VACUUM INTO, or SQLite3::backup); a restore writes the copy
 * back through SQLite's backup API, so a WAL file of the live database cannot outlive it.
 */
final class Sqlite extends Driver
{
    public function engine(): string
    {
        return 'sqlite';
    }

    protected function pdoDriverName(): string
    {
        return 'sqlite';
    }

    public function file(): string
    {
        return (string) $this->s['file'];
    }

    protected function connectPdo(): \PDO
    {
        if (!is_file($this->file())) {
            throw new \PDOException('no database file ' . $this->file());
        }
        return new \PDO('sqlite:' . $this->file());
    }

    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function tables(): array
    {
        $st = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function columns(string $table): array
    {
        $out = [];
        foreach ($this->pdo()->query('PRAGMA table_info(' . $this->quoteIdent($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $out[] = (string) $c['name'];
        }
        return $out;
    }

    protected function dumpInto(callable $emit): void
    {
        throw new XneException('the SQLite backup is a file copy (dump() is overridden)');
    }

    protected function dropAll(): void
    {
    }

    /** A consistent copy of the database file plus its counts, in the same meta shape as a dump. */
    public function dump(string $file): array
    {
        Fs::mkdir(dirname($file), 0700);
        $copy = preg_replace('/\.gz$/', '', $file) ;
        $copy = (string) $copy;
        if (is_file($copy)) {
            throw new XneException("$copy exists already");
        }
        $done = false;
        if (class_exists('SQLite3')) {
            $src = new \SQLite3($this->file(), SQLITE3_OPEN_READONLY);
            $dst = new \SQLite3($copy);
            $done = $src->backup($dst);
            $dst->close();
            $src->close();
        }
        if (!$done) {
            $this->pdo()->exec('VACUUM INTO ' . $this->pdo()->quote($copy));
        }
        @chmod($copy, 0600);
        $chk = new \PDO('sqlite:' . $copy);
        $ok = (string) $chk->query('PRAGMA integrity_check')->fetchColumn();
        if ($ok !== 'ok') {
            throw new XneException("the SQLite copy $copy fails its integrity check: $ok");
        }
        $counts = [];
        foreach ($chk->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $counts[(string) $t] = (int) $chk->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', (string) $t) . '"')->fetchColumn();
        }
        $chk = null;
        return ['engine' => 'sqlite', 'tables' => $counts, 'statements' => 0, 'bytes' => (int) filesize($copy), 'sha256' => (string) hash_file('sha256', $copy), 'file' => basename($copy)];
    }

    /** @param array{tables: array<string,int>, sha256: string, file?: string} $meta */
    public static function verifyCopy(string $copy, array $meta): void
    {
        if (!is_file($copy) || hash_file('sha256', $copy) !== $meta['sha256']) {
            throw new XneException("the SQLite copy $copy is missing or its checksum differs");
        }
        $chk = new \PDO('sqlite:' . $copy, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        $ok = (string) $chk->query('PRAGMA integrity_check')->fetchColumn();
        if ($ok !== 'ok') {
            throw new XneException("the SQLite copy $copy fails its integrity check: $ok");
        }
        foreach ($meta['tables'] as $t => $n) {
            $c = (int) $chk->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn();
            if ($c !== $n) {
                throw new XneException("the SQLite copy $copy holds $c rows of $t, $n expected");
            }
        }
    }

    public function restore(string $file): void
    {
        $copy = (string) preg_replace('/\.gz$/', '', $file);
        $this->pdo = null;
        if (class_exists('SQLite3')) {
            $src = new \SQLite3($copy, SQLITE3_OPEN_READONLY);
            $dst = new \SQLite3($this->file());
            $ok = $src->backup($dst);
            $dst->close();
            $src->close();
            if (!$ok) {
                throw new XneException('the SQLite restore through the backup API failed');
            }
            return;
        }
        Fs::atomicCopy($copy, $this->file(), fileperms($this->file()) & 0777);
    }
}
