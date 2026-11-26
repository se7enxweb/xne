# xne on Windows

Last verified: 2026-11-26 (xne 0.3.0.1)

xne's engine is plain PHP and needs no shell tool, so the commands, their options, their output and their exit
codes are the same on Windows as on Linux. This page covers what is different: installing it, finding PHP, paths,
and the web servers Exponential runs under on Windows (IIS, Apache from XAMPP or WampServer).

Every change to xne is tested on Windows Server 2022 and 2025 in GitHub Actions: the whole unit suite (PHP 8.1 and
8.5, through `xne.bat` and `xne.ps1` too) and real Exponential releases installed with Composer: 6.0.13 -> 6.0.14
planned, dry-run, upgraded and rolled back exactly on an SQLite database, and 6.0.14 -> 6.0.15 planned and dry-run
([testing.md](testing.md)). The 6.0.15 kernel upgrade step itself does not run on Windows yet (a kernel issue in its
autoload step); xne then rolls back by itself, and the install stays on 6.0.14.

## Install

1. **PHP for the command line**, 7.4 or newer; use the PHP the site runs on.

   | Stack | PHP |
   |---|---|
   | XAMPP | `C:\xampp\php\php.exe` |
   | WampServer | `C:\wamp64\bin\php\php8.x.y\php.exe` |
   | IIS (PHP Manager or a zip from windows.php.net) | for example `C:\Program Files\PHP\v8.3\php.exe` |

   In that PHP's `php.ini`, enable `extension=openssl`, `extension=curl` and the PDO driver of the site's database
   (`extension=pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`). zlib is built into the Windows builds of PHP. Check with
   `php -m`.

2. **xne**: `git clone https://github.com/se7enxweb/xne.git C:\xne` (Git for Windows), or download the repository
   archive and unpack it to `C:\xne`.

3. **The command**: add `C:\xne\bin` to `PATH` (System Properties, Environment Variables), then open a new prompt:

   ```bat
   xne version
   ```

   When `php.exe` is not on `PATH`, tell xne where it is, once for your account or for the machine:

   ```bat
   setx XNE_PHP "C:\xampp\php\php.exe"
   setx XNE_PHP "C:\xampp\php\php.exe" /M
   ```

## Which PHP runs the install's tools

xne itself runs on PHP 7.4 or newer. The install's own tools (the kernel's `upgrade.php`, `ezcache.php`, the autoload
generator) need the PHP the install's `vendor/` accepts, at least 8.1. xne picks it in this order: `--php` (or
`XNE_PHP`, `PHP=` in the install's settings), the PHP running xne, `php` on `PATH`, then the PHP of XAMPP
(`C:\xampp\php`), WampServer (`C:\wamp64\bin\php\php*`), Laragon, `%ProgramFiles%\PHP\*`, `C:\php*` and
`C:\tools\php*`. When none fits, the plan is refused with the PHP version it needs; name one with `--php`.

## The launchers

| Launcher | For | What it does |
|---|---|---|
| `bin\xne.bat` | cmd.exe, Task Scheduler, other programs | runs `bin\xne` with `XNE_PHP`, else the `php` on `PATH`; passes every argument and the exit code through |
| `bin\xne.ps1` | PowerShell | the same for PowerShell (`xne` finds `xne.ps1` when `C:\xne\bin` is on `PATH`) |
| `php C:\xne\bin\xne` | anywhere | the same without a launcher, with any PHP you name |

When PowerShell refuses to run scripts, use `xne.bat` from PowerShell too, or
`powershell -ExecutionPolicy Bypass -File C:\xne\bin\xne.ps1 version`.

Exit codes are the same as on Linux: 0 PASS, 1 FAIL, 2 usage, 3 refused. In cmd: `echo %ERRORLEVEL%`; in
PowerShell: `$LASTEXITCODE`.

## Paths

- Write install roots with backslashes or forward slashes; both work: `--root=C:\xampp\htdocs\exponential` or
  `--root=C:/xampp/htdocs/exponential`. Quote a path with spaces: `--root="C:\inetpub\wwwroot\my site"`.
- **In PowerShell, give options with a drive path as two words**, `--root C:\sites\example`, or quote the whole
  option, `'--root=C:\sites\example'`: PowerShell 7 reads `--root=C:\...` as a parameter name and value and splits
  it at the drive colon. cmd.exe and `xne.bat` take either form.
- Drive letters are compared without case: `c:\Sites` and `C:\sites` are the same folder for xne's checks.
- xne keeps its state (backups, locks, install keys) in `%ProgramData%\xne\lib` and the release cache in
  `%ProgramData%\xne\cache`. Change them with `XNE_STATE_DIR` and `XNE_CACHE_DIR`, or `state_dir=` and `cache_dir=`
  in a settings file named by `XNE_CONF`.
- Server settings: there is no `/etc/xne/xne.conf` on Windows; create for example `C:\ProgramData\xne\xne.conf`
  from `etc\xne.conf.example` and `setx XNE_CONF "C:\ProgramData\xne\xne.conf" /M`.
