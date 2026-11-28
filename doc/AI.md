# xne for AI assistants (Claude and others)

Last verified: 2026-11-24 (xne 0.2.0, phases 1 and 2)

This page is written for an AI assistant that is asked to upgrade, check or repair an Exponential install. Read it
whole before you act. It needs no other context.

## The rule

**Never script an Exponential install upgrade by hand over a shell; use xne.**

That means: no hand-run `git pull`, `rsync` or `cp` of kernel files, no hand-run SQL update files, no hand-picked
list of `update/common/scripts`, no ad-hoc cache wiping, no "quick" `sed` on kernel files, no copying a release
tarball over a live tree. Every one of those steps exists in xne as tested code, with a verified backup before it
and an automatic rollback after it. A hand-run upgrade has neither.

You may (and should) **call** xne. You may read what it prints. You decide nothing it already decides.

## What xne is

`xne` (Exponential Network) is a command-line tool, pure PHP 7.4 to 8.5, installed as `xne` on the PATH
(`/usr/local/bin/xne` by `install.sh`, or `vendor/bin/xne` with Composer). For one install it detects what it is, plans an upgrade, makes a verified backup
(database + every file that will change), applies the upgrade (file patch or git fast-forward, then the kernel's own
`exp:upgrade` or the SQL update files, autoloads, targeted cache clears), checks the result, and rolls everything
back by itself when any step fails. It overlays the kernel's own tools; it does not replace them.

## The commands you need

Run them as root (xne runs the install's own tools as the install's owner), from anywhere; `--root` names the
install (the folder with `lib/version.php`, `kernel/`, `settings/`).

```bash
xne detect  --root=/var/www/vhosts/<account>/doc/<host>            # what the install is
xne traps   --root=...                                              # known upgrade traps of this install
xne plan    --root=... [--channel=stable|beta|main|security] [--to=v6.0.15] --json
xne upgrade --root=... [same options] --dry-run                    # the plan, nothing changed
xne upgrade --root=... [same options] --json                       # backup, upgrade, health check, auto-rollback
xne backups --root=...                                              # backups, newest first, with state
xne rollback --root=... [BACKUP-ID] --dry-run                       # what a rollback would do
xne rollback --root=... [BACKUP-ID]                                 # files + database back to the backup
xne rollback --root=... --git-tag=v6.0.14                           # git installs: code back to a tag (DB untouched)
xne verify-backup --root=... [BACKUP-ID]                            # read a backup back and check it
xne help [command]                                                  # the manual of one command
xne check --root=... --json                                         # channels + advisories (server, else sources); notifies
xne defaults --root=... --reference=<fresh install root> [--dry-run]  # settings to a fresh install's (extensions, admin4l, var link)
xne key:status --root=...                                           # install key id + fingerprint (never the secret)
```

The normal sequence for one install:

1. `xne detect --root=R` and `xne traps --root=R`. Read every `warn` and `block` line.
2. `xne plan --root=R --json`. Read `blocked`, `warnings`, `local_edits`, `traps`, `database`, `steps`.
3. If `blocked` is not empty: **stop**. Fix the cause the way the message's `fix` says, or report it. Use
   `--accept=<trap-id>` only when the person responsible for the site said so for that trap.
4. `xne upgrade --root=R --dry-run` once more if anything changed, then `xne upgrade --root=R --json`.
5. Read the result (below). On PASS report the version, the backup id and the files kept aside. On FAIL report
   the error and that the rollback ran (`rolled_back: true`); do not try again with changes of your own.

## Reading --json

Every command prints exactly one JSON object on stdout with `--json`; progress lines go to stderr.

```json
{ "tool": "xne", "version": "0.2.0", "command": "upgrade", "ok": true, "exit": 0, "result": { ... } }
```

| Exit | `ok` | Means | What you do |
|---|---|---|---|
| 0 | true | PASS (or up to date) | report it |
| 1 | false | FAIL; for an upgrade: it failed **and was rolled back** when `result.rolled_back` is true | report `result.error`; never retry blindly |
| 2 | false | usage error (unknown command, option, channel, ref, backup id) | fix the command line |
| 3 | false | refused by a safety check (blocking trap, lock held, git not fast-forward, database xne cannot back up) | report it; do not work around it |

Important fields:

