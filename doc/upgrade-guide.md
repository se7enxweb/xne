# Upgrading an Exponential install with xne

Last verified: 2026-11-24 (xne 0.1.0; 6.0.13 → 6.0.14 and 6.0.14 → 6.0.15 on SQLite, MariaDB and PostgreSQL)

This guide takes one install from its version to a newer one. It is the same on every server; only the paths
differ. Everything here is safe to run: the first three steps change nothing, and the upgrade itself makes a
verified backup first and rolls back by itself when anything fails.

## Before you start

- You know the install's root (the folder with `lib/version.php`, `kernel/` and `settings/`).
- You run xne as root (it runs the install's own tools as the owner of `index.php`), or as the owner.
- The PHP that runs xne has the PDO driver of the install's database (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`).
  The install's own tools run with `php=` (default: this PHP when it is 8.1 or newer).
- On a site that people use, you have the go of the site's owner, and a time when a short slowdown is acceptable.

## 1. What is the install?

```bash
xne detect --root=/var/www/vhosts/example.com/doc/www.example.com
```

Check the version, the install type (`git` installs are fast-forwarded, plain `files` installs are file-patched),
the database line (it must be reachable: `ezsite_data` shows its version) and the kernel tools.

## 2. Traps

```bash
xne traps --root=...
```

A `block` line refuses the upgrade until it is fixed (or accepted with `--accept=<id>` by the person responsible).
A `warn` line needs an action after the upgrade (for example flushing the Valkey view cache). [traps.md](traps.md)
explains each.

## 3. The plan

```bash
xne plan --root=...                       # stable channel: the newest stable release of the same major
xne plan --root=... --channel=security    # only the newest release of the same x.y line
xne plan --root=... --to=v6.0.15          # an exact release
xne plan --root=... --channel=main        # the nightly branch (warns; a backup is always made anyway)
```

Read:

- **files**: how many are added, updated and retired; **local edits**: files changed on this server. Each one is
  copied into the backup's `kept/` folder and then replaced with the release's version. Plan the merge of each with
  the site's owner (a template change, a hot fix) before you upgrade, or right after it.
- **protected**: files the release has that xne never writes (`settings/override`, `settings/siteaccess`, `var`,
  `config.php`, `.htaccess` ...). Compare them with the release by hand when a release note asks for it.
- **Composer's** (since 0.3.0.4): in an install whose libraries Composer manages (`vendor/composer/installed.json`),
  files in `vendor/`, in every package's install path and in the extension folders `composer.lock` names, and
  `composer.json` / `composer.lock` themselves, are never written or retired by the file patch. Releases moved
  extensions out of the kernel repository into packages, and an install's own `share/filelist.md5` still lists
  their files: before 0.3.0.4 those were retired, and the site lost its extensions (a hosted install, 2026-11-26).
- **composer.json**: in such an install it follows the release's file, merged: the release's file, plus the
  site's extension packages the release no longer requires, at `>=` their locked version (a release that drops an
  extension never uninstalls one the site uses, and the release's own packages, exponential-php-floor above all,
  may need a newer one); other requirements the release dropped go (libraries it replaced).
  The plan lists the new packages, the changed constraints, the site extensions kept and what was dropped. A
  `composer.json` that is a link into a capture's `arc/` folder is written through the link.
- **database**: `kernel` means the kernel's own `exp:upgrade` runs (its stages are listed); `sql` lists the
  `update/database/<engine>/` files that run, in order. When composer.json changes, the `libraries` stage runs
  first, with `--update=` every root requirement (the release's set is resolved as a whole; a partial update keeps
  locked versions the release's packages no longer accept); vendor/,
  the composer files and the package folders, also the ones about to be installed, are in the backup, and a rollback
  gives them back (a composer file behind a link through that link) and moves out what was installed.
- **blocked**: anything here refuses the upgrade.

## 4. The dry run

```bash
xne upgrade --root=... --dry-run
```

The same plan, through the upgrade command; nothing changes.

## 5. The upgrade

```bash
xne upgrade --root=...
```

It prints each step as it goes (`.. backup: archiving`, `.. files: writing`, `.. kernel: exp:upgrade`, ...) and
ends with:

- `PASS: upgraded 6.0.14 -> 6.0.15 (backup 20261124-031502-a1b2)`: done. Note the backup id.
- `FAIL: <reason>; rolled back to backup <id>`: nothing of the upgrade is left; the install runs as before. Read
  the reason ([troubleshooting.md](troubleshooting.md)), fix the cause, plan again.
- `REFUSED: ...`: a safety check stopped it before anything changed.

Visitors: by default the site keeps serving during the upgrade (owner decision F4). With `maintenance=page` (server
setting or the install's `Maintenance=page`) visitors get the kernel's maintenance page (503 with Retry-After) while
admins can still sign in; it needs `bin/php/maintenance.php` (Exponential 6.0.15 or newer) in the install or in the
target.

## 6. After the upgrade

1. Open the site and the admin, sign in, look at a few pages.
2. Merge the local edits listed as `kept aside` (copies in `<backup dir>/kept/`).
3. Act on every `warn` trap (Valkey cache flush, PHP worker restart on LiteSpeed ...).
4. When the web server keeps PHP processes with the old classes (PHP-FPM, Velocity, LiteSpeed), reload them. On
   installs that ask for it (`finish=velocity`), xne runs `exp:velocity deploy` itself.
5. Setup > Upgrade check in the admin should show no modified kernel file (the manifest landed with the release).

## Upgrading several installs

Plan all of them first (`xne plan --json` per install; `cronjobs/xne-check-all` prints one line per install of
`/etc/xne/installs`), then upgrade one at a time. Each install has its own lock and its own backups. The whole
procedure, with shared folders and per-install settings: [multi-install-guide.md](multi-install-guide.md). On
Windows: [windows-guide.md](windows-guide.md).

## Older lines (4.x, 5.x)

Phase 1 upgrades within 6.x; xne detects older installs and chains the SQL update files, but the 4.x/5.x upgrade
chains are completed and tested in phase 3. Until then, plan only.
