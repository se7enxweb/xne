# xne: Exponential Network

[![Version](https://img.shields.io/badge/xne-0.3.0.10-2b6cb0)](#versioning)
[![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.5-8892BF?logo=php&logoColor=white)](https://php.net)
[![Engines](https://img.shields.io/badge/databases-MySQL%20%7C%20PostgreSQL%20%7C%20SQLite%20%7C%20MongoDB%20%7C%20Oracle-4a5568)](doc/engines.md)
[![API](https://img.shields.io/badge/API-network.se7enx.com%2Fapi%2Fv1-38a169)](#the-api)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

### Safe, verified upgrades for every Exponential install. One command, and a way back.

An upgrade by hand is a list of risky steps: pull the code, copy files, run SQL update files, pick the right update
scripts, wipe caches, and hope. When step four fails at midnight, nobody has a clean way back.

**xne** (product name **Exponential Network**) turns that into one command. It looks at the install, plans the
upgrade, makes a **verified backup**, upgrades files, libraries and database, **checks the result**, and when any step
fails it **rolls everything back by itself**. It works on all five database engines Exponential runs on, in pure PHP
7.4 to 8.5, with no shell tool in the engine.

It overlays the kernel's own tools (`exp:upgrade`, the SQL update files, the autoload generator, `ezcache.php`); it
does not replace them. And it is the only way an Exponential install gets upgraded on the 7x servers.

> **AI assistants (Claude and others): read [doc/AI.md](doc/AI.md) first. Never script an Exponential install
> upgrade by hand over a shell; use xne.**

## Requirements

- **PHP 7.4 to 8.5** on the command line (`php -v`), with `zlib` (release archives and backups) and `json`; `curl` or
  `openssl` for HTTPS downloads.
- The PDO driver of the install's database: `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`; for MongoDB the `mongodb`
  extension, for Oracle `oci8`. When the PHP on `PATH` lacks it, point xne at one that has it with `--php=...`.
- `git`, only for installs that are git checkouts (the git fast-forward method).
- Write access to the install, as its owner (or as root/Administrator: xne then runs the install's own tools as the
  owner of `index.php`).

## Install

xne is a folder of PHP files; nothing is compiled. Get it with git or as an archive of the repository, put it in a
fixed place, and make the `xne` command reachable. Releases are tagged `vX.Y.Z.N`; check out the newest tag.

### Linux (and BSD)

```bash
sudo git clone https://github.com/se7enxweb/xne.git /opt/xne
cd /opt/xne && sudo git checkout "$(git tag -l 'v*' --sort=version:refname | tail -1)"
sudo ./install.sh            # shows what it would do
sudo ./install.sh --apply    # links /usr/local/bin/xne, creates /etc/xne, /var/lib/xne (0700), /var/cache/xne
xne version
```

`install.sh` never removes anything and can be run again after every update (`git fetch --tags`, check out the new
tag, `sudo ./install.sh --apply`). Run as root, xne keeps its state in `/var/lib/xne` and its release cache in
`/var/cache/xne`; run as a normal user, in `~/.xne/lib` and `~/.xne/cache`.

### macOS

```bash
git clone https://github.com/se7enxweb/xne.git ~/xne
ln -s ~/xne/bin/xne /usr/local/bin/xne      # or add ~/xne/bin to PATH
xne version
```

Use the PHP that runs the site (Homebrew `php`, MAMP's `/Applications/MAMP/bin/php/php8.x/bin/php`): `--php=` or
`XNE_PHP` names it. State and cache: `~/.xne/`.

### Windows

1. Install PHP 7.4 or newer for the command line (the same PHP the site uses: XAMPP's `C:\xampp\php\php.exe`,
   WampServer's `C:\wamp64\bin\php\php8.x\php.exe`, or a zip from windows.php.net), with
   `extension=openssl`, `extension=curl` and the database's `pdo_*` enabled in its `php.ini` (zlib is built in).
2. Get xne: `git clone https://github.com/se7enxweb/xne.git C:\xne`, or download the repository archive and unpack it
   to `C:\xne`.
3. Add `C:\xne\bin` to `PATH`, or set `XNE_PHP` to the full path of `php.exe` when it is not on `PATH`:

```bat
setx XNE_PHP "C:\xampp\php\php.exe"
xne version
xne detect --root="C:\xampp\htdocs\exponential"
```

In PowerShell the same commands run through `xne.ps1` (`xne version`; when scripts are blocked:
`powershell -ExecutionPolicy Bypass -File C:\xne\bin\xne.ps1 version`). State and cache:
`%ProgramData%\xne\lib` and `%ProgramData%\xne\cache`. Windows, IIS, XAMPP and WampServer specifics:
[doc/windows-guide.md](doc/windows-guide.md).

Every push is tested on Linux (PHP 7.4 and 8.5) and on Windows Server 2022 and 2025 (PHP 8.1 and 8.5), including a
real Exponential release upgraded and rolled back on Windows: [doc/testing.md](doc/testing.md).

## Quick start

Point `--root` at the install (the folder with `lib/version.php`, `kernel/` and `settings/`), or run xne from inside
it.

```bash
xne detect  --root=/var/www/example.com      # what the install is: version, type, PHP, database, owner
xne traps   --root=/var/www/example.com      # known upgrade traps, each with its fix
xne plan    --root=/var/www/example.com      # what would change; nothing is touched
xne upgrade --root=/var/www/example.com --dry-run
xne upgrade --root=/var/www/example.com      # verified backup, upgrade, health check, automatic rollback on failure
xne backups --root=/var/www/example.com      # the backups, newest first
xne rollback --root=/var/www/example.com     # files and database back to the newest backup
```

Every command answers `PASS`, `FAIL` or `REFUSED` with a fixed exit code (0, 1, 3; 2 for a usage error), and
prints one JSON object with `--json`. A first upgrade, step by step: [doc/upgrade-guide.md](doc/upgrade-guide.md).
Every command and option: [doc/cli.md](doc/cli.md).

---

## Features

- **One command per job.** `detect`, `plan`, `upgrade`, `rollback`; every command takes `--json`, `--dry-run` and
  `--help`, and answers with fixed exit codes (0 PASS, 1 FAIL, 2 usage, 3 refused).
- **A verified backup before every upgrade.** The database and every file the upgrade will change, read back and
  checked before anything is written. There is no option to upgrade without one, on purpose.
- **Automatic rollback.** Any failure after the backup (files, libraries, database, finish, health check) restores
  files and database at once; the command exits 1 with `rolled_back: true`.
- **Two upgrade methods, chosen per install.** A verified **file patch** (the diff against `share/filelist.md5`,
  written file by file and checked against the release) or a **git fast-forward** for git installs.
- **Local edits kept aside.** Files changed on the server are copied into the backup's `kept/` folder and listed,
  never silently overwritten.
- **Nothing is deleted.** Retired files, and files a rollback takes back, are moved into the backup folder.
- **Kernel libraries stage.** When the kernel reports its `libraries` stage pending, xne runs
  `libraries, database, scripts` in that order, with `vendor/`, `composer.json`, `composer.lock` and the Composer
  extension folders in the verified backup (since 0.3.0.1).
- **Trap detection.** Known upgrade traps (a stale `lib/` copy above the docroot, a Composer `files` entry outside
  the install, shared extension folders, LiteSpeed PHP handlers, kernel class overrides, an unknown
  `DatabaseImplementation`, ...) are reported as `info`, `warn` or `block` with the fix: [doc/traps.md](doc/traps.md).
- **Channels.** `stable`, `beta`, `main` (nightly, with a warning) and `security` (only the newest release of the same
  x.y line), or an exact release with `--to=v6.0.15`.
- **Ranked release sources.** GitLab, GitHub, Packagist and a local source, in an order one setting changes
  (`sources=`); releases are downloaded once into the release cache.
- **Health check after every upgrade.** Written files equal the release, the release's own `share/filelist.md5`
  matches the files, the install starts from the CLI, `exp:upgrade:status` shows nothing failed or pending, and every
  `health_url` answers 200 to 399.
- **Visitors keep being served** by default; with `maintenance=page` they get the kernel's maintenance page (503 with
  Retry-After) while admins can still sign in.
- **One run per install.** A lock refuses a second run; each install has its own backups (the last `backup_keep`,
  default 5, are kept).
- **Checks and notifications.** `xne check` asks network.se7enx.com what each channel offers and which advisories
  affect the install, and runs the notify hooks (`notify_problem_cmd`, `notify_ok_cmd`, `notify_event_cmd`): any
  alerting, chat or ticket tool can open and resolve a problem or receive an event.
- **Install keys.** A signed install key unlocks monitoring reports; the secret never travels and is never printed.

## Database engines and PHP

xne backs up, upgrades and rolls back the database through the install's own `[DatabaseSettings]` (the password is
never shown). A rollback is exact: the tests compare a fingerprint of every table or collection before the backup and
after the restore.

| Engine | DatabaseImplementation | PHP extension | Backup |
|---|---|---|---|
| MySQL / MariaDB | `ezmysqli`, `ezmysql` | pdo_mysql | `db.sql.gz`, xne's framed SQL dump with row counts |
| PostgreSQL | `ezpostgresql` | pdo_pgsql | `db.sql.gz`, DDL from the catalog, sequences at their positions; restored in one transaction |
| SQLite | `sqlite3`, `ezsqlite3` | pdo_sqlite | `db.sqlite`, a consistent copy, integrity-checked |
| MongoDB | `expmongodb` | mongodb | `db.mongo.jsonl.gz`, indexes and documents as canonical extended JSON |
| Oracle | `ezoracle` | oci8 | `db.oracle.jsonl.gz`, DDL from DBMS_METADATA, rows with CLOB/BLOB whole |

Proven: MySQL/MariaDB, PostgreSQL and SQLite upgrade 6.0.13 -> 6.0.14 and 6.0.14 -> 6.0.15 by file patch and by git,
with exact rollbacks; MongoDB and Oracle a full upgrade of a kickstarted install and an exact `xne rollback`. Details
and limits: [doc/engines.md](doc/engines.md).

**PHP 7.4 to 8.5.** When xne's own PHP lacks a driver, name one that has it: `--php=/usr/bin/php`.
The engine needs no shell tool, so the same commands run on Linux, Windows, macOS and BSD (the Windows launchers and
installer are phase 4).

## How an upgrade works

```
 detect ──▶ plan ──▶ lock ──▶ backup (verified) ──▶ maintenance on? (optional)
                                                          │
                                                          ▼
                                    files: file patch | git fast-forward
                                                          │
                                                          ▼
                      kernel exp:upgrade: [libraries,] database, scripts   (else the SQL update files)
                                                          │
                                                          ▼
                              finish: autoloads + cache clears ──▶ health check
                                                          │
                     ┌──────────── any failure ◀─────────┤
                     ▼                                    ▼ PASS
   automatic rollback: files, libraries,        maintenance off ──▶ backup marked applied
   database, caches (exit 1, rolled_back)                  ──▶ old backups rotated
```

Results read `PASS: upgraded 6.0.14 -> 6.0.15 (backup <id>)`, `FAIL: <reason>; rolled back to backup <id>`, or
`REFUSED: ...` when a safety check stopped it before anything changed. Step by step:
[doc/upgrade-guide.md](doc/upgrade-guide.md); going back: [doc/rollback-guide.md](doc/rollback-guide.md).

## Commands

Global options: `--root=DIR`, `--json`, `--dry-run`, `--help`, `--set key=value` (any `xne.conf` key).
Full reference: [doc/cli.md](doc/cli.md).

| Command | What it does |
|---|---|
| `xne detect` | version, install type (`git`, `composer`, `files`), PHP, owner, databases, extensions, siteaccesses, kernel tools |
| `xne traps` | the known upgrade traps of this install, each with its level and fix |
| `xne plan [--channel=stable\|beta\|main\|security] [--to=REF] [--method=auto\|filepatch\|git]` | target, file counts, local edits, database steps, traps, warnings, `blocked`; changes nothing |
| `xne upgrade [plan options] [--dry-run]` | plan, lock, verified backup, files, libraries/database/scripts, finish, health; auto-rollback on failure |
| `xne rollback [BACKUP-ID] [--no-db] [--dry-run]` | files and database back to a backup; `--git-tag=TAG` moves a git install's code to a tag |
| `xne backup` | a verified backup now |
| `xne backups` | every backup, newest first, with its state |
| `xne verify-backup [BACKUP-ID]` | reads a backup back: archive checksum, every file's md5, the dump and its row counts |
| `xne sources [--refresh]` | the release sources in rank order and the target of each channel |
| `xne config` | every effective setting and where it came from (secrets never shown) |
| `xne db-fingerprint` | rows and an md5 per table, plus one fingerprint of all of them |
| `xne check [--no-notify]` | channels and advisories from network.se7enx.com (else the sources); notifies |
| `xne report [--dry-run]` | the install's report to the server, at the key's level |
| `xne key:set`, `xne key:status` | store an install key from stdin (0600); show its id and fingerprint |
| `xne server:index`, `server:mirror`, `server:key:*` | the API node: release index, GitLab release mirror, install keys ([doc/server.md](doc/server.md)) |
| `xne version`, `xne help [command]` | the version; the manual of one command |

## The API

`https://network.se7enx.com/api/v1`: no framework, the same class runs behind network.se7enx.com or self-hosted.
Every answer is JSON with `ok`.

| Request | Answer |
|---|---|
| `GET /status` | `{ok, api: "xne/1", version, index_built, releases}` |
| `GET /releases` | the release index: every release tag (ref, commit, version, stable) and the main branch head |
| `GET /advisories?version=6.0.14` | the advisories whose range contains the version |
| `POST /check` `{"version": "6.0.14"}` | what each channel offers, advisories, `upgrade_available`, `security`. Anonymous checks send only the version |
| `POST /report` | signed only: the install's report trimmed to the key's level; never content, users or settings values |

Signed requests carry `X-Xne-Key`, `X-Xne-Time` and an HMAC-SHA256 `X-Xne-Signature`; a signature more than 300
seconds off is refused, so a captured request cannot be replayed. Details: [doc/server.md](doc/server.md).

```bash
curl -s https://network.se7enx.com/api/v1/status
```

## Safety guarantees

What xne never does:

- **It never changes an install without a verified backup.** If the backup fails, nothing is changed.
- **It never leaves a half-upgraded install.** Any failure after the backup rolls files and database back at once.
- **It never deletes a file.** Retired files and files a rollback takes back are moved into the backup folder; only
  one class (`Guard`) moves or removes files.
- **It never overwrites a local edit silently.** Each one is kept aside in the backup and listed.
- **It never writes protected files**: `settings/override`, `settings/siteaccess`, `var`, `config.php`,
  `.htaccess` and the like.
- **It never runs twice on one install.** A lock refuses a second run (exit 3).
- **It never forces git.** A git checkout that cannot fast-forward is refused; a GitLab mirror tag that differs is
  never overwritten.
- **It never works around a blocking trap** unless the person responsible accepts that trap by id (`--accept=<id>`).
- **It never prints a secret.** No database password, token or key secret is shown; keys are read from stdin only.
- **It never tags or publishes an Exponential release.** xne installs releases; it does not make them.

## Versioning

Four positions, `X.Y.Z.N`: currently **0.3.0.11** (`App::VERSION`, the `VERSION` file, `xne version`, every `--json`
result and `GET /api/v1/status`). Only the last position counts on: 0.3.0.11 -> 0.3.0.12. Tags are permanent:
a published version is never moved or re-cut; a fix is always the next version.

- **0.3.0.11** (2026-11-27): after a file patch xne tells the kernel the code is in place, so `exp:upgrade` runs the
  later stages on an install that is a git checkout (its commits are left as they were, and the kernel's code step
  stayed pending: "the stage code has 1 pending step(s)", then a rollback). It passes `--code-applied=<commit>` when
  the target's kernel knows it (Exponential main from 2026-11-27), and on an older kernel `--force-order` only when
  `exp:upgrade:status` shows the code step as the one pending step before the run; `xne plan` reports the answer as
  `database.code_stage` (`CodeStageTest`).
- **0.3.0.10** (2026-11-26): `xne plan` and the upgrade preflight check the PHP extensions the target release
  requires (`ext-*` of its composer.json, its composer.lock platform and every package the lock installs, never the
  dev ones) against the PHP its tools and Composer run with, and block with trap `php-extension-missing` before
  anything changes, naming each extension, who requires it, the loaded php.ini and the line to add on Linux and
  Windows (`extension=sockets`). Exponential main requires ext-sockets; before, Composer refused it half way.
- **0.3.0.9** (2026-11-26): a rollback moves aside the extension folders Composer added for a dependency of an
  updated package (named only by the install's composer.lock afterwards, as exponential-php-floor 1.3 brings some
  forty extensions), and extension folders are named as the legacy installer names them
  (`ezpublish-legacy-extension-name`). On Windows, upgrades from 6.0.13 to 6.0.14 and from 6.0.14 to 6.0.15 run in
  CI on Windows Server 2022 and 2025, each with an exact rollback ([xne CI](https://github.com/se7enxweb/xne/actions/runs/45848213473), [Exponential's Windows
  CI](https://github.com/se7enxweb/exponential/actions/runs/45867228580)); the Windows limit noted for 0.3.0.8 no longer applies.
- **0.3.0.8** (2026-11-26): notifications go through generic notify hooks (`notify_problem_cmd`, `notify_ok_cmd`,
  `notify_event_cmd`): command lines with placeholders and `XNE_NOTIFY_*` environment, started without a shell, so
  any alerting, chat or ticket tool can be plugged in (doc/server.md, "Notify hooks"). Windows is proven in CI.
- **0.3.0.1** (2026-11-24): when the kernel reports its libraries stage pending with an unchanged `composer.lock`
  (`vendor/` not what the lock names, as on a kickstarted install), xne plans and runs it before the database stage,
  with the library folders in the verified backup and the same automatic rollback (`LibrariesTest`).

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Linux CLI: detect, plan, backup, apply (file patch, git), rollback; MySQL/MariaDB, PostgreSQL, SQLite (MongoDB and Oracle since); traps; tests on throwaway installs | done |
| 2 | API v1, release index, advisories, signed install keys, `xne check`/`report`, notify hooks, the GitLab release mirror | done; the API is live on network.se7enx.com |
| 3 | Setup > Network (admin4 first), portal, Composer method, 4.x/5.x chains, nightly and security channels with auto-apply | planned |
| 4 | Windows: launchers, MSI, WAMP/XAMPP/IIS, scheduled task, guides | planned |

## Inside the package

| Path | Contents |
|---|---|
| `bin/xne` | the CLI (PHP); `xne.bat`, `xne.ps1` Windows launchers (stubs until phase 4) |
| `lib/Xne/` | HTTP (curl or streams), tar.gz reader/writer, filelist.md5, INI reader, versions, processes, safe file writes |
| `classes/Xne/Client/` | the stages: `Detect`, `Plan`, `Backup`, `Apply`, `Rollback`, `Health`, `Traps`, `Release`, `Kernel`, `Guard` |
| `classes/Xne/Source/` | release sources `GitLab`, `GitHub`, `Packagist`, `Local`, and the `Ranker` |
| `classes/Xne/Method/` | `FilePatch`, `Git` |
| `classes/Xne/Db/` | dump, verify, restore and fingerprint per engine, through the install's own settings |
| `classes/Xne/Server/`, `server/` | the API v1, release index, advisories, key registry, GitLab mirror |
| `extension/exp_network/` | the Exponential extension (phase 3: Setup > Network, `exp:network:*`) |
| `cronjobs/` | the daily check (`xne-check.timer`, not installed by default) |
| `etc/xne.conf.example` | every setting with its meaning |
| `tests/` | `tests/php/` unit and fake-release end-to-end tests; `tests/integration/` real releases on throwaway installs |

State: `/var/lib/xne/installs/<install>/backups/<id>/`. Release cache: `/var/cache/xne/`.

## Documentation

| Document | For |
|---|---|
| [doc/README.md](doc/README.md) | the index of every guide |
| [doc/cli.md](doc/cli.md) | every command, option, setting, exit code and the `--json` shape |
| [doc/engines.md](doc/engines.md) | the five database engines: backup, upgrade and rollback of each, what is proven |
| [doc/server.md](doc/server.md) | the API v1, install keys, check, report, notifications, the GitLab release mirror |
| [doc/AI.md](doc/AI.md) | AI assistants: the rule, the commands, reading `--json`, the safety rules |
| [doc/upgrade-guide.md](doc/upgrade-guide.md), [doc/rollback-guide.md](doc/rollback-guide.md) | administrators, step by step |
| [doc/traps.md](doc/traps.md), [doc/troubleshooting.md](doc/troubleshooting.md), [doc/testing.md](doc/testing.md) | traps, messages, tests |
| [doc/design.md](doc/design.md) | the design and the owner's decisions |

Exponential Network: [network.se7enx.com](https://network.se7enx.com/).

## License

GNU General Public License v2.0 or later, for client and server.
