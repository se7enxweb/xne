# xne command reference

Last verified: 2026-11-26 (xne 0.3.0.2). Every usage block below is the exact text of `xne help <command>`; the
test `tests/php/DocsTest.php` fails when a command, its usage line or one of its options is missing here.

```
xne <command> [arguments] [options]
```

Options are `--name=value` or `--name value`; flags have no value. Without a command, xne prints the overview
(`xne help`).

## Global options

Every command takes these.

| Option | Meaning |
|---|---|
| `--root=DIR` | the install (default: the current folder or the first parent with `lib/version.php`, `kernel/` and `settings/`) |
| `--json` | one JSON object on stdout (below); progress lines go to stderr |
| `--dry-run`, `-n` | show what would happen, change nothing |
| `--help`, `-h` | the manual of the command |
| `--set key=value` | any setting of [etc/xne.conf.example](../etc/xne.conf.example), for this run (repeatable) |

Setting shorthands, each the same as `--set <key>=<value>` (dashes become underscores):

| Option | Setting | Meaning |
|---|---|---|
| `--channel=C` | `channel` | `stable`, `beta`, `main` or `security` (see `plan`) |
| `--method=M` | `method` | `auto`, `filepatch` or `git` |
| `--maintenance=M` | `maintenance` | `keep` (visitors keep being served) or `page` (the kernel's maintenance page during the upgrade) |
| `--php=PATH` | `php` | the PHP binary for xne itself and the install's tools; xne runs itself again under it when it differs. Without it the tools run with the first PHP that meets the install's `vendor/composer/platform_check.php` (and 8.1): the web server's PHP (`.htaccess` AddHandler, else `.php-version`), this PHP, `php` on PATH, then the newest installed (Plesk, LiteSpeed, cPanel, CloudLinux, `/usr/bin/phpX.Y`); the plan shows it as `php` and is blocked when there is none (since 0.3.0.2) |
| `--composer=PATH` | `composer` | the Composer phar the kernel's libraries stage runs, under the install's PHP. Without it xne passes the first real phar it finds (`composer.phar` on the PATH, in `/usr/local/bin` or `/usr/bin`, the install's own); a `composer` wrapper script is never taken, because its phar starts with the node's CLI PHP (since 0.3.0.5) |
| `--state-dir=DIR` | `state_dir` | backups, locks and keys (default `/var/lib/xne` as root, `~/.xne/lib` otherwise, `%ProgramData%\xne\lib` on Windows) |
| `--cache-dir=DIR` | `cache_dir` | the release cache (default `/var/cache/xne`, `~/.xne/cache`, `%ProgramData%\xne\cache`) |
| `--sources=LIST` | `sources` | release sources in rank order: `gitlab`, `github`, `packagist`, `local` |
| `--finish=F` | `finish` | `ezcache` (autoloads and cache clears), `velocity` (also `exp:velocity deploy`) or `none` |
| `--run-as=U` | `run_as` | `auto` (the owner of `index.php` when xne runs as root), `root`, or a user name |
| `--network-url=URL` | `network_url` | the Exponential Network API (default `https://network.se7enx.com/api/v1`) |
| `--server-data=DIR` | `server_data` | server commands: the index and key registry folder |
| `--notify=N` | `notify` | notifications after `check`: `auto` (run the notify hooks that are set) or `none` |
| `--report-level=L` | `report_level` | `version`, `health` or `full`: what `report` sends |

## Exit codes

| Exit | Meaning |
|---|---|
| 0 | PASS (also: up to date, or `check` found an upgrade) |
| 1 | FAIL (an upgrade that failed and was rolled back is a FAIL) |
| 2 | usage error: unknown command, channel, ref or backup id |
| 3 | refused by a safety check: a blocking trap, the lock, a git checkout that cannot fast-forward, a database xne cannot back up, a key the server refuses |

## Looking at an install

### detect

```
xne detect [--root=DIR]
  The install: root, version, install type (git, composer, files), PHP, databases (from its own settings,
  never the password), extensions (kernel, 7x, third-party), owner, and which kernel upgrade tools it has.
```

The install: root, version (`lib/version.php`), install type (`git` when the root is a git checkout, `composer` when
the kernel is a Composer package of the root project, else `files`), PHP, the owner of `index.php`, every database
(from `[DatabaseSettings]`: `settings/site.ini`, the siteaccess, `settings/override`; never the password) with its
`ezsite_data` version, the extensions (kind `kernel` for the bundled ones, `7x` for se7enxweb Composer packages
(`vendor/composer/installed.json`, else `composer.lock` when the lock was refreshed without an install, since 0.3.0.3),
`third-party`; active, version, git, link), the siteaccesses, and which of the kernel's tools exist (`upgrade`,
`maintenance`, `checkmanifest`, `ezcache`, `autoloads`, `velocity`, `console`).

### traps

```
xne traps [--root=DIR]
  The known upgrade traps of the install (public lib copy, Composer files entry, shared extension
  folders, LiteSpeed PHP in .htaccess, Valkey cache, InstallationHash, kernel overrides, manifest).
```

Runs the trap detectors ([traps.md](traps.md)) and lists each with its level (`info`, `warn`, `block`) and fix.

### config

```
xne config [--root=DIR]
  The effective settings and where they come from (secrets are never shown).
```

Every effective setting and the files it came from, in the order they were read. Secrets are never shown: a token is named by its file only.

### db-fingerprint

```
xne db-fingerprint [--root=DIR]
  Rows and an md5 per table of the install's database (diagnostics; compares a restore with its source).
```

Rows and an md5 per table (or collection) of the install's database, plus one fingerprint of all of them. Two equal fingerprints mean equal data; the tests use it to prove that a rollback restored the database exactly.

## Planning and upgrading

### plan

```
xne plan [--root=DIR] [--channel=stable|beta|main|security] [--to=REF] [--method=auto|filepatch|git]
         [--shared=skip|write] [--accept=TRAP,...] [--refresh] [--allow-downgrade]
  What an upgrade would do: the source used (gitlab, github, packagist, in that order), the target, the
  file diff (add, update, retire, local edits kept aside), the libraries and database steps, the traps and
  the ordered steps. Changes nothing in the install.
```

| Option | Meaning |
|---|---|
| `--channel` | `stable` (newest stable release of the same major), `beta` (alpha, beta and rc too), `main` (the nightly branch: a warning, a forced backup), `security` (newest stable release of the install's own x.y line) |
| `--to` | a tag (`v6.0.15` or `6.0.15`), a branch (`main`) or a 40-hex commit; overrides the channel |
| `--method` | `auto` (git for git checkouts, else filepatch), `filepatch`, `git`; `composer` is refused until phase 3 |
| `--shared` | files in folders that link out of the install (shared with another clone): `skip` leaves them, `write` writes through the link; without it the plan is blocked |
| `--accept` | accepts blocking traps by id, after a person decided so |
| `--refresh` | fetches the source's ref list again (it is cached for `index_ttl` seconds) |
| `--allow-downgrade` | allows a target older than the install (use `xne rollback` instead when you can) |

The plan lists: the sources in rank order and which answered; the target (ref, kind, commit, version, source); the
method and the base of the file diff (`release v6.0.14`: the tree of the install's own release, else
`share/filelist.md5`, else none); the file counts; the local edits; the database mode (`kernel`: the stages
`exp:upgrade` will run; `sql`: the SQL update files, in order); whether Composer runs; the traps; warnings; the ordered
steps; and `blocked` (why it would be refused). A plan downloads the target release (and the install's own release,
as the base) into the release cache once; it changes nothing in the install. Exit 3 when the plan is blocked.

### upgrade

```
xne upgrade [plan options] [--dry-run]
  Plan, verified backup, maintenance (setting), files, the kernel's exp:upgrade or the SQL update files,
  autoloads and cache clears, health check. Any failure after the backup rolls back files and database
  at once. --dry-run prints the plan and changes nothing.
```

With `--dry-run` it prints the plan and stops (exit 0, or 3 when blocked). Otherwise, in this order:

1. **plan** again, refused (exit 3) when blocked;
2. **lock** of the install (`<state_dir>/installs/<install>/lock`): a second run is refused;
3. **backup**, verified ([rollback-guide.md](rollback-guide.md)); when it fails, nothing is changed;
4. **maintenance on** when `maintenance=page` (the kernel's `bin/php/maintenance.php on --allow-admin`);
5. **files**: the file patch (add, update, local edits replaced after a copy is kept, retired files moved aside) or
   the git fast-forward (`git merge --ff-only`);
6. **libraries, database, scripts**: `php bin/php/upgrade.php --apply --stages=[libraries,]database,scripts
   --no-deploy --no-verify --no-tests --format=json --no-caption --force-no-caption --to=<commit>` when the target has
   the kernel's exp:upgrade (xne's backup replaces the kernel's caption), else the SQL update files;
7. **finish**: `ezpgenerateautoloads.php -e`, then `ezcache.php --clear-tag=<tag>` once per tag of `cache_tags`;
   with `finish=velocity` also `exp:velocity deploy`;
8. **health**: written files equal the release, `share/filelist.md5` is the release's own and matches the files, the
   install starts from the CLI (`ezcache.php --list-tags`), `exp:upgrade:status` shows no failed or pending step of
   the stages that ran, every `health_url` answers 200 to 399;
9. **maintenance off**; the backup is marked `applied`; older backups beyond `backup_keep` are rotated away.

Any failure in steps 4 to 8 starts the **automatic rollback** and the command exits 1 with `rolled_back: true`.

### sources

```
xne sources [--refresh]
  The release sources in rank order, which one answers, and the newest refs per channel.
```

The sources in rank order (`sources=`, default `gitlab,github,packagist`; `local` for a folder of release archives), which one answered, and the target of each channel. Ref lists are cached for `index_ttl` seconds; `--refresh` fetches them again.

## Backups and rollback

### backup

```
xne backup [--root=DIR] [--dry-run]
  A verified backup now: the database plus the files the current plan would change.
```

A verified backup now: the database and the files the current plan would change. `--dry-run` lists what it would hold. The backup lives in `<state_dir>/installs/<install>/backups/<id>/` and is read back before the command reports PASS.

### backups

```
xne backups [--root=DIR]
  The backups of the install, newest first, with state and size.
```

Every backup of the install, newest first: id, state (`verified`, `applying`, `applied`, `rolling-back`, `rolled-back`, `rollback-failed`), from and to versions, method, file count, database engine, size.

### verify-backup

```
xne verify-backup [BACKUP-ID]
  Reads a backup back: archive checksums, every file's md5, the dump to its end mark with its row counts.
```

Reads a backup back (default: the newest): the archive checksum, every file's md5, the dump to its end mark with its row counts; SQLite: the copy's integrity check and counts. Exit 1 when anything differs.

### defaults

```
xne defaults [--root=DIR] --reference=DIR [--dry-run]
  An older install's settings brought to a fresh install's of the same kernel, the site's own kept: the
  reference's ActiveExtensions the install has but does not run (added after its own), admin4l and the access
  extensions on every administration siteaccess, AllowedDeletionDirs for a var/ link out of the install. A
  verified backup first (settings files + database), then the kernel's autoloads, its database stage (tables of
  the newly active extensions) and the cache clears; any failure rolls back (xne rollback works on it too).
```

`--reference` names the root of a fresh install made by the kernel's `exp:install` from the same release (a fresh
install's extension list comes from its site package and the kernel's own defaults, so a real one is the only
complete list). What changes, and only that:

- `settings/override/site.ini.append.php`: `ActiveExtensions[]` keeps the site's list and order; the reference's
  extensions that the install has in `extension/` and does not run are added after them. An extension the install
  does not have (the reference site's own) is never added.
- every administration siteaccess (its `SiteDesign` is an admin design, or a design that exists nowhere followed by
  admin designs): `SiteDesign=admin4l`, the reference admin's `AdditionalSiteDesignList[]`, and its
  `ActiveAccessExtensions[]` that the install has and does not run globally.
- `[FileSettings] AllowedDeletionDirs[]`: the real folder of `var/` when `var/` is a link out of the install (a
  capture's `arc/` tree); without it the kernel refuses every cache delete there.

Each file is edited line by line (comments, order and other settings stay). Before: a verified backup of those files
and the database. After: the kernel's extension autoloads, its `exp:upgrade` database stage (the tables of the
newly active extensions; the INI cache is cleared before it, and a database step still pending after it fails the
run), the cache clears and the CLI bootstrap check; any failure rolls back. Settings already at the defaults with
the kernel's database steps still pending (a run before 0.3.0.7) get the database stage on the next run; and `xne rollback`
gives the settings back later. `--dry-run` shows the plan only.

### rollback

```
xne rollback [BACKUP-ID] [--no-db] [--dry-run] [--shared=write]
  Files and database back to a backup (default: the newest). Files the upgrade added are moved aside into
  the backup, never deleted. Every path is checked first; a file in a folder that leads out of the install is
  restored only when the upgrade wrote it with --shared=write (recorded in the backup since xne 0.3.0.2), or
  with --shared=write here for an older backup.
xne rollback --git-tag=TAG [--dry-run]
  A git install's code back to a tag (git reset --keep); the database is not touched.
```

Without an id, the newest backup. Files the upgrade added are moved aside into the backup, archived files are
written back (checked against their md5), library folders lose what was added since, a git install is reset to the
commit it was on (`git reset` with `--keep`, which refuses to overwrite local changes), the database is restored (counts checked, tables made since are dropped), then
autoloads and caches. `--no-db` leaves the database. `--git-tag` moves a git install's code to a tag and leaves the
database alone. Every path of the archive is checked before anything changes, so a refusal leaves the install
as it was. Files an upgrade wrote through a link out of the install (`--shared=write`) go back through the same
link: the backup records them since 0.3.0.2; for an older backup say `--shared=write` again. Details:
[rollback-guide.md](rollback-guide.md).

## Checks, reports and install keys

### check

```
xne check [--root=DIR] [--no-notify]
  What each channel offers the install and the advisories that affect it: from the Exponential Network
  server (network_url), signed when the install has a key, else from the release sources. Runs the notify
  hooks (notify_problem_cmd, notify_ok_cmd, notify_event_cmd; setting notify). Exit 0 also when an upgrade is available (see upgrade_available).
```

Asks `network_url` what each channel offers and which advisories affect the install (signed when the install has a key, anonymous otherwise: then only the version is sent); when the server cannot be reached, the release sources answer. `upgrade_available` and `security` in the result say whether to act. Exit 0 also when an upgrade is available; exit 3 when the server refuses the key. `--no-notify` (or `notify=none`) sends no notification. The notify hooks are described in [server.md](server.md#notify-hooks).

### report

```
xne report [--root=DIR] [--dry-run]
  Sends the install's report at report_level (version, health, full) to the server, signed with the install
  key. Never content, users or settings values. --dry-run shows the report and sends nothing.
```

Sends the install's report at `report_level` (`version`, `health` or `full`) to the server, signed with the install key; without a key it is refused. A report never holds content, users or settings values. `--dry-run` prints the report and sends nothing.

### key:set

```
xne key:set [--root=DIR | --domain=DOMAIN] < keyfile
  Stores the install key "<id>:<secret>" read from stdin (never an argument) in a 0600 file.
```

Reads `<id>:<secret>` from standard input (never from an argument, so it does not show up in the process list or the shell history) and stores it in a 0600 file in the state folder. `--domain=DOMAIN` finds the install of a hosted domain instead of `--root`.

### key:status

```
xne key:status [--root=DIR]
  The install key's id and fingerprint, never the secret.
```

The install key's id and fingerprint, so it can be compared with the server's record; never the secret.

## Server commands (the API and mirror nodes)

### server:index

```
xne server:index [--set server_data=DIR]
  Builds the server's release index from the ranked sources into server_data/index.json.
```

On the API node: builds the release index (every release tag, its commit, version and stability, and the main branch head) from the ranked sources into `server_data/index.json`, which the API answers from. Details: [server.md](server.md).

### server:key:issue

```
xne server:key:issue --account=ACCOUNT --level=health|full --out=FILE
  Issues an install key for an account; writes "<id>:<secret>" once into FILE (0600). The secret is never printed.
```

Issues an install key.

| Option | Meaning |
|---|---|
| `--account=ACCOUNT` | the hosting account the install belongs to (recorded with the key) |
| `--level=health\|full` | what reports signed with the key may hold: `health` (version and health), `full` (more detail, never content) |
| `--out=FILE` | where the key is written, once, mode 0600 |

 The `<id>:<secret>` pair is written once into that file; the secret is never printed and the server keeps only its hash.

### server:key:list

```
xne server:key:list
  The issued keys: id, account, level, created, revoked, fingerprint.
```

The issued keys: id, account, level, created, revoked, fingerprint.

### server:key:revoke

```
xne server:key:revoke <key id>
  Revokes a key; its signed requests are refused from then on.
```

Revokes a key; every signed request with it is refused from then on.

### server:key:import

```
xne server:key:import < record.json
  Stores a key record sent by a peer node (server_peers replicate every issue and revoke over ssh stdin).
```

Stores a key record sent by a peer API node on standard input; `server_peers` replicate every issue and revoke this way.

### server:key:provision

```
xne server:key:provision --account=ACCOUNT --level=health|full --domain=DOMAIN --target=SSH-TARGET
  Issues a key and installs it on the domain's server (xne key:set --domain over ssh stdin); the fingerprint must match.
  A provisioning system runs it when a customer install goes live.
```

Issues a key and installs it on the server that hosts the domain (`xne key:set --domain` over ssh, the key on standard input); the fingerprint read back must match. `--account` and `--level` as for `server:key:issue`; `--domain=DOMAIN` is the site's domain and `--target=SSH-TARGET` the ssh destination (`user@host`) of the server that hosts it.

### server:mirror

```
xne server:mirror [--dry-run]
  Mirrors mirror_from (GitHub) to mirror_to (the GitLab release mirror): branches and tags, never a forced tag.
```

Copies `mirror_from` (the GitHub repository of Exponential) to `mirror_to` (a GitLab release mirror): every branch and tag. A tag that moved upstream is reported, never forced over the mirror's. `--dry-run` lists what it would push.

## Other

### version

```
xne version
  The xne version.
```

The xne version, four positions `X.Y.Z.N` (also in every `--json` result as `version`). `xne --version` prints the same.

### help

```
xne help [command]
  This overview, or one command's manual.
```

The overview of every command, or one command's manual. `xne <command> --help` (or `-h`) prints the same.

## The --json shape

```json
{
  "tool": "xne",
  "version": "0.3.0.2",
  "command": "plan",
  "ok": true,
  "exit": 0,
  "result": { "...": "the command's result" },
  "messages": ["human lines, when there were any"]
}
```

On an error, `result.error` holds the message. The result of each command is what the human output shows, as data;
[AI.md](AI.md) lists the fields to read for plan and upgrade.

## Settings

Server-wide in `/etc/xne/xne.conf` (or `XNE_CONF`), per install in `settings/override/xne.ini.append.php`
`[NetworkSettings]` (`Channel`, `Method`, `Maintenance`, `MaintenanceUntil`, `BackupKeep`, `Finish`,
`DatabaseUpgrade`, `Libraries`, `HealthURL[]`, `Sources`, `RunAs`, `PHP`, `CacheTags`), in the environment as
`XNE_<KEY>` and on the command line. Every key: [etc/xne.conf.example](../etc/xne.conf.example).

| Environment | Meaning |
|---|---|
| `XNE_CONF` | the server settings file instead of `/etc/xne/xne.conf` |
| `XNE_<KEY>` | any setting, e.g. `XNE_STATE_DIR`, `XNE_CHANNEL`, `XNE_PHP` |
| `XNE_GITHUB_TOKEN`, `XNE_GITLAB_TOKEN` | API tokens when no token file is set (never logged) |
| `XNE_TEST_FAIL_AFTER` | tests only: fail on purpose after `backup`, `files`, `database`, `finish` or `health` |
