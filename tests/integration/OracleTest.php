<?php
declare(strict_types=1);

// Oracle: the driver's logical export, verify, restore and fingerprint against the node's Oracle Free (pod
// oracle-free, 127.0.0.1:1521, PDB FREEPDB1), in a throwaway schema XNE_TEST_<run> made for the test and dropped after
// it. Needs XNE_IT_ORACLE_ENV (the 0600 env file with ORACLE_PWD of SYSTEM) and oci8. Never touches another schema.

return [
    'oracle: logical export, verify, exact restore (CLOB over 4000, BLOB, RAW, dates, sequence, trigger, index) in a throwaway schema' => static function (): void {
        $envFile = (string) getenv('XNE_IT_ORACLE_ENV');
        if ($envFile === '' || !is_file($envFile) || !function_exists('oci_connect')) {
            throw new TestFailure('XNE_IT_ORACLE_ENV and oci8 are needed');
        }
        $sysPw = \Xne\Config::parseConf((string) file_get_contents($envFile))['ORACLE_PWD'] ?? '';
        $cs = (string) (getenv('XNE_IT_ORACLE_CS') ?: '//127.0.0.1:1521/FREEPDB1');
        $user = strtoupper('xne_test_' . preg_replace('/\W/', '', Fixture::run()));
        if (strncmp($user, 'XNE_TEST_', 9) !== 0) {
            throw new TestFailure('refused');
        }
        $pw = 'X' . bin2hex(random_bytes(10));
        $sys = new \Xne\Db\Oracle(['engine' => 'oracle', 'user' => 'SYSTEM', 'password' => $sysPw, 'database' => $cs]);
        try {
            $sys->statement("DROP USER $user CASCADE");
        } catch (\Throwable $e) {
        }
        $sys->statement("CREATE USER $user IDENTIFIED BY \"$pw\" QUOTA UNLIMITED ON USERS");
        $sys->statement("GRANT CREATE SESSION, CREATE TABLE, CREATE SEQUENCE, CREATE TRIGGER TO $user");
        try {
            $s = ['engine' => 'oracle', 'implementation' => 'ezoracle', 'user' => $user, 'password' => $pw, 'database' => $cs];
            $d = new \Xne\Db\Oracle($s);
            $d->statement('CREATE SEQUENCE s_ezcontentobject START WITH 1');
            $d->statement('CREATE TABLE ezcontentobject (id NUMBER(11) PRIMARY KEY, name VARCHAR2(255), body CLOB, pic BLOB, tag RAW(16), published DATE, modified TIMESTAMP, price NUMBER(12,4))');
            $d->statement('CREATE INDEX ezcontentobject_name ON ezcontentobject (name)');
            $d->statement("CREATE OR REPLACE TRIGGER ezcontentobject_tr BEFORE INSERT ON ezcontentobject FOR EACH ROW WHEN (new.id IS NULL) BEGIN SELECT s_ezcontentobject.NEXTVAL INTO :new.id FROM dual; END;");
            $d->statement('CREATE TABLE ezsite_data (name VARCHAR2(60) PRIMARY KEY, value VARCHAR2(255))');
            $d->statement("INSERT INTO ezsite_data VALUES ('ezpublish-version', '6.0.15stable')");
            for ($i = 1; $i <= 30; $i++) {
                $big = str_repeat("Ü'\"; line $i\n", 600);
                $d->statement("INSERT INTO ezcontentobject (name, body, pic, tag, published, modified, price) VALUES (:n, :b, :p, HEXTORAW(:t), DATE '2026-01-01' + :i, TIMESTAMP '2026-01-01 10:00:00.123456' + :i, :pr)",
                    [':n' => "item $i", ':b' => $big, ':p' => bin2hex(random_bytes(8)), ':t' => bin2hex(random_bytes(16)), ':i' => (string) $i, ':pr' => (string) ($i / 7)]);
            }
            t_same('6.0.15stable', $d->siteVersion());
            $before = $d->fingerprint();
            $dir = Fixture::dir() . '/state/oracle-' . Fixture::run();
            $meta = $d->dump($dir . '/db.oracle.jsonl.gz');
            \Xne\Db\Oracle::verifyDump($dir . '/db.oracle.jsonl.gz', $meta);
            t_same(['EZCONTENTOBJECT' => 30, 'EZSITE_DATA' => 1], $meta['tables']);
            $d->statement('DELETE FROM ezcontentobject WHERE id <= 10');
            $d->statement("UPDATE ezsite_data SET value = 'broken'");
            $d->statement("INSERT INTO ezcontentobject (name) VALUES ('after the backup')");
            $d->statement('CREATE TABLE expupgrade_later (x NUMBER)');
            t_true($before !== $d->fingerprint());
            $d->restore($dir . '/db.oracle.jsonl.gz');
            $a = new \Xne\Db\Oracle($s);
            $after = $a->fingerprint();
            $a->close();
            t_same($before, $after, 'rows, LOBs and the sequence position exact; the later table gone');
            $r = new \Xne\Db\Oracle($s);
            $r->statement("INSERT INTO ezcontentobject (name) VALUES ('new after restore')");
            t_true((int) $r->rows("SELECT MAX(id) AS M FROM ezcontentobject")[0]['M'] > 30, 'the trigger and the sequence go on after the restore, never reusing an id');
            $r->close();
            $d->close();
        } finally {
            try {
                $sys->statement("DROP USER $user CASCADE");
            } catch (\Throwable $e) {
                echo "      (the throwaway schema $user stays until the next run: " . substr($e->getMessage(), 0, 80) . ")\n";
            }
        }
    },
];
