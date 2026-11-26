<?php
declare(strict_types=1);

// MongoDB: the driver's dump, verify, restore and fingerprint against the real replica set rs0 (on the node that runs
// the test), in the throwaway database xne_test_mongo with the least-privilege user xne_tester. Needs XNE_IT_MONGO_TEST (an
// env file with MONGO_TEST_USER / MONGO_TEST_PASS / MONGO_TEST_DB, 0600) and the mongodb extension. Never touches another database.

/**
 * The least-privilege test user xne_tester (dbOwner of the database xne_test_mongo only) from XNE_IT_MONGO_TEST, a 0600
 * env file (MONGO_TEST_USER, MONGO_TEST_PASS, MONGO_TEST_DB). The test empties that database first and drops it last.
 * @return array{0: \MongoDB\Driver\Manager, 1: string, 2: array<string,string>} manager, database, driver settings
 */
function t_mongo_fixture(): array
{
    $envFile = (string) getenv('XNE_IT_MONGO_TEST');
    if ($envFile === '' || !is_file($envFile) || !extension_loaded('mongodb')) {
        throw new TestFailure('XNE_IT_MONGO_TEST (the xne_tester env file) and the mongodb extension are needed');
    }
    $e = \Xne\Config::parseConf((string) file_get_contents($envFile));
    $db = (string) $e['MONGO_TEST_DB'];
    if (strncmp($db, 'xne_test_', 9) !== 0) {
        throw new TestFailure('refused: not a throwaway database');
    }
    // the replica set's primary takes the writes (the local member may be a secondary); hello needs no login
    $seed = (string) (getenv('XNE_IT_MONGO_SEED') ?: '127.0.0.1:27017');
    $primary = (string) (new \MongoDB\Driver\Manager('mongodb://' . $seed . '/?directConnection=true'))->executeCommand('admin', new \MongoDB\Driver\Command(['hello' => 1]))->toArray()[0]->primary;
    [$host, $port] = explode(':', $primary);
    $s = ['implementation' => 'expmongodb', 'engine' => 'mongodb', 'server' => $host, 'port' => $port, 'user' => (string) $e['MONGO_TEST_USER'], 'password' => (string) $e['MONGO_TEST_PASS'], 'database' => $db];
    $m = new \MongoDB\Driver\Manager('mongodb://' . rawurlencode($s['user']) . ':' . rawurlencode($s['password']) . '@' . $primary . '/' . $db);
    $m->executeCommand($db, new \MongoDB\Driver\Command(['dropDatabase' => 1]));
    return [$m, $db, $s];
}

return [
    'mongodb: dump, verify, restore exactly (types, indexes, later collections dropped) on rs0, throwaway database' => static function (): void {
        [$admin, $db, $s] = t_mongo_fixture();
        $d = new \Xne\Db\Mongo($s);
        $b = new \MongoDB\Driver\BulkWrite();
        $b->insert(['_id' => 1, 'name' => 'ezpublish-version', 'value' => '6.0.15stable']);
        $admin->executeBulkWrite($db . '.ezsite_data', $b);
        $b = new \MongoDB\Driver\BulkWrite();
        for ($i = 1; $i <= 1200; $i++) {
            $b->insert(['_id' => $i, 'n' => $i, 'f' => $i / 3, 'big' => new \MongoDB\BSON\Int64(PHP_INT_MAX - $i), 'bin' => new \MongoDB\BSON\Binary(random_bytes(16), 0),
                'when' => new \MongoDB\BSON\UTCDateTime(1700000000000 + $i), 'tags' => ['a', 'b' . $i], 'nul' => null, 'obj' => ['k' => "Ü'\"\n$i"]]);
        }
        $admin->executeBulkWrite($db . '.ezcontentobject', $b);
        $admin->executeCommand($db, new \MongoDB\Driver\Command(['createIndexes' => 'ezcontentobject', 'indexes' => [['key' => ['n' => -1, 'tags' => 1], 'name' => 'n_tags', 'unique' => false]]]));
        t_same('6.0.15stable', $d->siteVersion());
        $before = $d->fingerprint();
        $dir = Fixture::dir() . '/state/mongo-' . Fixture::run();
        $meta = $d->dump($dir . '/db.mongo.jsonl.gz');
        \Xne\Db\Mongo::verifyDump($dir . '/db.mongo.jsonl.gz', $meta);
        t_same(['ezcontentobject' => 1200, 'ezsite_data' => 1], $meta['tables']);
        $b = new \MongoDB\Driver\BulkWrite();
        $b->delete(['_id' => ['$lte' => 100]]);
        $b->update(['_id' => 500], ['$set' => ['n' => -1]]);
        $admin->executeBulkWrite($db . '.ezcontentobject', $b);
        $b = new \MongoDB\Driver\BulkWrite();
        $b->insert(['x' => 1]);
        $admin->executeBulkWrite($db . '.expupgrade_later', $b);
        t_true($before !== $d->fingerprint());
        $d->restore($dir . '/db.mongo.jsonl.gz');
        t_same($before, (new \Xne\Db\Mongo($s))->fingerprint(), 'documents, types and indexes exact; the later collection gone');
        t_throws(\Xne\XneException::class, static function () use ($dir, $meta): void {
            $m = $meta;
            $m['tables']['ezsite_data'] = 2;
            \Xne\Db\Mongo::verifyDump($dir . '/db.mongo.jsonl.gz', $m);
        }, 'a wrong count fails the verification');
        $admin->executeCommand($db, new \MongoDB\Driver\Command(['dropDatabase' => 1]));
    },
];
