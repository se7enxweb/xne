<?php
declare(strict_types=1);

namespace Xne\Db;

use Xne\XneException;

/**
 * MongoDB (DatabaseImplementation expmongodb / mongodb), through the PHP mongodb extension and the install's own
 * settings (the URI the kernel builds: mongodb://user:password@server:port/database). The backup is a logical
 * export in pure PHP: every collection's indexes and documents as canonical extended JSON, one per line (gzip), which
 * keeps every BSON type exactly; counts per collection are written into it and into the meta, and a dump is verified
 * by reading it to its end mark and recounting. The restore drops the database's collections (also the ones made
 * after the backup), recreates the indexes and inserts the documents in batches.
 */
final class Mongo extends Driver
{
    /** @var \MongoDB\Driver\Manager|null */
    private $m;

    public function engine(): string
    {
        return 'mongodb';
    }

    protected function pdoDriverName(): string
    {
        return 'mongodb';
    }

    protected function connectPdo(): \PDO
    {
        throw new XneException('MongoDB has no PDO driver');
    }

    public function quoteIdent(string $name): string
    {
        return $name;
    }

    public function db(): string
    {
        return (string) $this->s['database'];
    }

    public function manager(): \MongoDB\Driver\Manager
    {
        if ($this->m === null) {
            if (!extension_loaded('mongodb')) {
                throw new XneException('this PHP (' . PHP_BINARY . ') has no mongodb extension for the MongoDB database; run xne with a PHP that has it (--php)');
            }
            $user = (string) ($this->s['user'] ?? '');
            $cred = $user === '' ? '' : rawurlencode($user) . (($this->s['password'] ?? '') !== '' ? ':' . rawurlencode((string) $this->s['password']) : '') . '@';
            $host = ($this->s['server'] ?? '') !== '' ? $this->s['server'] : 'localhost';
            $port = ($this->s['port'] ?? '') !== '' ? $this->s['port'] : '27017';
            try {
                $this->m = new \MongoDB\Driver\Manager('mongodb://' . $cred . $host . ':' . $port . '/' . $this->db());
                $this->command(['ping' => 1]);
            } catch (\Throwable $e) {
                $this->m = null;
                throw new XneException('cannot connect to the MongoDB database "' . $this->db() . '": ' . $this->clean($e->getMessage()));
            }
        }
        return $this->m;
    }

    /** @return list<object> */
    private function command(array $cmd): array
    {
        return $this->manager()->executeCommand($this->db(), new \MongoDB\Driver\Command($cmd))->toArray();
    }


