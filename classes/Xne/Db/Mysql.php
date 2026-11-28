<?php
declare(strict_types=1);

namespace Xne\Db;

/** MySQL and MariaDB (DatabaseImplementation ezmysqli / ezmysql). */
final class Mysql extends Driver
{
    public function engine(): string
    {
        return 'mysql';
    }

    protected function pdoDriverName(): string
    {
        return 'mysql';
    }

    protected function connectPdo(): \PDO
    {
        $dsn = 'mysql:dbname=' . $this->s['database'] . ';charset=utf8mb4';
        if (($this->s['socket'] ?? '') !== '' && $this->s['socket'] !== 'disabled') {
            $dsn .= ';unix_socket=' . $this->s['socket'];
        } else {
            $dsn .= ';host=' . (($this->s['server'] ?? '') !== '' ? $this->s['server'] : 'localhost');
            if (($this->s['port'] ?? '') !== '') {
                $dsn .= ';port=' . (int) $this->s['port'];
            }
        }
        return new \PDO($dsn, (string) ($this->s['user'] ?? ''), (string) ($this->s['password'] ?? ''), [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    }

    public function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function tables(): array
    {
        return $this->listByType('BASE TABLE');
    }

    /** @return list<string> */
    private function listByType(string $type): array
    {
        $st = $this->pdo()->query('SHOW FULL TABLES');
        $out = [];
        while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
            if ($r[1] === $type) {
                $out[] = (string) $r[0];
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public function columns(string $table): array
    {
        $out = [];
        $st = $this->pdo()->query('SHOW COLUMNS FROM ' . $this->quoteIdent($table));
        while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
            $out[] = (string) $r[0];
        }
        return $out;
    }

    protected function dumpInto(callable $emit): void
    {
        $pdo = $this->pdo();
        $emit('SET FOREIGN_KEY_CHECKS=0');
        $emit("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        foreach ($this->tables() as $t) {
            $ddl = (string) $pdo->query('SHOW CREATE TABLE ' . $this->quoteIdent($t))->fetch(\PDO::FETCH_NUM)[1];
            $emit($ddl);
            $batch = [];
            $size = 0;
            $this->eachRow('SELECT * FROM ' . $this->quoteIdent($t), function (array $row) use ($emit, $t, &$batch, &$size): void {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $this->literal($v);
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line;
                $size += strlen($line);
                if (count($batch) >= 200 || $size > 1000000) {
                    $emit('INSERT INTO ' . $this->quoteIdent($t) . ' VALUES ' . implode(',', $batch), $t, count($batch));
                    $batch = [];
                    $size = 0;
                }
            });
            if ($batch) {
                $emit('INSERT INTO ' . $this->quoteIdent($t) . ' VALUES ' . implode(',', $batch), $t, count($batch));
            }
        }
        foreach ($this->listByType('VIEW') as $v) {
            $ddl = (string) $pdo->query('SHOW CREATE VIEW ' . $this->quoteIdent($v))->fetch(\PDO::FETCH_NUM)[1];
            $emit((string) preg_replace('/\sDEFINER=\S+\s/', ' ', $ddl));
        }
        $emit('SET FOREIGN_KEY_CHECKS=1');
    }

    /** MySQL has no transactional DDL: the restore drops and recreates every table with foreign keys off. */
    protected function dropAll(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->listByType('VIEW') as $v) {
            $pdo->exec('DROP VIEW ' . $this->quoteIdent($v));
        }
        foreach ($this->tables() as $t) {
            $pdo->exec('DROP TABLE ' . $this->quoteIdent($t));
        }
    }

    protected function afterRestore(bool $ok): void
    {
        try {
            $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
        }
    }
}
