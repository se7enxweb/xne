# xne: Exponential Network (design)

xne is a client and server system in the spirit of the old "eZ Network" product. It lets any Exponential install, on
any operating system, check its health, receive notifications and upgrade: from 6.0.14, or in theory any 6.x and
older line, to the latest release or a chosen GitHub version.

- Package: `se7enxweb/xne`, with bin/, classes/, lib/, cronjobs/, doc/, etc/ and tests/.
- Product name shown to users: **Exponential Network**. Code name: **xne**.
- License: **GNU GPL v2 or later** for client and server.

The build status per phase is in [README.md](../README.md).

---

## 1. Decisions

| # | Topic | Decision |
|---|---|---|
| Q1 | Purpose | Built in this order: **(1) upgrade + monitor + notify**, the most feature-rich start; then (3) a managed service (accounts, support); then (2) plain upgrade delivery as a light mode. |
| Q2 | Client form | **Both**: an Exponential extension (admin page + `exp:network:*` console commands) **and** a standalone `xne.phar` rescue/upgrade tool that works when the site is broken. |
| Q3 | Server | **network.se7enx.com**, as an Exponential site with the API and portal, plus a small standalone server mode for self-hosting with the same code. |
| Q4 | Transport | **Both**: a signed HTTPS JSON API for status and control, plus git/tarball downloads for the release files. |
| Q5 | Release source | **Ranked**: a GitLab release mirror, then GitHub (se7enxweb/exponential tags/branches), then Packagist. One setting changes the order. |
| Q6 | Upgrade method | Auto-detected per install. **Focus: verified file patches** (diff against share/filelist.md5, show the changes, apply file by file, keep local edits aside). Also Composer and git fast-forward. |
| Q7 | From versions | **Any 6.x** and **5.x/4.x**, plus 6.0.14+, which ships first. Older lines chain their upgrade scripts. |
| Q8 | DB upgrades | **Run automatically**, always after a backup (Q9). On failure: **auto-rollback** (F3). |
| Q9 | Backups | **Always automatic**: DB dump + changed files before every upgrade; keep the last N. |
| Q10 | OS support | **Linux first**, then pure-PHP CLI on any OS, then **Windows specifics (WAMP/XAMPP/IIS, services)**, finished to be stable and defect-free, because those users are often less technical. |
| Q11 | Auth | **Free + keyed**: anyone can check and download open-source upgrades; an install key unlocks monitoring, notifications and support. |
| Q12 | Data reported | Three levels. **Focus: version + extensions + health** (Exponential, extension, PHP and DB versions, the filelist check, basic health; never content or user data). Version-only and full-support levels too. |
| Q13 | Telemetry | **On for keyed installs only**; anonymous installs send only their version. |
| Q14 | Admin UI | **The admin page (Setup > Network) and the web portal on network.se7enx.com**, after the **CLI is made defect-free first**. |
| Q15 | Notifications | The dashboard notice, email to AdminEmail, webhooks, and **notify hooks**: commands any alerting, chat or ticket tool can plug into. |
| Q16 | Extensions | **Kernel + se7enxweb extensions** (Composer packages and git extensions, each version-checked); third-party extensions are reported only. |
| Q17 | Rollback | **One-command `xne rollback`** (files + DB from the automatic backup, any OS) **and** git revert by tag for git installs. |
| Q18 | Channels | **stable, beta/alpha, GitHub main (nightly)**, plus a **security-only** channel. |
| Q19 | License | GPLv2+ client and server. |
| Q20 | Name | **Exponential Network**, code name xne. |
| F2 | Nightly channel | **Any install, with a warning**, a forced backup and an easy switch back. |
| F3 | DB upgrade failure | **Auto-rollback**: restore DB + files at once, put the site back, report. |
| F4 | Visitors during an upgrade | A **per-install setting**, default **keep serving**; the **maintenance page** (503 + Retry-After, admins can still sign in) is offered as an option. |
| F6 | Windows client | Default **xne.phar + .bat/.ps1 launcher**; an **MSI installer** for less technical users; **Composer** also supported. The method must not matter for the result. |
| F7 | Schedule | Manual checks, a **daily check that notifies**, and **auto-applied security patches** (with backup and rollback). |

---

## 2. Architecture

```
 install (any OS)                                   network server
 ┌──────────────────────────────┐   HTTPS JSON   ┌───────────────────────────────────┐
 │ extension exp_network         │ ─────────────▶ │ network.se7enx.com (Exponential)   │
 │  admin: Setup > Network       │   signed (key) │  API v1: check, report, releases,  │
 │  exp:network:check|upgrade|…  │ ◀───────────── │  advisories, installs, webhooks    │
 │ xne.phar (rescue, any OS)     │                │  portal: account → all its installs│
 │  check|plan|backup|upgrade|   │  downloads     │ self-host mode (same code)         │
 │  rollback|report|channel      │ ◀── git/tar ── │ release index ← GitLab → GitHub →  │
 └──────────────────────────────┘                │                  Packagist         │
                                                  └───────────────────────────────────┘
```

