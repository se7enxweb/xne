# Rolling back with xne

Last verified: 2026-11-24 (xne 0.1.0)

There are four ways back, from the automatic one to the manual one. All of them use the backup xne made before
the upgrade; none of them deletes a file.

## The backup

Before any upgrade xne writes `<state_dir>/installs/<install>/backups/<id>/` (mode 0700; `state_dir` is
`/var/lib/xne` as root) and verifies it before anything changes:

| File | Holds |
|---|---|
| `.xne-backup` | the marker; the rotation removes only folders that carry it |
| `backup.json` | versions, target, method, git commit, the file list with the md5 of each file, the database meta (tables and row counts, checksum), the state and the rollback result |
| `files.tar.gz` | the content of every file the upgrade changes or retires, `settings/override` and `settings/siteaccess` whole (a kernel upgrade step may write new settings there), plus `vendor/`, `composer.json/.lock` and the Composer extension folders when Composer runs |
| `listing.json` | the file listing of those folders (a rollback moves aside what was added since) |
| `db.sql.gz` | MySQL/MariaDB and PostgreSQL: xne's framed SQL dump (every statement with its length, rows counted per table, an end mark) |
| `db.sqlite` | SQLite: a consistent copy (SQLite's backup API), integrity-checked |
| `kept/` | a plain copy of every local edit the upgrade replaced |
| `aside/` | the files the upgrade retired |
| `aside-rollback/` | the files a rollback took back (added by the upgrade) |

The newest `backup_keep` backups (default 5) are kept.

## 1. Automatic (F3)

Any failure after the backup (writing files, the kernel's exp:upgrade, an SQL file, the cache clears, the health
check) rolls back at once: maintenance off, added files aside, files restored, library folders cleaned, the database
restored, autoloads and caches. The command exits 1 with `rolled_back: true` and the reason. The backup's state
becomes `rolled-back`. Nothing else is needed.

If the rollback itself fails (`rollback_error` in the result, state `rollback-failed`), the backup is still there
and verified: run `xne rollback <id>` (below) after fixing the cause (a full disk, a database that went away).

## 2. xne rollback

```bash
xne backups  --root=...                    # the backups, newest first
xne rollback --root=... --dry-run          # what the newest backup would restore
xne rollback --root=... [BACKUP-ID]        # files + database
xne rollback --root=... [BACKUP-ID] --no-db   # files only
```

Order: the backup is verified; the files the upgrade added move to `aside-rollback/`; the archived files are written
back (each atomically, checked against its md5); files added to `vendor/` and the Composer extension folders since
the backup move aside; a git install is reset to the commit it was on (`git reset --keep`, refused over local
changes); the database is restored (PostgreSQL in one transaction; MySQL drops every table and recreates it from the
dump; SQLite through the backup API) and its row counts are compared with the backup's; then the extension autoloads
and the caches.

A rollback of the database brings back the data **of the moment of the backup**: content created between the
upgrade and the rollback is lost. Use `--no-db` when the database upgrade itself was fine and only files need to go
back, and tell the site's owner either way.

## 3. git installs: by tag

```bash
xne rollback --root=... --git-tag=v6.0.14 --dry-run
xne rollback --root=... --git-tag=v6.0.14
```

Moves the code to the tag with `git reset --keep` (refused over local changes in tracked files). The database is not
touched: restore it with `xne rollback <id>` when the upgrade changed it.

## 4. By hand, from a backup

Only when xne cannot run at all (no PHP, a broken disk). The backup's files are plain formats:

- `files.tar.gz`: a normal tar.gz; extract it over the install root (`tar -xzf files.tar.gz -C <root>`).
- `db.sqlite`: copy it over the install's database file (`var/storage/sqlite3/<Database>`) while the site is stopped.
- `db.sql.gz`: a SQL file with `-- xne-stmt <bytes>` lines before each statement; drop the tables of the
  database, then feed it to `mysql` or `psql` (`gunzip -c db.sql.gz | mysql <db>`); the comment lines are ignored.
- `backup.json` lists the files the upgrade added (`applied.added`): move them out of the install.

Then clear the caches (`php bin/php/ezcache.php --clear-tag=ini`, `template`, `content`, one per call).
