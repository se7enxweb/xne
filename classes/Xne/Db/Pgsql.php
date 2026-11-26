<?php
declare(strict_types=1);

namespace Xne\Db;

/**
 * PostgreSQL (DatabaseImplementation ezpostgresql), schema of the connection's search_path (public). The dump is
 * built from the catalog: sequences with their positions, tables with columns and defaults, data, then constraints,
 * indexes, sequence ownership and views. The restore runs in one transaction, so it either completes or changes
 * nothing.
 */
final class Pgsql extends Driver
{
    public function engine(): string
    {
        return 'postgresql';
    }

    protected function pdoDriverName(): string
    {
        return 'pgsql';
    }

    protected function connectPdo(): \PDO
    {
        $host = ($this->s['server'] ?? '') !== '' ? $this->s['server'] : 'localhost';
        if (($this->s['socket'] ?? '') !== '' && $this->s['socket'] !== 'disabled') {
            $host = $this->s['socket'];
        }
        $dsn = 'pgsql:host=' . $host . ';dbname=' . $this->s['database'];
        if (($this->s['port'] ?? '') !== '') {
            $dsn .= ';port=' . (int) $this->s['port'];
        }
        return new \PDO($dsn, (string) ($this->s['user'] ?? ''), (string) ($this->s['password'] ?? ''));
    }

    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** @return list<string> */
    private function rels(string $kinds): array
    {
        $st = $this->pdo()->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema() AND c.relkind IN ($kinds) ORDER BY c.relname");
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function tables(): array
    {
        return $this->rels("'r','p'");
    }

    public function columns(string $table): array
    {
        $st = $this->pdo()->prepare("SELECT a.attname FROM pg_attribute a WHERE a.attrelid = (quote_ident(current_schema()) || '.' || quote_ident(?))::regclass
            AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum");
        $st->execute([$table]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }


    /** json columns cannot be compared: the rows are ordered by their text form. */
    protected function orderedSelect(string $table, array $cols): string
    {
        return 'SELECT x.* FROM ' . $this->quoteIdent($table) . ' AS x ORDER BY x::text';
    }
    private function oid(string $table): int
    {
        $st = $this->pdo()->prepare("SELECT (quote_ident(current_schema()) || '.' || quote_ident(?))::regclass::oid");
        $st->execute([$table]);
        return (int) $st->fetchColumn();
    }

    /** @param mixed $v */
    public function literal($v): string
    {
        if (is_resource($v)) {
            return "'\\x" . bin2hex((string) stream_get_contents($v)) . "'::bytea";
        }
        return parent::literal($v);
    }

    protected function dumpInto(callable $emit): void
    {
        $pdo = $this->pdo();
        $emit('SET client_encoding = \'UTF8\'');
        foreach ($this->rels("'S'") as $seq) {
            $q = $this->quoteIdent($seq);
            $p = $pdo->query("SELECT increment_by, min_value, max_value, start_value, cache_size, cycle FROM pg_sequences
                WHERE schemaname = current_schema() AND sequencename = " . $pdo->quote($seq))->fetch(\PDO::FETCH_ASSOC);
            $emit("CREATE SEQUENCE $q INCREMENT BY $p[increment_by] MINVALUE $p[min_value] MAXVALUE $p[max_value] START WITH $p[start_value] CACHE $p[cache_size]" . ($p['cycle'] ? ' CYCLE' : ''));
            $pos = $pdo->query("SELECT last_value, is_called FROM $q")->fetch(\PDO::FETCH_ASSOC);
            $emit("SELECT setval('" . str_replace("'", "''", $q) . "', $pos[last_value], " . ($pos['is_called'] ? 'true' : 'false') . ')');
        }
        $late = [];
        foreach ($this->tables() as $t) {
            $oid = $this->oid($t);
            $cols = $pdo->query("SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull, pg_get_expr(d.adbin, d.adrelid) AS def,
                    a.attidentity, a.attgenerated
                FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                WHERE a.attrelid = $oid AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum")->fetchAll(\PDO::FETCH_ASSOC);
            $defs = [];
            $identity = false;
            $names = [];
            foreach ($cols as $c) {
                $d = $this->quoteIdent($c['attname']) . ' ' . $c['type'];
                if ($c['attidentity'] === 'a' || $c['attidentity'] === 'd') {
                    $d .= ' GENERATED ' . ($c['attidentity'] === 'a' ? 'ALWAYS' : 'BY DEFAULT') . ' AS IDENTITY';
                    $identity = true;
                } elseif (($c['attgenerated'] ?? '') === 's') {
                    $d .= ' GENERATED ALWAYS AS (' . $c['def'] . ') STORED';
                } elseif ($c['def'] !== null) {
                    $d .= ' DEFAULT ' . $c['def'];
                }
                if ($c['attnotnull']) {
                    $d .= ' NOT NULL';
                }
                $defs[] = $d;
                if (($c['attgenerated'] ?? '') !== 's') {
                    $names[] = $this->quoteIdent($c['attname']);
                }
            }
            $qt = $this->quoteIdent($t);
            $emit("CREATE TABLE $qt (\n    " . implode(",\n    ", $defs) . "\n)");
            $colList = implode(', ', $names);
            $st = $pdo->query("SELECT $colList FROM $qt");
            $batch = [];
            $size = 0;
            $ins = "INSERT INTO $qt ($colList)" . ($identity ? ' OVERRIDING SYSTEM VALUE' : '') . ' VALUES ';
            while (($row = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $this->literal($v);
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line;
                $size += strlen($line);
                if (count($batch) >= 200 || $size > 1000000) {
                    $emit($ins . implode(',', $batch), $t, count($batch));
                    $batch = [];
                    $size = 0;
                }
            }
            if ($batch) {
                $emit($ins . implode(',', $batch), $t, count($batch));
            }
            if ($identity) {
                foreach ($cols as $c) {
                    if ($c['attidentity'] === 'a' || $c['attidentity'] === 'd') {
                        $col = $this->quoteIdent($c['attname']);
                        $emit("SELECT setval(pg_get_serial_sequence('" . str_replace("'", "''", $qt) . "', '" . str_replace("'", "''", $c['attname']) . "'), COALESCE((SELECT MAX($col) FROM $qt), 1), (SELECT COUNT(*) > 0 FROM $qt))");
                    }
                }
            }
            foreach ($pdo->query("SELECT conname, contype, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = $oid ORDER BY contype = 'f', conname")->fetchAll(\PDO::FETCH_ASSOC) as $con) {
                $sql = "ALTER TABLE $qt ADD CONSTRAINT " . $this->quoteIdent($con['conname']) . ' ' . $con['def'];
                if ($con['contype'] === 'f') {
                    $late[] = $sql;
                } else {
                    $emit($sql);
                }
            }
            foreach ($pdo->query("SELECT pg_get_indexdef(i.indexrelid) FROM pg_index i
                    WHERE i.indrelid = $oid AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.indexrelid AND c.conrelid = $oid)
                    ORDER BY i.indexrelid")->fetchAll(\PDO::FETCH_COLUMN) as $idx) {
                $emit((string) $idx);
            }
        }
        foreach ($late as $sql) {
            $emit($sql);
        }
        foreach ($pdo->query("SELECT s.relname AS seq, t.relname AS tbl, a.attname AS col FROM pg_depend d
                JOIN pg_class s ON s.oid = d.objid AND s.relkind = 'S' JOIN pg_namespace n ON n.oid = s.relnamespace
                JOIN pg_class t ON t.oid = d.refobjid JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
                WHERE d.deptype = 'a' AND n.nspname = current_schema()")->fetchAll(\PDO::FETCH_ASSOC) as $o) {
            $emit('ALTER SEQUENCE ' . $this->quoteIdent($o['seq']) . ' OWNED BY ' . $this->quoteIdent($o['tbl']) . '.' . $this->quoteIdent($o['col']));
        }
        foreach ($this->rels("'v'") as $v) {
            $def = (string) $pdo->query("SELECT pg_get_viewdef(" . $pdo->quote($v) . "::regclass, true)")->fetchColumn();
            $emit('CREATE VIEW ' . $this->quoteIdent($v) . ' AS ' . rtrim(trim($def), ';'));
        }
    }

    protected function beforeRestore(): void
    {
        $this->pdo()->beginTransaction();
    }

    protected function dropAll(): void
    {
        $pdo = $this->pdo();
        foreach ($this->rels("'v','m'") as $v) {
            $pdo->exec('DROP VIEW IF EXISTS ' . $this->quoteIdent($v) . ' CASCADE');
        }
        foreach ($this->tables() as $t) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdent($t) . ' CASCADE');
        }
        foreach ($this->rels("'S'") as $s) {
            $pdo->exec('DROP SEQUENCE IF EXISTS ' . $this->quoteIdent($s) . ' CASCADE');
        }
    }

    protected function afterRestore(bool $ok): void
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            $ok ? $pdo->commit() : $pdo->rollBack();
        }
    }
}
