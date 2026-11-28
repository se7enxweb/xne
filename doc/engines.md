# Database engines

Last verified: 2026-11-24 (xne 0.3.0.1)

**Proven on every engine.** MySQL/MariaDB, PostgreSQL and SQLite: 6.0.13 -> 6.0.14 and 6.0.14 -> 6.0.15 by file patch
and by git, with exact rollbacks (the integration suite). MongoDB and Oracle (2026-11-24): an exact backup and restore
(the integration tests), and a full upgrade of a kickstarted install (kernel main~20 to main: 64 files written, the
kernel's exp:upgrade applied 3 steps on MongoDB and 5 on Oracle, health check passed), then `xne rollback`: every file
outside var/ and every collection (155) or table (264) equal to before. The Oracle run found and fixed two faults
(Oracle Text DR$ tables in the dump; the ezoracle alias in the trap).

**Kernel libraries stage (closed in 0.3.0.1).** A later Oracle proof stopped and rolled back: the kernel reported its
`libraries` stage pending although `composer.lock` was unchanged (vendor/ not what the lock names, as on a kickstarted
install), and exp:upgrade refuses the database stage while an earlier stage has pending steps. xne used to add the
libraries stage only when the target's `composer.lock` differed. Since 0.3.0.1 the plan also asks the install's
`exp:upgrade:status --stages=libraries`, and when it is pending runs `libraries, database, scripts` in that order, with
vendor/, composer.json, composer.lock and the Composer extension folders in the verified backup and the same automatic
rollback; when only the new kernel reports it after the files landed, xne stops and rolls back rather than run it
without a backup. Proven by `LibrariesTest` (a fake kernel with the kernel's stage order rule: plan, upgrade,
`xne rollback` and an automatic rollback restore files, vendor/ and database exactly; all 46 unit tests pass). Not yet
rerun on a real Oracle install: the throwaway install from that proof no longer exists on this node.

**Kernel code stage after a file patch (closed in 0.3.0.11).** doc.exponential.earth on cluster, 2026-11-27: a file
patch of main into an install that is a git checkout (with local commits, so the git method is refused) wrote 5695
files, then `exp:upgrade --stages=libraries,database,scripts` refused: "the stage code has 1 pending step(s)". A file
patch leaves the checkout's commits as they were, so the kernel's code step (`6.0.15-code-update`, which moves the
checkout) stays pending, and exp:upgrade refuses a later stage while an earlier one has pending steps; xne rolled back
cleanly. The kernel now has `exp:upgrade --code-applied=<version or commit>` (the code stage counts as in place,
nothing else is skipped); since 0.3.0.11 xne passes it after every file patch when the target's kernel knows it, and
on an older kernel passes `--force-order` only when `exp:upgrade:status` shows the code step as the one pending step
before the run. Proven by `CodeStageTest` (a fake kernel with the order rule: both kernels, another pending step
refused and rolled back, exact rollback).

**Sites over ODBC (kernel 6.0.15, `DatabaseImplementation=odbc`).** ODBC is only the site's transport: xne reads
`[ODBCSettings] Dialect` and treats the install as that engine (`postgresql`, `mysql`), backing it up and rolling it
back through the engine's own PDO driver against the same server (PHP running xne needs `pdo_pgsql` or `pdo_mysql`).
`sqlserver`, `db2` and `msaccess` over ODBC are refused as their native drivers are (no verified xne backup yet).
Unit test: `DbTest` (a site over ODBC is its dialect's engine). Not yet proven by an integration upgrade.

xne backs up, upgrades and rolls back the database of an Exponential install with the install's own
`[DatabaseSettings]` (`settings/site.ini`, the siteaccess, `settings/override`; the password is never shown). Every
backup is verified before anything changes, and a rollback is exact: the tests compare a fingerprint of every table
or collection (and sequence) before the backup and after the restore.

| Engine | DatabaseImplementation | PHP extension xne needs | Backup (`<backup>/...`) | Upgrade steps | Rollback |
|---|---|---|---|---|---|
| MySQL / MariaDB | `ezmysqli`, `ezmysql` | pdo_mysql | `db.sql.gz`: xne's framed SQL dump (DDL from SHOW CREATE TABLE, rows counted) | the kernel's exp:upgrade, else `update/database/mysql/` SQL files | every table dropped and recreated from the dump |
| PostgreSQL | `ezpostgresql` | pdo_pgsql | `db.sql.gz`: DDL from the catalog, sequences at their positions, rows | exp:upgrade, else `update/database/postgresql/` | in one transaction |
| SQLite | `sqlite3`, `ezsqlite3` | pdo_sqlite (+ sqlite3) | `db.sqlite`: a consistent copy, integrity-checked | exp:upgrade, else `update/database/sqlite/` | through SQLite's backup API |
| MongoDB | `expmongodb` | mongodb | `db.mongo.jsonl.gz`: indexes and documents as canonical extended JSON (every BSON type exact) | the kernel's exp:upgrade only (6.0.15 and newer) | collections dropped, indexes and documents recreated |
| Oracle | `ezoracle` | oci8 | `db.oracle.jsonl.gz`: DDL from DBMS_METADATA (tables, sequences at their positions, indexes, triggers), rows with CLOB/BLOB whole; no expdp, RMAN or server directory | the kernel's exp:upgrade (through ezoracle) only | tables purged, recreated, rows bound back, then indexes and triggers |

When xne's own PHP lacks the extension, name one that has it: `xne upgrade --root=... --php=/usr/bin/php`
(xne then runs itself under that PHP). An install whose siteaccesses use more than one database is refused
(`several-databases`).

## Examples

```bash
xne detect  --root=/var/www/vhosts/example.com/doc/www.example.com      # the database line names the engine
xne backup  --root=... --php=/usr/bin/php                 # a verified backup now (any engine)
xne upgrade --root=... --dry-run
xne upgrade --root=...
xne rollback --root=...
```