    /** The documents of a command that answers with a cursor (listCollections, listIndexes): the driver iterates it. @return list<object> */
    private function cursorDocs(array $cmd): array
    {
        $out = [];
        foreach ($this->manager()->executeCommand($this->db(), new \MongoDB\Driver\Command($cmd)) as $doc) {
            if (isset($doc->cursor->firstBatch)) {
                foreach ($doc->cursor->firstBatch as $d) {
                    $out[] = $d;
                }
            } else {
                $out[] = $doc;
            }
        }
        return $out;
    }
    public function tables(): array
    {
        $out = [];
        foreach ($this->cursorDocs(['listCollections' => 1, 'nameOnly' => true, 'filter' => ['type' => 'collection']]) as $c) {
            if (strncmp((string) $c->name, 'system.', 7) !== 0) {
                $out[] = (string) $c->name;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public function columns(string $table): array
    {
        return [];
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $out[$t] = (int) ($this->command(['count' => $t])[0]->n ?? 0);
        }
        return $out;
    }

    /** @return iterable<object> */
    private function docs(string $coll): iterable
    {
        return $this->manager()->executeQuery($this->db() . '.' . $coll, new \MongoDB\Driver\Query([], ['sort' => ['_id' => 1]]));
    }

    public function siteVersion(): ?string
    {
        try {
            foreach ($this->manager()->executeQuery($this->db() . '.ezsite_data', new \MongoDB\Driver\Query(['name' => 'ezpublish-version'], ['limit' => 1])) as $d) {
                return (string) $d->value;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    public function fingerprint(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            $ctx = hash_init('md5');
            $n = 0;
            foreach ($this->docs($t) as $d) {
                hash_update($ctx, \MongoDB\BSON\Document::fromPHP($d)->toCanonicalExtendedJSON() . "\n");
                $n++;
            }
            $idx = [];
            foreach ($this->indexes($t) as $i) {
                $idx[] = \MongoDB\BSON\Document::fromPHP($i)->toCanonicalExtendedJSON();
            }
            sort($idx);
            hash_update($ctx, implode("\n", $idx));
            $out[$t] = ['rows' => $n, 'md5' => hash_final($ctx)];
        }
        ksort($out);
        return $out;
    }

    /** The index definitions of a collection without _id_ and without the server's own fields. @return list<array> */
    private function indexes(string $coll): array
    {
        $out = [];
        foreach ($this->cursorDocs(['listIndexes' => $coll]) as $i) {
            $a = (array) $i;
            if (($a['name'] ?? '') === '_id_') {
                continue;
            }
            unset($a['v'], $a['ns']);
            $out[] = $a;
        }
        return $out;
    }

    protected function dumpInto(callable $emit): void
    {
        throw new XneException('dump() is overridden');
    }

    protected function dropAll(): void
    {
        foreach ($this->tables() as $t) {
            $this->command(['drop' => $t]);
        }
    }

    public function dump(string $file): array
    {
        \Xne\Fs::mkdir(dirname($file), 0700);
        $gz = @gzopen($file, 'wb6');
        if ($gz === false) {
            throw new XneException("cannot write $file");
        }
        $counts = [];
        gzwrite($gz, json_encode(['xne' => 'mongodb-dump/1', 'database' => $this->db(), 'at' => gmdate('c')]) . "\n");
        foreach ($this->tables() as $t) {
            gzwrite($gz, json_encode(['collection' => $t, 'indexes' => array_map(static function ($i) {
                return json_decode(\MongoDB\BSON\Document::fromPHP($i)->toCanonicalExtendedJSON(), true);
            }, $this->indexes($t))]) . "\n");
            $n = 0;
            foreach ($this->docs($t) as $d) {
                gzwrite($gz, '{"c":' . json_encode($t) . ',"d":' . \MongoDB\BSON\Document::fromPHP($d)->toCanonicalExtendedJSON() . "}\n");
                $n++;
            }
            $counts[$t] = $n;
        }
        gzwrite($gz, json_encode(['end' => true, 'collections' => count($counts), 'documents' => array_sum($counts)]) . "\n");
        gzclose($gz);
        return ['engine' => 'mongodb', 'tables' => $counts, 'statements' => count($counts), 'bytes' => (int) filesize($file), 'sha256' => (string) hash_file('sha256', $file)];
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
            if (isset($l['c'])) {
                $counts[$l['c']] = ($counts[$l['c']] ?? 0) + 1;
            } elseif (!empty($l['end'])) {
                $end = true;
            }
        });
        if (!$end) {
            throw new XneException("the dump $file has no end mark (cut short)");
        }
        foreach ($meta['tables'] as $t => $n) {
            if (($counts[$t] ?? 0) !== (int) $n) {
                throw new XneException("the dump $file holds " . ($counts[$t] ?? 0) . " documents of $t, $n expected");
            }
        }
    }

    private static function lines(string $file, callable $fn): void
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            throw new XneException("cannot read $file");
        }
        try {
            while (($line = gzgets($gz, 67108864)) !== false) {
                $j = json_decode($line, true);
                if (!is_array($j)) {
                    throw new XneException("$file has a damaged line");
                }
                $fn($j, $line);
            }
        } finally {
            gzclose($gz);
        }
    }

    public function restore(string $file): void
    {
        try {
            $this->dropAll();
            $db = $this->db();
            $m = $this->manager();
            $batch = null;
            $coll = '';
            $n = 0;
            $flush = static function () use (&$batch, &$coll, &$n, $m, $db): void {
                if ($batch !== null && $n > 0) {
                    $m->executeBulkWrite($db . '.' . $coll, $batch);
                }
                $batch = null;
                $n = 0;
            };
            self::lines($file, function (array $l, string $raw) use (&$batch, &$coll, &$n, $flush, $db): void {
                if (isset($l['collection'])) {
                    $flush();
                    $coll = (string) $l['collection'];
                    $this->command(['create' => $coll]);
                    if (!empty($l['indexes'])) {
                        $idx = [];
                        foreach ($l['indexes'] as $i) {
                            $idx[] = \MongoDB\BSON\Document::fromJSON(json_encode($i))->toPHP();
                        }
                        $this->command(['createIndexes' => $coll, 'indexes' => $idx]);
                    }
                    return;
                }
                if (!isset($l['c'])) {
                    return;
                }
                if ($batch === null) {
                    $batch = new \MongoDB\Driver\BulkWrite(['ordered' => true]);
                }
                $p = strpos($raw, ',"d":');
                $batch->insert(\MongoDB\BSON\Document::fromJSON(substr(rtrim($raw, "\r\n"), $p + 5, -1)));
                if (++$n >= 500) {
                    $flush();
                }
            });
            $flush();
        } catch (XneException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new XneException('the restore of the MongoDB database failed: ' . $this->clean($e->getMessage()));
        }
    }

    public function runSqlFile(string $file): int
    {
        throw new XneException('MongoDB has no SQL update files: its upgrade steps are the kernel\'s exp:upgrade');
    }
}
