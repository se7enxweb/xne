<?php
declare(strict_types=1);

namespace Xne\Db;

use Xne\XneException;

/**
 * Oracle (DatabaseImplementation ezoracle), through the PHP oci8 extension with the install's own settings: User,
 * Password and Database (a connect string such as //127.0.0.1:1521/FREEPDB1, or Server + Port + Database as service).
 * The backup is a logical export in pure PHP, without expdp, RMAN or a directory object on the server:
 *   - the DDL of every table, sequence (at its current position), index and trigger of the schema from DBMS_METADATA
 *     (storage, tablespace and segment clauses left out, so it restores into any tablespace);
 *   - the rows as JSON lines (BLOBs base64, CLOBs whole), restored with bound variables, so a CLOB or BLOB of any size
 *     and every character survive; dates and timestamps travel in a fixed ISO format.
 * The restore drops the schema's tables (PURGE) and sequences, recreates tables and sequences, loads the rows, then
 * creates indexes and triggers, so a trigger that fills ids never touches the restored rows.
 */
final class Oracle extends Driver
{
    /** @var resource|null */
    private $c;

    private const NLS = ["ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD\"T\"HH24:MI:SS'", "ALTER SESSION SET NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD\"T\"HH24:MI:SS.FF6'",
        "ALTER SESSION SET NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD\"T\"HH24:MI:SS.FF6 TZH:TZM'", "ALTER SESSION SET NLS_NUMERIC_CHARACTERS = '.,'"];

    public function engine(): string
    {
        return 'oracle';
    }

    protected function pdoDriverName(): string
    {
        return 'oci';
    }

    protected function connectPdo(): \PDO
    {
        throw new XneException('Oracle is driven through oci8, not PDO');
    }

    public function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function connectString(): string
    {
        $db = (string) ($this->s['database'] ?? '');
        if (($this->s['server'] ?? '') !== '' && strpos($db, '/') === false && strpos($db, '(') === false) {
            return '//' . $this->s['server'] . ':' . (($this->s['port'] ?? '') !== '' ? $this->s['port'] : '1521') . '/' . $db;
        }
        return $db;
    }

    /** @return resource */
    public function conn()
    {
        if ($this->c === null) {
            if (!function_exists('oci_connect')) {
                throw new XneException('this PHP (' . PHP_BINARY . ') has no oci8 extension for the Oracle database; run xne with a PHP that has it (--php)');
            }
            $c = @oci_new_connect((string) $this->s['user'], (string) $this->s['password'], $this->connectString(), 'AL32UTF8');
            if ($c === false) {
                $e = oci_error();
                throw new XneException('cannot connect to the Oracle database: ' . $this->clean((string) ($e['message'] ?? 'unknown error')));
            }
            $this->c = $c;
            foreach (self::NLS as $sql) {
                $this->statement($sql);
            }
        }
        return $this->c;
    }