| Command | Field | Meaning |
|---|---|---|
| plan | `result.up_to_date` | nothing to do on this channel |
| plan | `result.target.{ref,version,sha,source}` | what would be installed, from which source |
| plan | `result.method` | `filepatch` or `git` |
| plan | `result.files.{add,update,remove,local_edits,kept_retired,protected,shared}` | counts of the file diff |
| plan | `result.local_edits` | files changed on the server; they are kept aside in the backup and replaced |
| plan | `result.database.mode` | `kernel` (the kernel's `exp:upgrade` stages) or `sql` (the update files listed in `sql_files`) |
| plan | `result.traps[]` | `{id, level: info|warn|block, message, fix}` |
| plan | `result.blocked[]` | reasons the upgrade would be refused; empty when it may run |
| plan | `result.php.{binary,version,reason,web,web_from,skipped}` | the PHP the kernel's tools and Composer run with and why (`--php`, the web server's PHP from `.htaccess`, the Plesk vhost's PHP-FPM pool, Velocity or `.php-version`, else panel PHPs first); `skipped` names each PHP left out (below the platform check, or ruled out by a `composer.lock` constraint) |
| plan | `result.php_extensions.{required,missing,php,ini}` | the PHP extensions the target requires (and by whom), those the chosen PHP lacks with the `php.ini` line for Linux and Windows; a missing one blocks as trap `php-extension-missing` |
| upgrade | `result.ok`, `result.from`, `result.to` | the outcome |
| upgrade | `result.backup.{id,dir}` | the backup to roll back to |
| upgrade | `result.kept_aside[]` | local edits replaced; their copies are in `<backup dir>/kept/` |
| upgrade | `result.rolled_back`, `result.rollback`, `result.error` | after a failure |
| upgrade | `result.rollback_error` | the automatic rollback failed too: **tell a human at once**, give the backup id |

## When xne lacks a step

If an upgrade needs something xne does not do (a new kernel upgrade step, a new trap, a new database engine, a new
install layout), **add it to xne as tested code**, or, when it is about the kernel itself, **add it to the kernel as
a feature** (an `exp:upgrade` step, a console command) that xne then calls. Never a shortcut:

- no one-off shell step "just this once" before or after `xne upgrade`;
- no editing files of an install by hand to get past a blocking trap;
- no patching of kernel code at run time, and nothing written into `settings/override` outside the INI conventions.

A change to xne comes with a test in `tests/` (unit tests in `tests/php/`, real releases in `tests/integration/`),
`make test` must pass, and the integration run too (below). Then the
upgrade is done with the new xne.

## Safety rules

- **The backup is verified before anything changes.** If the backup fails, nothing is changed. Never use an option
  or setting to upgrade without one; there is none, on purpose.
- **Automatic rollback.** Any failure after the backup (files, kernel steps, database, cache clears, health check)
  rolls back files and database at once. Do not interrupt a running xne (no `kill`, no Ctrl-C): let it finish.
- **Nothing is deleted.** Retired files and files a rollback takes back are moved into the backup folder.
- **Local edits are kept aside and listed**, never silently overwritten. Tell the site's owner about every one.
- **One run per install.** A lock refuses a second run (exit 3).
- **Real sites only with a go.** An upgrade of a live site needs the go of the person responsible for it; watch the
  site around it. Run heavy work at quiet times.
- **No Exponential tag or release by xne.** xne installs releases; it never tags, publishes or pushes one, and
  you never do either as part of an upgrade.
- **Never print secrets.** xne never shows the database password or a token; do not read them out of settings
  files to show them either.

## Settings you may meet

Per install, in `settings/override/xne.ini.append.php` (read by xne, written by people):

```ini
[NetworkSettings]
Channel=stable           # stable | beta | main | security
Maintenance=keep         # keep serving (default) | page (503 page, admins can sign in)
BackupKeep=5
HealthURL[]=https://www.example.com/
```

Server-wide: `/etc/xne/xne.conf` (see `etc/xne.conf.example` in the package). Any key also takes
`--set key=value` on the command line or `XNE_<KEY>` in the environment.

## Tests (for changes to xne)

```bash
make test                                   # unit + fake-release end to end, any machine
# real releases and throwaway databases (xne_test_*), never production; one group at a time, under a load gate,
# at the lowest priority (doc/testing.md has the full command):
XNE_IT_DIR=/srv/xne-test XNE_IT_PHP=/usr/bin/php nice -n 19 tests/integration/run-gated.sh <log>
```

Fix the code, never the tests. 100% must pass.

## More

[README](README.md) · [CLI reference](cli.md) · [Upgrade guide](upgrade-guide.md) ·
[Rollback guide](rollback-guide.md) · [Traps](traps.md) · [Troubleshooting](troubleshooting.md) · [Design](design.md)
