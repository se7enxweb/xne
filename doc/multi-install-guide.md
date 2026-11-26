# Many installs on one server

Last verified: 2026-11-26 (xne 0.3.0.1)

xne works on one install per command. A server that hosts many Exponential installs is handled by running it once per
install, which keeps every install's lock, backups and result separate. This guide shows how to keep an overview,
plan everything first, and upgrade one install after the other.

## Each install is on its own

- **State per install.** Backups, the lock and the install key live in `<state_dir>/installs/<install>/`. A
  rollback of one install never touches another.
- **One run per install.** A second xne run on the same install is refused (exit 3) while the first holds the lock;
  runs on different installs do not block each other. Run them one after the other anyway (below).
- **The release cache is shared.** A release is downloaded once into `cache_dir` and used by every install that
  needs it.
- **Settings per install.** Server-wide settings are in `/etc/xne/xne.conf`; one install can choose differently in
  its own `settings/override/xne.ini.append.php`:

```ini
<?php /* #?ini charset="utf-8"?
[NetworkSettings]
Channel=security
Maintenance=page
BackupKeep=10
PHP=/usr/bin/php8.3
HealthURL[]=https://www.example.com/
HealthURL[]=https://admin.example.com/user/login
*/ ?>
```

The keys: `Channel`, `Method`, `Maintenance`, `MaintenanceUntil`, `BackupKeep`, `Finish`, `DatabaseUpgrade`,
`Libraries`, `HealthURL[]`, `Sources`, `RunAs`, `PHP`, `CacheTags`, `NetworkURL`, `ReportLevel`, `Notify`. Order of
precedence: built-in defaults, the package's `etc/xne.conf`, `/etc/xne/xne.conf` (or `XNE_CONF`), the install's
`[NetworkSettings]`, the `XNE_*` environment, the command line. `xne config --root=...` shows the result and where
each value came from.

## 1. List the installs

Write one install root per line into `/etc/xne/installs` (`#` starts a comment). On Windows, or for another file,
name it with `XNE_INSTALLS`.

```text
# /etc/xne/installs
/var/www/example.com
/var/www/shop.example.com
/srv/intranet/exponential
```

On a hosting server where each site lives at `/var/www/vhosts/<domain>/...`, `--domain=www.example.com` finds the
install of a domain instead of `--root` (it looks in `doc/<domain>`, `httpdocs` and `public_html`).

## 2. The overview

```bash
php /opt/xne/cronjobs/xne-check-all
```

It runs `xne plan --json` (and `xne check`) for each listed install and prints one line each; it changes nothing:

```text
CURRENT  /var/www/example.com: 6.0.14
UPGRADE  /var/www/shop.example.com: 6.0.13 -> 6.0.14
BLOCKED  /srv/intranet/exponential: 6.0.13 -> 6.0.14
FAIL     /var/www/old.example.com: no such folder: /var/www/old.example.com
```

Exit 1 when a plan failed. Run it daily from cron (`20 5 * * * php /opt/xne/cronjobs/xne-check-all`), from the
systemd timer in `cronjobs/` (`xne-check.timer`), or on Windows from a scheduled task
([windows-guide.md](windows-guide.md)).

## 3. Plan every install first

For each `UPGRADE` or `BLOCKED` line, read the whole plan before anything is upgraded:

```bash
xne traps --root=/var/www/shop.example.com
xne plan  --root=/var/www/shop.example.com --json > shop-plan.json
```

Fix every `block` trap, decide about every local edit, and note which installs share folders (next section).

## 4. Upgrade one at a time

```bash
for root in /var/www/shop.example.com /srv/intranet/exponential; do
  xne upgrade --root="$root" --json > "upgrade-$(basename "$root").json" || { echo "stopped at $root"; break; }
done
```

Stop at the first failure. A failed upgrade has already rolled itself back (`rolled_back: true`); look at it
before you go on, because the next install often has the same cause. Do not run upgrades of several installs in
parallel: each one runs the kernel's tools, rebuilds autoloads and clears caches, and on a busy server that is load
the sites feel.

## Installs that share folders

Some servers run several installs from one set of files: a second host whose `extension/` or `vendor/` is a link to
the first install's folder, or a clone that shares extension folders. xne finds such folders (they lead out of the
install) and refuses to write them by accident: the plan is blocked with `... file(s) are in folders shared with
another install`. Decide per case:

- `--shared=skip`: leave the shared folders alone (the install they belong to is upgraded on its own);
- `--shared=write`: write through the link, when this install is the one that owns them.

Upgrade the install that owns the shared folders first, then the ones that link to it with `--shared=skip`.

## Different PHP versions

When installs need different PHP binaries, set `PHP=` in each install's `[NetworkSettings]`, or pass `--php=` on the
command line. xne then runs itself and the install's tools under that PHP, so the database driver and the
install's PHP version requirements match.

## Backups across installs

Each install keeps its last `backup_keep` backups (default 5). `xne backups --root=...` lists them per install;
`xne verify-backup --root=... <id>` reads one back. Plan disk space for the database dump and the changed files of
every install you upgrade in one session.