    /** Runs one statement (autocommit unless $commit is false). @param array<string,mixed> $binds */
    public function statement(string $sql, array $binds = [], bool $commit = true): void
    {
        $st = oci_parse($this->conn(), $sql);
        if ($st === false) {
            throw new XneException('Oracle could not parse: ' . substr($sql, 0, 200));
        }
        foreach ($binds as $k => $v) {
            oci_bind_by_name($st, $k, $binds[$k]);
        }
        if (!@oci_execute($st, $commit ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($st);
            throw new XneException('Oracle: ' . $this->clean((string) ($e['message'] ?? 'error')) . ' in ' . substr($sql, 0, 160));
        }
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql, array $binds = []): array
    {
        $st = oci_parse($this->conn(), $sql);
        foreach ($binds as $k => $v) {
            oci_bind_by_name($st, $k, $binds[$k]);
        }
        if (!@oci_execute($st, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($st);
            throw new XneException('Oracle: ' . $this->clean((string) ($e['message'] ?? 'error')) . ' in ' . substr($sql, 0, 160));
        }
        $out = [];
        while (($r = oci_fetch_array($st, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
            $out[] = $r;
        }
        return $out;
    }

    /** Ends the session (a schema cannot be dropped while one is open). */
    public function close(): void
    {
        if ($this->c !== null) {
            oci_close($this->c);
            $this->c = null;
        }
    }

    public function tables(): array
    {
        return array_map(static function ($r) {
            return (string) $r['TABLE_NAME'];
        }, $this->rows("SELECT table_name FROM user_tables WHERE table_name NOT LIKE 'BIN$%' AND nested = 'NO' AND iot_type IS NULL AND secondary = 'N' ORDER BY table_name"));
    }

    public function columns(string $table): array
    {
        return array_map(static function ($r) {
            return (string) $r['COLUMN_NAME'];
        }, $this->rows('SELECT column_name FROM user_tab_columns WHERE table_name = :t ORDER BY column_id', [':t' => $table]));
    }

    /** @return array<string,string> column => data type */
    private function types(string $table): array
    {
        $out = [];
        foreach ($this->rows('SELECT column_name, data_type FROM user_tab_columns WHERE table_name = :t ORDER BY column_id', [':t' => $table]) as $r) {
            $out[(string) $r['COLUMN_NAME']] = (string) $r['DATA_TYPE'];
        }
        return $out;
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $out[$t] = (int) $this->rows('SELECT COUNT(*) AS N FROM ' . $this->quoteIdent($t))[0]['N'];
        }
        return $out;
    }

    public function siteVersion(): ?string
    {
        try {
            $r = $this->rows("SELECT value FROM ezsite_data WHERE name = 'ezpublish-version'");
            return $r ? (string) $r[0]['VALUE'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** One row as text, BLOBs as base64 (the same form the dump keeps). */
    private function rowValues(array $row, array $types): array
    {
        $v = [];
        foreach ($types as $col => $type) {
            $x = $row[$col] ?? null;
            if ($x !== null && ($type === 'BLOB' || $type === 'RAW' || $type === 'LONG RAW')) {
                $x = base64_encode((string) $x);
            }
            $v[] = $x === null ? null : (string) $x;
        }
        return $v;
    }

    public function fingerprint(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $types = $this->types($t);
            $lines = [];
            foreach ($this->rows('SELECT * FROM ' . $this->quoteIdent($t)) as $r) {
                $lines[] = json_encode($this->rowValues($r, $types));
            }
            sort($lines, SORT_STRING);
            $out[$t] = ['rows' => count($lines), 'md5' => md5(implode("\n", $lines))];
        }
        foreach ($this->rows('SELECT sequence_name, last_number FROM user_sequences ORDER BY 1') as $s) {
            $out['sequence ' . $s['SEQUENCE_NAME']] = ['rows' => 0, 'md5' => md5((string) $s['LAST_NUMBER'])];
        }
        ksort($out);
        return $out;
    }

    private function ddl(string $type, string $name, bool $dependent = false): array
    {
        $this->statement("BEGIN DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'STORAGE', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'SEGMENT_ATTRIBUTES', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'TABLESPACE', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'EMIT_SCHEMA', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'SQLTERMINATOR', FALSE); END;");
        $fn = $dependent ? 'DBMS_METADATA.GET_DEPENDENT_DDL' : 'DBMS_METADATA.GET_DDL';
        try {
            $r = $this->rows("SELECT $fn(:ty, :nm) AS D FROM dual", [':ty' => $type, ':nm' => $name]);
        } catch (XneException $e) {
            if (strpos($e->getMessage(), 'ORA-31608') !== false) {
                return [];
            }
            throw $e;
        }
        $text = trim((string) ($r[0]['D'] ?? ''));
        if ($text === '') {
            return [];
        }
        // GET_DEPENDENT_DDL returns several statements separated by blank lines; ALTER TRIGGER ... ENABLE follows a trigger
        $parts = preg_split('/\n\s*(?=CREATE |ALTER )/', $text);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    protected function dumpInto(callable $emit): void
    {
        throw new XneException('dump() is overridden');
    }

    protected function dropAll(): void
    {
        // domain indexes first (an Oracle Text CONTEXT index owns its DR$ tables), then the tables, then whatever is
        // left (DR$ tables of a domain index that failed half-way)
        foreach ($this->rows("SELECT index_name FROM user_indexes WHERE index_type = 'DOMAIN'") as $i) {
            $this->statement('DROP INDEX ' . $this->quoteIdent((string) $i['INDEX_NAME']) . ' FORCE');
        }
        foreach ($this->tables() as $t) {
            $this->statement('DROP TABLE ' . $this->quoteIdent($t) . ' CASCADE CONSTRAINTS PURGE');
        }
        foreach ($this->rows("SELECT table_name FROM user_tables WHERE table_name NOT LIKE 'BIN$%'") as $t) {
            $this->statement('DROP TABLE ' . $this->quoteIdent((string) $t['TABLE_NAME']) . ' CASCADE CONSTRAINTS PURGE');
        }
        foreach ($this->rows('SELECT sequence_name FROM user_sequences') as $s) {
            $this->statement('DROP SEQUENCE ' . $this->quoteIdent((string) $s['SEQUENCE_NAME']));
        }
    }

    public function dump(string $file): array
    {
        \Xne\Fs::mkdir(dirname($file), 0700);
        $gz = @gzopen($file, 'wb6');
        if ($gz === false) {
            throw new XneException("cannot write $file");
        }
        $w = static function (array $rec) use ($gz): void {
            gzwrite($gz, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        };
        $w(['xne' => 'oracle-dump/1', 'at' => gmdate('c')]);
        $counts = [];
        $late = [];
        foreach ($this->rows('SELECT sequence_name, min_value, max_value, increment_by, cycle_flag, cache_size, last_number FROM user_sequences ORDER BY 1') as $s) {
            $w(['ddl' => 'CREATE SEQUENCE ' . $this->quoteIdent((string) $s['SEQUENCE_NAME']) . ' MINVALUE ' . $s['MIN_VALUE'] . ' MAXVALUE ' . $s['MAX_VALUE']
                . ' INCREMENT BY ' . $s['INCREMENT_BY'] . ' START WITH ' . $s['LAST_NUMBER'] . ((int) $s['CACHE_SIZE'] > 0 ? ' CACHE ' . $s['CACHE_SIZE'] : ' NOCACHE')
                . ($s['CYCLE_FLAG'] === 'Y' ? ' CYCLE' : ' NOCYCLE')]);
        }
        foreach ($this->tables() as $t) {
            foreach ($this->ddl('TABLE', $t) as $d) {
                $w(['ddl' => $d]);
            }
            $types = $this->types($t);
            $w(['table' => $t, 'columns' => $types]);
            $n = 0;
            foreach ($this->rows('SELECT * FROM ' . $this->quoteIdent($t)) as $r) {
                $w(['t' => $t, 'r' => $this->rowValues($r, $types)]);
                $n++;
            }
            $counts[$t] = $n;
            $own = [];
            foreach ($this->rows('SELECT index_name FROM user_indexes WHERE table_name = :t AND index_name NOT IN (SELECT index_name FROM user_constraints WHERE table_name = :t AND index_name IS NOT NULL) AND generated = \'N\'', [':t' => $t]) as $i) {
                $own[] = (string) $i['INDEX_NAME'];
            }
            foreach ($own as $i) {
                foreach ($this->ddl('INDEX', $i) as $d) {
                    $late[] = $d;
                }
            }
            foreach ($this->rows('SELECT trigger_name FROM user_triggers WHERE table_name = :t', [':t' => $t]) as $tr) {
                foreach ($this->ddl('TRIGGER', (string) $tr['TRIGGER_NAME']) as $d) {
                    $late[] = $d;
                }
            }
        }
        foreach ($late as $d) {
            $w(['ddl' => $d]);
        }
        $w(['end' => true, 'tables' => count($counts), 'rows' => array_sum($counts)]);
        gzclose($gz);
        return ['engine' => 'oracle', 'tables' => $counts, 'statements' => 0, 'bytes' => (int) filesize($file), 'sha256' => (string) hash_file('sha256', $file)];
    }

    private static function lines(string $file, callable $fn): void
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            throw new XneException("cannot read $file");
        }
        try {
            $buf = '';
            while (!gzeof($gz)) {
                $buf .= (string) gzgets($gz, 16777216);
                if (substr($buf, -1) !== "\n" && !gzeof($gz)) {
                    continue;
                }
                $j = json_decode($buf, true);
                $buf = '';
                if (!is_array($j)) {
                    throw new XneException("$file has a damaged line");
                }
                $fn($j);
            }
        } finally {
            gzclose($gz);
        }
    }

    /** @param array{tables: array<string,int>, sha256: string} $meta */
    public static function verifyDump(string $file, array $meta): void
    {
        if (!is_file($file) || hash_file('sha256', $file) !== $meta['sha256']) {
            throw new XneException("the dump $file is missing or its checksum differs");
        }
        $counts = [];
        $end = false;
        self::lines($file, static function (array $l) use (&$counts, &$end): void {
            if (isset($l['t'])) {
                $counts[$l['t']] = ($counts[$l['t']] ?? 0) + 1;
            } elseif (!empty($l['end'])) {
                $end = true;
            }
        });
        if (!$end) {
            throw new XneException("the dump $file has no end mark (cut short)");
        }
        foreach ($meta['tables'] as $t => $n) {
            if (($counts[$t] ?? 0) !== (int) $n) {
                throw new XneException("the dump $file holds " . ($counts[$t] ?? 0) . " rows of $t, $n expected");
            }
        }
    }

    public function restore(string $file): void
    {
        try {
            $this->dropAll();
            $cols = [];
            $insert = null;
            self::lines($file, function (array $l) use (&$cols, &$insert): void {
                // the DR$ tables of an Oracle Text index are made by the index itself (a dump of xne 0.3.0 kept them)
                if (isset($l['ddl'])) {
                    if (!preg_match('/^\s*CREATE TABLE "DR\$/', (string) $l['ddl'])) {
                        $this->statement((string) $l['ddl']);
                    }
                } elseif (isset($l['table']) && strncmp((string) $l['table'], 'DR$', 3) === 0) {
                    $insert = null;
                } elseif (isset($l['table'])) {
                    $cols = (array) $l['columns'];
                    $names = [];
                    $ph = [];
                    $i = 0;
                    foreach ($cols as $c => $type) {
                        $names[] = $this->quoteIdent((string) $c);
                        $ph[] = ':b' . $i++;
                    }
                    $insert = 'INSERT INTO ' . $this->quoteIdent((string) $l['table']) . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $ph) . ')';
                } elseif (isset($l['t'])) {
                    if ($insert !== null) {
                        $this->insertRow((string) $insert, array_values($cols), (array) $l['r']);
                    }
                }
            });
            oci_commit($this->conn());
        } catch (XneException $e) {
            throw new XneException('the restore of the Oracle schema failed: ' . $e->getMessage());
        }
    }

    /** @param list<string> $types @param list<string|null> $vals */
    private function insertRow(string $sql, array $types, array $vals): void
    {
        $st = oci_parse($this->conn(), $sql);
        $hold = [];
        foreach ($vals as $i => $v) {
            $type = $types[$i];
            if (($type === 'CLOB' || $type === 'NCLOB' || $type === 'BLOB') && $v !== null) {
                $lob = oci_new_descriptor($this->conn(), OCI_D_LOB);
                $lob->writeTemporary($type === 'BLOB' ? (string) base64_decode($v) : $v, $type === 'BLOB' ? OCI_TEMP_BLOB : OCI_TEMP_CLOB);
                $hold[$i] = $lob;
                oci_bind_by_name($st, ':b' . $i, $hold[$i], -1, $type === 'BLOB' ? OCI_B_BLOB : OCI_B_CLOB);
                continue;
            }
            if (($type === 'RAW' || $type === 'LONG RAW') && $v !== null) {
                $v = (string) base64_decode($v);
            }
            $hold[$i] = $v;
            oci_bind_by_name($st, ':b' . $i, $hold[$i], $v === null ? -1 : max(1, strlen($v)), ($type === 'RAW' || $type === 'LONG RAW') ? SQLT_BIN : SQLT_CHR);
        }
        if (!@oci_execute($st, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($st);
            throw new XneException('Oracle insert: ' . $this->clean((string) ($e['message'] ?? 'error')));
        }
        foreach ($hold as $h) {
            if ($h instanceof \OCILob) {
                $h->free();
            }
        }
    }

    public function runSqlFile(string $file): int
    {
        throw new XneException('the Oracle schema is upgraded by the kernel\'s exp:upgrade (through ezoracle), not by SQL files in xne');
    }
}