**Client engine** (pure PHP 7.4–8.5, no shell tools required, so it is identical on Linux, Windows, macOS and BSD):
- **detect:** the install root, its version (lib/version.php / composer.lock), the install type (git, Composer or plain files), PHP, the DB engine, the extensions, and the web server.
- **plan:** source ranking → the target release → the file diff against filelist.md5 → the local edits found → the DB upgrade scripts to run (chained for 4.x/5.x) → the extension upgrades. Output as a human summary and `--json`.
- **backup:** DB dump through the install's own DB settings (MySQL, PostgreSQL, SQLite, Oracle, MongoDB) plus a tar/zip of the files that will change; rotation of the last N; verified.
- **apply:** the maintenance mode per setting (F4), files, DB scripts, cache clears, a health check; **auto-rollback on any failure**.
- **rollback:** files + DB from the backup, or a git revert by tag.
- **report:** a version-only, health or full-support report (by key level, Q12/Q13).
- **schedule:** cron, a systemd timer or a Windows scheduled task, for the daily check and the security auto-apply.

**Server** (network.se7enx.com, or self-hosted):
- a release index built from GitLab/GitHub/Packagist; advisories (security notes per version range);
- an install registry (keys per account); reports; notifications (dashboard data, email, webhooks);
- the portal; and a self-host mode (the same code).

---

## 3. Package layout

```
bin/        xne (CLI), xne.phar build script, xne.bat, xne.ps1
classes/    Xne\Client\{Detect,Plan,Backup,Apply,Rollback,Report,Schedule}, Xne\Source\{GitLab,GitHub,Packagist},
            Xne\Method\{FilePatch,Composer,Git}, Xne\Db\{Mysql,Pgsql,Sqlite,Oracle,Mongo}, Xne\Server\{Api,Index,Registry,Notify}
lib/        HTTP client (curl or streams), signing (HMAC/Ed25519), tar/zip, filelist.md5 tools
extension/  exp_network (the Exponential extension: admin pages, console commands, ezinfo.php, extension.xml)
server/     the network server's API entry point and web server snippets
windows/    the MSI build (WiX), the .bat/.ps1 launchers, the scheduled-task setup
cronjobs/   systemd units + cron examples
doc/        user guide, admin guide, Windows guide for non-technical users, API reference, design
etc/        xne.conf (source ranking, channel, schedule, F4 mode), channels, keys (0600, never in git)
tests/      unit + integration (throwaway installs per OS and version)
```

---

## 4. Safety rules

- Never upgrade without a verified backup; never leave a site half-upgraded (auto-rollback).
- Local file edits are kept aside and listed, never silently overwritten.
- Signed API; keys never logged; no content or user data ever sent.
- xne never tags or publishes an Exponential release; it installs them.

---

## 5. Build plan

1. **Phase 1 (Linux, CLI first, defect-free):** detect/plan/backup/apply/rollback for 6.0.14 → the latest 6.x via file patches and git, plus tests on throwaway installs (MySQL, PostgreSQL, SQLite).
2. **Phase 2:** the server API + release index + advisories on network.se7enx.com; client check/report/notify; install keys.
3. **Phase 3:** the admin page + the portal; Composer method; 4.x/5.x chains; channels incl. nightly and security-only; the security auto-apply.
4. **Phase 4:** Windows: the pure-PHP paths proven, the .bat/.ps1 launchers, the MSI, WAMP/XAMPP/IIS specifics, the Windows guide; tests on Windows.

---

## 6. One tool for every upgrade

1. **xne is the one way installs get upgraded.** An upgrade is one command (`xne upgrade` / `exp:network:upgrade`) on
   any OS, instead of many hand-run shell steps. The known traps of real upgrades are built-in checks:
   - a public_html/lib copy;
   - a Composer `files` entry;
   - shared extension folders between clones;
   - LiteSpeed PHP set via .htaccess;
   - `--clear-id` one cache at a time;
   - a Valkey view cache that ezcache misses;
   - the InstallationHash;
   - kernel overrides coming from every extension dir;
   - the live manifest (filelist.md5) written on every update.

   Each becomes a detector and a fix step in `Plan`/`Apply`.
2. **Overlay, don't duplicate.** Where Exponential already has the feature, xne calls it instead of re-implementing it:
   - the kernel's upgrade scripts and `update/` SQL;
   - `bin/php/ezcache.php`, ezpgenerateautoloads and the exp:* console commands (exp:velocity deploy, exp:cache, exp:cluster);
   - the package system;
   - share/filelist.md5 and the admin's upgrade check;
   - Composer.

   It adds only what's missing: source ranking, planning, backup, rollback, reporting and scheduling.
3. **Never offend core design ideals.** xne never patches kernel code at runtime, never bypasses the kernel's own
   upgrade ordering, never writes into settings/override without the INI system's conventions, and never changes how
   a site works outside an upgrade. If something can only be done by bending the core, it goes into the kernel as a
   proper feature, and xne then calls it.
4. **Speed:** a plan must be computed in seconds (filelist.md5 diff, cached release index); downloads are delta
   tarballs where possible; the cache clears are targeted (`--clear-tag`), not a full clear, unless the release notes ask.
