# Troubleshooting xne

Last verified: 2026-11-26 (xne 0.3.0.7)

Windows-specific messages (PHP not found, PowerShell scripts disabled, links, files held open):
[windows-guide.md](windows-guide.md#troubleshooting-on-windows).

| Message | Cause | What to do |
|---|---|---|
| `USAGE: no Exponential install at or above <dir>` | `--root` (or the current folder) is not inside an install | give the folder that has `lib/version.php`, `kernel/` and `settings/` |
| `REFUSED: another xne run holds the lock of this install` | an upgrade, backup or rollback of this install is running | wait for it; never remove the lock file of a running xne |
| `REFUSED: the upgrade is refused: trap ...` | a blocking trap | [traps.md](traps.md): fix it, or `--accept=<id>` when the person responsible decided so |
| `... file(s) are in folders shared with another install` | a shared extension folder would be written | `--shared=skip` or `--shared=write`, after deciding which the other clone needs |
| `git: local changes in tracked files: ...` | a git install has uncommitted changes in kernel files | commit them on the site's branch (or stash them) and plan again; or use `--method=filepatch`, which keeps them aside |
| `git: HEAD has commits the target lacks (not a fast-forward)` | the site's branch has its own commits | merge the release into the site's branch by hand (a normal git merge, reviewed), then plan again |
| `php: no PHP on this node meets PHP X (vendor/composer/platform_check.php ...)` | the install's `vendor/` was built for a newer PHP than any xne found (the kernel's tools would die in Composer's platform check after the files changed) | install that PHP, or name one with `--php=`; the plan refuses before any backup |
| `php: the php setting ... is PHP X, the install needs PHP Y` | `--php` (or `php=` in xne.conf) names a PHP below the platform check | name a newer one; xne never swaps it for another silently |
| `<package> requires php >=7.4 <8.6 -> your php version (8.6.0) does not satisfy that requirement` (Composer, libraries stage; xne before 0.3.0.12) | the site's web PHP was not found (a Plesk PHP-FPM site has no `AddHandler` or `.php-version`), so xne took the newest PHP installed, a distribution preview build (`/usr/bin/php86`) | upgrade with 0.3.0.12 or newer: it reads the Plesk vhost's PHP-FPM pool, ranks panel PHPs first and skips a PHP that `composer.lock` rules out; `xne plan` prints the PHP as `php:` with the reason. With an older xne: `--php=<the site's PHP>` |
| `rollback_error: refused to restore ...: its folder leads out of the install` | an xne before 0.3.0.2 wrote a shared folder with `--shared=write` and could not restore through the link | `xne rollback --root=... <id> --shared=write` (every path is checked first; nothing changes on a refusal) |
| files of the install owned by root after a rollback (xne before 0.3.0.2) | the restore wrote files as root; the next run then took root for the owner and ran the kernel's tools as root | give them back to the owner (`chown` the files newer than the rollback); since 0.3.0.2 the backup records the owner and the rollback restores as that user |
| extensions gone after a file patch (before 0.3.0.4): ezwebin, sevenx_themes_simple ... | the install's `share/filelist.md5` listed files of extensions that are Composer packages now; the patch retired them | `xne rollback` to the backup (files and database), then upgrade with 0.3.0.4 or newer: Composer's folders are left to the libraries stage |
| `exp:upgrade failed ... 6.0.15-libraries-update ... An unexpected error has occurred` (xne before 0.3.0.5) | the kernel ran `composer` from the PATH, a wrapper that starts Composer with the server's older CLI PHP (8.0), which resolves another set and fails; the kernel's error path then stops on an older git_manager (`BackupManager::backupPath()`), which hides the message | upgrade with 0.3.0.5 or newer (it passes `--composer=<phar>`), or give `--composer=/usr/local/bin/composer.phar` |
| `this PHP has no PDO driver "pgsql"` | xne runs with a PHP without the database's driver | run xne with a PHP that has it: `/usr/bin/php /usr/local/bin/xne ...` |
| `cannot connect to the ... database` | the settings in `settings/override/site.ini.append.php` do not reach the database from this node | check host, port and socket; for PostgreSQL over a local socket set `Server=/var/run/postgresql` |
| `this PHP has no mongodb extension` / `no oci8 extension` | MongoDB or Oracle with a PHP that lacks the driver | run xne with a PHP that has it: `xne ... --php=/usr/bin/php` |
| `no release source answered` | GitLab not configured, GitHub rate limit or network, Packagist down | `xne sources --refresh`; set `github_token_file` (a 0600 file) for the GitHub API limit; or use a `local_source` folder |
| `HTTP 403 from https://api.github.com/...` | the anonymous GitHub API limit (60 an hour) | set `github_token_file`, or wait; ref lists are cached for `index_ttl` seconds |
| `the release archive ... is damaged` | a download cut short | run the plan again (the damaged extraction is removed by the guard; the tarball is downloaded again when it is missing) |
| `exp:upgrade failed (exit 1): <step>: <message>` | a kernel upgrade step failed; xne rolled back | the message names the step; `php bin/php/upgradestatus.php` on a copy shows its state; fix the cause in the database or the kernel (a kernel fix is a kernel PR), then upgrade again |
| `ezcache.php --clear-tag=... failed` | the install does not start with the new code (a fatal error) | xne rolled back; run `php bin/php/ezcache.php --list-tags` on a copy of the upgraded tree to see the error (often a kernel override, see traps) |
| `health: ... differs from the release` | something changed a file during the upgrade (a deploy, another tool) | xne rolled back; make sure nothing else writes the tree, then upgrade again |
| `health: share/filelist.md5 is not the release's own` | `share/` is protected or shared | check `protect=` and the `shared` files in the plan |
| `xne runs as root but cannot run the install's tools as <user> (no runuser)` | a system without `runuser` | set `run_as=root` (tools get `--allow-root-user`) or run xne as the owner |
| `ROLLBACK FAILED: ... - run xne rollback <id>` | the automatic rollback hit an error (disk full, database gone) | fix that cause, then `xne rollback <id>`; the backup is verified and untouched. If xne itself cannot run: [rollback-guide.md](rollback-guide.md), "By hand" |
| `USAGE: unknown command ...` | a typo, or a command of a newer xne | `xne help` lists the commands of this version |
| trap `unsupported-database`: `the database cannot be reached with the install's settings` | the PHP lacks the PDO driver, or the settings do not reach the database from here | run xne with a PHP that has the driver (`--php=`), check `[DatabaseSettings]`; xne never upgrades without a backup |
| trap `several-databases` | siteaccesses with their own databases | upgrade with the kernel's `exp:upgrade` for now; xne plans one database per install |
| `FAIL     <root>: no such folder` in `xne-check-all` | a root in the installs list that no longer exists | correct `/etc/xne/installs` (or the file named by `XNE_INSTALLS`) |
| a page shows old content after an upgrade | caches outside `ezcache.php`: Valkey view cache, a front cache, Velocity's response cache, PHP-FPM with old classes | see the `warn` traps; reload PHP-FPM/Velocity (`finish=velocity` does it) |

## Where to look

- The result of the last runs: `xne backups` and `backup.json` in each backup folder (`state`, `applied`, `error`,
  `rollback`).
- `--json` output of a command: the whole result as data.
- The kernel's own record: `php bin/php/upgradestatus.php` (6.0.15 and newer), `var/log/upgrade.log`.
