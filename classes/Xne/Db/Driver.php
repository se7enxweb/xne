<?php
declare(strict_types=1);

namespace Xne\Db;

use Xne\XneException;

/**
 * One database of an install, reached with the install's own settings ([DatabaseSettings] of site.ini) through PDO.
 * A dump is xne's own framed SQL file (gzip): every statement is preceded by a line "-- xne-stmt <bytes>", so the
 * restore reads statements back exactly, whatever their content; the counts of rows per table are written into the
 * dump and into its meta file, and a dump is verified by reading it to its end mark and recounting.
 */
abstract class Driver
{
    /** @var \PDO|null */
    protected $pdo;
    /** @var array<string,string> */
    protected $s;

    /** @param array<string,string> $settings implementation, server, port, user, password, database, socket, file */
    public function __construct(array $settings)
    {
        $this->s = $settings;
    }

    abstract public function engine(): string;

    abstract protected function connectPdo(): \PDO;

    /** @return list<string> the base tables of the schema */
    abstract public function tables(): array;

    /** Writes the DDL + data statements through $emit(string $sql, ?string $table, int $rows). */
    abstract protected function dumpInto(callable $emit): void;

    /** Drops every table, view and sequence of the schema (before a restore). */
    abstract protected function dropAll(): void;

    abstract public function quoteIdent(string $name): string;

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            if (!in_array($this->pdoDriverName(), \PDO::getAvailableDrivers(), true)) {
                throw new XneException('this PHP (' . PHP_BINARY . ') has no PDO driver "' . $this->pdoDriverName() . '" for the ' . $this->engine() . ' database; run xne with a PHP that has it');
            }
            try {
                $this->pdo = $this->connectPdo();
            } catch (\PDOException $e) {
                throw new XneException('cannot connect to the ' . $this->engine() . ' database "' . ($this->s['database'] ?? '') . '": ' . $this->clean($e->getMessage()));
            }
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $this->pdo;
    }

    abstract protected function pdoDriverName(): string;

    /** Error text without the password, should a driver ever echo it. */
    protected function clean(string $msg): string
    {
        $pw = (string) ($this->s['password'] ?? '');
        return $pw !== '' ? str_replace($pw, '***', $msg) : $msg;
    }

    /** What may be shown: never the password. @return array<string,string> */
    public function describe(): array
    {
        $d = ['engine' => $this->engine()];
        foreach (['implementation', 'server', 'port', 'database', 'user', 'socket', 'file'] as $k) {
            if (($this->s[$k] ?? '') !== '') {
                $d[$k] = (string) $this->s[$k];
            }
        }
        return $d;
    }

    /** ezsite_data ezpublish-version, or null. */
    public function siteVersion(): ?string
    {
        try {
            $st = $this->pdo()->query("SELECT value FROM ezsite_data WHERE name = 'ezpublish-version'");
            $v = $st ? $st->fetchColumn() : false;
            return $v === false ? null : (string) $v;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /** @return array<string,int> table => rows */
    public function counts(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $out[$t] = (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $this->quoteIdent($t))->fetchColumn();
        }
        return $out;
    }

    /**
     * A content fingerprint: per table the row count and the md5 of every row in a stable order, plus the list of
     * tables. Two equal fingerprints mean equal data. Used by tests and by "xne db-fingerprint".
     * @return array<string, array{rows: int, md5: string}>
     */
    public function fingerprint(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $cols = $this->columns($t);
            $st = $this->pdo()->query($this->orderedSelect($t, $cols));
            $ctx = hash_init('md5');
            $n = 0;
            while (($row = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                $n++;
                foreach ($row as $v) {
                    hash_update($ctx, $this->norm($v) . "\x1f");
                }
                hash_update($ctx, "\x1e");
            }
            $out[$t] = ['rows' => $n, 'md5' => hash_final($ctx)];
        }
        ksort($out);
        return $out;
    }


    /** SELECT * of a table in a stable order (every column, left to right). @param list<string> $cols */
    protected function orderedSelect(string $table, array $cols): string
    {
        $order = implode(', ', array_map([$this, 'quoteIdent'], $cols));
        return 'SELECT * FROM ' . $this->quoteIdent($table) . ($order !== '' ? ' ORDER BY ' . $order : '');
    }
    /** @return list<string> */
    abstract public function columns(string $table): array;

    /** @param mixed $v */
    protected function norm($v): string
    {
        if ($v === null) {
            return "\0NULL";
        }
        if (is_resource($v)) {
            return (string) stream_get_contents($v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return is_float($v) ? var_export($v, true) : (string) $v;
    }

    /** @param mixed $v */
    public function literal($v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? var_export($v, true) : $this->pdo()->quote((string) $v);
        }
        if (is_resource($v)) {
            $v = (string) stream_get_contents($v);
        }
        return $this->pdo()->quote((string) $v);
    }

    /**
     * Dumps the database into $file (gzip). @return array{tables: array<string,int>, statements: int, bytes: int,
     * sha256: string, engine: string}
     */
    public function dump(string $file): array
    {
        \Xne\Fs::mkdir(dirname($file), 0700);
        $gz = @gzopen($file, 'wb6');
        if ($gz === false) {
            throw new XneException("cannot write $file");
        }
        $counts = [];
        $stmts = 0;
        gzwrite($gz, '-- xne dump: engine ' . $this->engine() . ', database ' . ($this->s['database'] ?? '') . ', ' . gmdate('c') . "\n");
        $emit = static function (string $sql, ?string $table = null, int $rows = 0) use ($gz, &$counts, &$stmts): void {
            if ($table !== null) {
                $counts[$table] = ($counts[$table] ?? 0) + $rows;
                gzwrite($gz, "-- xne-rows $rows " . $table . "\n");
            }
            gzwrite($gz, '-- xne-stmt ' . strlen($sql) . "\n" . $sql . "\n");
            $stmts++;
        };
        $this->dumpInto($emit);
        foreach ($this->tables() as $t) {
            if (!isset($counts[$t])) {
                $counts[$t] = 0;
            }
        }
        ksort($counts);
        gzwrite($gz, '-- xne-dump-end tables=' . count($counts) . ' rows=' . array_sum($counts) . ' statements=' . $stmts . "\n");
        gzclose($gz);
        return ['engine' => $this->engine(), 'tables' => $counts, 'statements' => $stmts, 'bytes' => (int) filesize($file), 'sha256' => (string) hash_file('sha256', $file)];
    }

    /**
     * Reads a dump to its end mark and recounts the rows per table. Throws when it is damaged or differs from $meta.
     * @param array{tables: array<string,int>, statements: int, sha256: string} $meta
     */
    public static function verifyDump(string $file, array $meta): void
    {
        if (!is_file($file) || hash_file('sha256', $file) !== $meta['sha256']) {
            throw new XneException("the dump $file is missing or its checksum differs");
        }
        $counts = [];
        $stmts = 0;
        $end = false;
        self::readFramed($file, static function (string $sql) use (&$stmts): void {
            $stmts++;
        }, static function (string $line) use (&$counts, &$end): void {
            if (preg_match('/^-- xne-rows (\d+) (.+)$/', $line, $m)) {
                $counts[$m[2]] = ($counts[$m[2]] ?? 0) + (int) $m[1];
            } elseif (strncmp($line, '-- xne-dump-end', 15) === 0) {
                $end = true;
            }
        });
        if (!$end) {
            throw new XneException("the dump $file has no end mark (cut short)");
        }
        if ($stmts !== $meta['statements']) {
            throw new XneException("the dump $file has $stmts statements, $meta[statements] expected");
        }
        foreach ($meta['tables'] as $t => $n) {
            if ($n > 0 && ($counts[$t] ?? 0) !== $n) {
                throw new XneException("the dump $file holds " . ($counts[$t] ?? 0) . " rows of $t, $n expected");
            }
        }
    }

    /** Streams the statements of a framed dump to $onStmt, other lines to $onLine. */
    public static function readFramed(string $file, callable $onStmt, ?callable $onLine = null): void
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            throw new XneException("cannot read $file");
        }
        try {
            while (($line = gzgets($gz, 1048576)) !== false) {
                $line = rtrim($line, "\n");
                if (strncmp($line, '-- xne-stmt ', 12) === 0) {
                    $len = (int) substr($line, 12);
                    $sql = $len > 0 ? (string) \Xne\Tar::readExact($gz, $len) : '';
                    if (strlen($sql) !== $len) {
                        throw new XneException("$file is cut short inside a statement");
                    }
                    gzgetc($gz);
                    $onStmt($sql);
                } elseif ($onLine !== null) {
                    $onLine($line);
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    /** Puts the database back to the dump: every table dropped first (also the ones made after the dump). */
    public function restore(string $file): void
    {
        $pdo = $this->pdo();
        $this->beforeRestore();
        try {
            $this->dropAll();
            self::readFramed($file, static function (string $sql) use ($pdo): void {
                $pdo->exec($sql);
            });
            $this->afterRestore(true);
        } catch (\Throwable $e) {
            $this->afterRestore(false);
            throw new XneException('the restore of the ' . $this->engine() . ' database failed: ' . $this->clean($e->getMessage()));
        }
    }

    protected function beforeRestore(): void
    {
    }

    protected function afterRestore(bool $ok): void
    {
    }

    /**
     * Runs a kernel SQL update file (update/database/<engine>/...): statements split on ";" at a line end, outside
     * quotes and comments. Stops at the first failing statement (throws).
     */
    public function runSqlFile(string $file): int
    {
        $n = 0;
        foreach (self::splitSql((string) file_get_contents($file), $this->engine() === 'mysql') as $sql) {
            try {
                $this->pdo()->exec($sql);
            } catch (\PDOException $e) {
                throw new XneException(basename($file) . ': statement ' . ($n + 1) . ' failed: ' . $this->clean($e->getMessage()));
            }
            $n++;
        }
        return $n;
    }

    /** @return list<string> */
    public static function splitSql(string $text, bool $backslashEscapes = false): array
    {
        $out = [];
        $buf = '';
        $len = strlen($text);
        $q = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($q === null) {
                if ($c === '-' && ($text[$i + 1] ?? '') === '-') {
                    $e = strpos($text, "\n", $i);
                    $i = $e === false ? $len : $e;
                    $buf .= "\n";
                    continue;
                }
                if ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                    $e = strpos($text, '*/', $i + 2);
                    $i = $e === false ? $len : $e + 1;
                    continue;
                }
                if ($c === '#' && ($i === 0 || $text[$i - 1] === "\n")) {
                    $e = strpos($text, "\n", $i);
                    $i = $e === false ? $len : $e;
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $q = $c;
                } elseif ($c === '$' && preg_match('/\G\$([A-Za-z_]*)\$/', $text, $m, 0, $i)) {
                    $tag = $m[0];
                    $e = strpos($text, $tag, $i + strlen($tag));
                    $e = $e === false ? $len : $e + strlen($tag);
                    $buf .= substr($text, $i, $e - $i);
                    $i = $e - 1;
                    continue;
                } elseif ($c === ';') {
                    if (trim($buf) !== '') {
                        $out[] = trim($buf);
                    }
                    $buf = '';
                    continue;
                }
            } elseif ($c === $q) {
                if (($text[$i + 1] ?? '') === $q) {
                    $buf .= $c . $c;
                    $i++;
                    continue;
                }
                $q = null;
            } elseif ($backslashEscapes && $c === '\\' && $q !== '`') {
                $buf .= $c . ($text[$i + 1] ?? '');
                $i++;
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
