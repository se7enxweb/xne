# Testing xne

Last verified: 2026-11-26 (xne 0.3.0.1)

The tests are plain PHP (no PHPUnit): `tests/run.php` runs every `tests/php/*Test.php` and, when `XNE_IT_DIR` is
set, every `tests/integration/*Test.php`. Each file returns a map "test name" => callable; a test passes when it
returns without throwing. `php tests/run.php <filter>` runs the tests whose "Suite: name" contains the filter.
Exit 0 only when every test passes. The rule: **fix the code, never the tests.**

## Unit and fake-release tests (any node, no network)

```bash
make test                                # = php tests/run.php
```

| Suite | Covers |
|---|---|
| `LauncherTest` | the launchers start xne, pass the arguments through (also a path with spaces) and return its exit code |
| `DocsTest` | doc/cli.md holds the exact `xne help` text of every command and explains every option |
| `CoreTest` | versions (6.0.4.10 after 6.0.4.9, pre-releases), the INI reader, filelist.md5, settings precedence, the option parser, `php -l` of every file and a PHP 7.4 syntax guard |
| `TarTest` | the tar.gz writer and reader (long names, links, binary, empty files), damaged archives |
| `DbTest` | the SQL splitter, database settings with siteaccesses, the SQLite backup/verify/restore round trip, refusing Oracle/MongoDB |
| `PlanTest` | the file diff (add, update, local edit, retire, kept, protected, shared), channels, the SQL file chain |
| `LibrariesTest` | a fake kernel (upgrade.php and upgradestatus.php stand-ins with the kernel's stage order rule) reporting its libraries stage pending with an unchanged composer.lock: the plan puts libraries before database, the upgrade runs it, `xne rollback` and an automatic rollback give back files, vendor/ and database exactly, and `libraries=skip` leaves it out |
| `GuardTest` | **the safety grep**: no `unlink`, `rmdir`, shell or `rm` anywhere except the guarded functions of `Guard.php`; `rename` only for atomic writes; the guard's refusals |
| `FlowTest` | end to end through `bin/xne` on fake releases (a `local_source` folder) and SQLite installs: plan `--json`, dry run changes nothing, upgrade with a local edit kept aside, rollback exact (files and database fingerprints), a failing SQL update file rolled back, forced failures after files/finish/health rolled back, a failing kernel tool, a blocking trap (exit 3) and `--accept`, channels, rotation, `--json` of every command, and PHP 7.4 when `/opt/plesk/php/7.4` exists |

## Continuous integration (GitHub Actions)

`.github/workflows/ci.yml` runs on every push and pull request:

| Job | Runner | What |
|---|---|---|
| unit | ubuntu-24.04 with PHP 7.4 and 8.5, windows-2022 with PHP 8.1, windows-2025 with PHP 8.5 | `php -l` of every file, the whole unit suite (`php tests/run.php`), and on Windows `xne.bat` and `xne.ps1` started by hand |
| real release | windows-2022 and windows-2025, PHP 8.5 | the integration fixture on Windows: Exponential v6.0.13 and v6.0.14 from GitHub, `composer install`, SQLite installs; 6.0.13 -> 6.0.14 planned, dry-run, upgraded through the SQL path and rolled back exactly (files and database fingerprints); 6.0.14 -> main (6.0.15) planned and dry-run. The full 6.0.14 -> main upgrade on Windows waits for a kernel fix (below) |

**Known kernel issue on Windows (2026-11-26):** the kernel's `exp:upgrade` of 6.0.14 -> main fails on Windows in its
autoload step, which loads the test files of an extension (`extension/ezmbpaex/tests/...`) that its exclusions skip on
Linux. xne rolls the install back exactly, as it must; the fix belongs in the kernel's autoload generator.

`LauncherTest` runs on every platform: on Linux `bin/xne`, on Windows `bin\xne.bat` (cmd) and `bin\xne.ps1`
(Windows PowerShell and PowerShell 7), each with an install path that holds spaces, `--json`, and a usage error that
must come back as exit 2. `DocsTest` keeps [cli.md](cli.md) equal to `xne help`.

## Integration tests (a test machine)

Real releases from GitHub on throwaway installs, with throwaway databases on local test instances: an SQLite file,
MariaDB on 127.0.0.1:3306, PostgreSQL 16 on the 5432 socket. Database names are always `xne_test_<run>_<name>`; the
fixture refuses any other name. Never a production database.

```bash
cd <the xne package>
# one test group at a time, each only while load1 is under 26 (XNE_IT_GATE), at the lowest CPU and I/O priority:
systemd-run --unit=xne-it --collect -p Nice=19 -p IOSchedulingClass=idle \
  --setenv=XNE_IT_DIR=/srv/xne-test --setenv=XNE_IT_PHP=/usr/bin/php --setenv=XNE_IT_RUN=r1 \
  --working-directory="$PWD" tests/integration/run-gated.sh /srv/xne-test/log-r1.txt
grep -E '^(PASS|FAIL|GATED)' /srv/xne-test/log-r1.txt   # last line: GATED RUN PASS n/n
```

`XNE_IT_WAIT_CMD=<command>` replaces the built-in load gate with a command that returns once the machine may run a
test. Sites on the same machine come first: never run the suite without a gate, and never two runs at once.

| Test | Proves |
|---|---|
| seeds | v6.0.14 and v6.0.13 plain-file seeds (release tarball + `composer install`), a v6.0.14 git seed (blobless clone, main's blobs fetched) |
| sqlite | plan `--json`, dry run unchanged, 6.0.14 → main (6.0.15) by file patch through the kernel's exp:upgrade, a local edit kept aside, rollback exact |
| mysql | 6.0.14 → main by file patch on MariaDB, rollback exact |
| mysql failure | a real failing kernel database step (6.0.15-review-hold, with the eng-GB language renamed) makes exp:upgrade fail; files and database are rolled back exactly |
| postgresql | 6.0.14 → main on PostgreSQL, rollback exact; a forced failure after the database step rolled back in one transaction |
| git | 6.0.14 → main by git fast-forward, `xne rollback` back to the commit, `--git-tag` rollback; local changes refuse (exit 3) |
| stable | 6.0.13 → 6.0.14 on the stable channel through the SQL path, rollback exact |

The layout under `XNE_IT_DIR`: `seeds/` (built once), `cache/` (the release store, shared), `installs/<run>/`,
`state/<run>-<name>/` (each test's backups), `secrets/` (0700: the throwaway MariaDB user's password). Installs and
databases of a run stay for inspection; remove them when you are done.
