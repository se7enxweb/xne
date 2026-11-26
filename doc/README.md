# xne documentation

Last verified: 2026-11-26 (xne 0.3.0.1)

| Document | For | What |
|---|---|---|
| [AI.md](AI.md) | AI assistants | the rule (never script an upgrade by hand; use xne), the commands, reading `--json`, safety rules |
| [cli.md](cli.md) | everyone | every command and option (the exact `xne help` text, checked by a test), exit codes, settings, the `--json` shape |
| [upgrade-guide.md](upgrade-guide.md) | administrators | an upgrade step by step: check, plan, dry run, upgrade, what to do after |
| [multi-install-guide.md](multi-install-guide.md) | administrators | many installs on one server: the overview, planning all, upgrading one at a time, shared folders, per-install settings |
| [windows-guide.md](windows-guide.md) | administrators | Windows: install, PHP, launchers, paths, IIS, Apache (XAMPP, WampServer), scheduled task, known limits |
| [rollback-guide.md](rollback-guide.md) | administrators | going back: automatic rollback, `xne rollback`, by git tag, by hand from a backup |
| [traps.md](traps.md) | administrators | the known upgrade traps xne detects, why each matters and the fix |
| [troubleshooting.md](troubleshooting.md) | administrators | messages and what to do about them |
| [testing.md](testing.md) | developers | the unit tests, the integration tests on throwaway installs, adding a test |
| [engines.md](engines.md) | everyone | the database engines: MySQL/MariaDB, PostgreSQL, SQLite, MongoDB, Oracle; backup, upgrade and rollback of each |
| [server.md](server.md) | operators | phase 2: the API v1, install keys, check, report and notifications, the GitLab release mirror |
| [design.md](design.md) | everyone | the design and the owner's decisions (a copy) |

The package [README](../README.md) has the layout and the phase status.

## How an upgrade runs

```
detect ─▶ plan ─▶ lock ─▶ backup (verified) ─▶ maintenance on? ─▶ files ─▶ kernel exp:upgrade | SQL files
                                                                           │
            ┌──────────────── any failure ◀────────────────────────────────┤
            ▼                                                              ▼
    automatic rollback (files, libraries, database, caches)      autoloads + cache clears ─▶ health ─▶ maintenance off ─▶ rotate
```

- **Files** come from the target release (downloaded once into the release cache) and are written atomically, each
  checked against the release afterwards. Local edits are kept aside in the backup and listed.
- **Database** steps are the kernel's own: `bin/php/upgrade.php` (exp:upgrade) when the target has it, else the
  `update/database/<engine>/` SQL files, chained from the database's version to the target.
- **Nothing is deleted**: retired files and files a rollback takes back are moved into the backup folder.