- An absolute SQLite database path in the install's settings may start with a drive (`Database=D:\data\site.db`).

## Who runs xne

On Linux, xne run as root runs the install's own tools as the owner of `index.php`. Windows has no such switch:
xne and the install's tools run as the account that starts xne. Use an account that can write the whole install
and the state folder:

- run the prompt **as Administrator**, or as the account that owns the site's files;
- for IIS, that account must be able to write where the application pool identity (`IIS AppPool\<pool>`) writes,
  because upgraded files keep the folder's permissions;
- `xne detect` shows that account as the install's owner.

## IIS

1. **Visitors during the upgrade.** By default the site keeps serving (files are written one by one, each
   atomically). For a quiet window, use `--maintenance=page` (Exponential 6.0.15 and newer serve the kernel's
   maintenance page, admins can still sign in), or stop the site for the few minutes the upgrade takes:

   ```bat
   %windir%\system32\inetsrv\appcmd stop site /site.name:"Exponential"
   xne upgrade --root=C:\inetpub\exponential
   %windir%\system32\inetsrv\appcmd start site /site.name:"Exponential"
   ```

2. **After the upgrade, recycle the application pool**, so PHP's FastCGI processes drop the old classes from
   OPcache (or WinCache):

   ```bat
   %windir%\system32\inetsrv\appcmd recycle apppool /apppool.name:"Exponential"
   ```

3. `web.config` is a site file that xne never writes; compare it by hand when a release note asks for it.

## Apache on Windows (XAMPP, WampServer)

- xne treats the install like any other; `.htaccess` is protected and never written.
- After the upgrade, restart Apache so mod_php or the FastCGI processes load the new code: the XAMPP or WampServer
  control panel, or `httpd -k restart` in the Apache `bin` folder, or `net stop Apache2.4 && net start Apache2.4`
  when Apache runs as a service.
- XAMPP's MySQL/MariaDB is reached through the install's own `[DatabaseSettings]`, as on Linux.

## Git installs

An install that is a git checkout is upgraded by a git fast-forward. xne runs `git` from `PATH`; install Git for
Windows and check `git --version` in the same prompt. Line endings: keep `core.autocrlf` as the checkout was made
with; a checkout whose files git sees as changed is refused (exit 3) rather than overwritten.

## The daily check as a scheduled task

```bat
schtasks /Create /SC DAILY /ST 05:20 /TN "xne check" /RU SYSTEM ^
  /TR "C:\xne\bin\xne.bat check --root=C:\inetpub\exponential"
```

For several installs, list their roots one per line in a file and run `cronjobs\xne-check-all` with it:

```bat
setx XNE_INSTALLS "C:\ProgramData\xne\installs" /M
schtasks /Create /SC DAILY /ST 05:20 /TN "xne check all" /RU SYSTEM ^
  /TR "\"C:\xampp\php\php.exe\" C:\xne\cronjobs\xne-check-all"
```

`xne-check-all` changes nothing; it prints one line per install (current, upgrade available, blocked, failed).
See [multi-install-guide.md](multi-install-guide.md).

## Known limits on Windows

- **Links.** Release archives and backups can hold links. xne makes a link to a folder as a junction, which every
  account may create (Exponential's design folder aliases, such as `extension/bccie/design/admin2`, are of this kind).
  A link to a file needs Developer Mode or the "Create symbolic links" right; without it the upgrade stops with
  `cannot create the link <path>` and rolls back. Enable Developer Mode, or run xne as Administrator.
- **Long paths.** Deep vendor folders can pass 260 characters. Keep installs and the state folder short
  (`C:\sites\example`, `C:\ProgramData\xne`), or enable long paths (`LongPathsEnabled` in the registry, Windows 10
  1607 and newer).
- **Antivirus.** A scanner that holds a freshly written file open can make a write fail; xne then rolls back. Exclude
  the install and `%ProgramData%\xne` from on-access scanning during the upgrade, or run it again.
- **Phase 4** adds an MSI installer and a Windows guide for non-technical users; until then, install as above.

## Troubleshooting on Windows

| Message | What to do |
|---|---|
| `'php' is not recognized as an internal or external command` | set `XNE_PHP` to the full path of `php.exe`, or add PHP's folder to `PATH` |
| `this PHP ... has no PDO driver "mysql"` | enable `extension=pdo_mysql` in the `php.ini` of the PHP xne runs with (`php --ini` shows which file) |
| `HTTP ... SSL certificate problem` | the PHP has no CA bundle: set `curl.cainfo` and `openssl.cafile` in `php.ini` to a `cacert.pem` (from curl.se) |
| `cannot replace <file>` | another program holds the file open (an editor, the antivirus, a running PHP process); xne rolled back; close it and run again |
| `cannot create the link <path>` | the release has a symbolic link and this account may not create one: enable Developer Mode or run as Administrator; xne rolled back |
| `File cannot be loaded because running scripts is disabled` (PowerShell) | use `xne.bat`, or `-ExecutionPolicy Bypass` as above |

Everything else: [troubleshooting.md](troubleshooting.md).
