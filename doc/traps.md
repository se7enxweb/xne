# Upgrade traps

Last verified: 2026-11-24 (xne 0.1.0)

Each trap below cost a failed attempt in an upgrade run by hand before xne existed (design §6.1). Each is now a
detector in `classes/Xne/Client/Traps.php`, shown by `xne traps` and in every plan. Levels:

| Level | Effect |
|---|---|
| `info` | shown; nothing to do, or xne handles it |
| `warn` | shown; the upgrade runs, and you act afterwards as the fix says |
| `block` | the upgrade is refused (exit 3) until it is fixed, or accepted with `--accept=<id>` by the person responsible |

| Id | Level | Detects | Why it matters | Fix |
|---|---|---|---|---|
| `public-lib-copy` | block | a folder `lib/` with a `version.php` one or two levels above the install (for example the account's `public_html/lib`) that is not the install's own | PHP resolves the kernel's relative `lib/...` includes from the document root: a stale copy there is loaded instead of the upgraded `lib/`, and the site runs half old, half new | replace the copy with a link to `<root>/lib` (keep the copy aside), or accept it when it belongs to another install |
| `composer-files-entry` | block | an entry of `vendor/composer/autoload_files.php` that resolves outside the install | every tree sharing that `vendor/` loads the other tree's `autoload.php`/`config.php`: a kept old tree or a sandbox runs the live code | regenerate the autoloader inside the install (`composer dump-autoload`) so the entry is the install's own |
| `shared-extension-folder` | info / block | extension folders (or `extension/` itself) that link out of the install, shared with another clone | writing there changes the other clone too, which may still run the old kernel | give the install its own copy, or choose: `--shared=skip` (leave them) or `--shared=write` (change them for every clone). Blocked only when the upgrade would write there |
| `litespeed-htaccess-php` | info / block | an `AddHandler application/x-httpd-(ea-php|lsphp|alt-php)NN` line in the install's or the docroot's `.htaccess` | LiteSpeed (and cPanel EA) take the PHP version from that line, not from the panel; a version below the target's minimum breaks the site after the upgrade | switch the line (and the panel) to a supported PHP; after the upgrade restart the PHP workers (`pkill -x -u <owner> lsphp`, a graceful LiteSpeed restart keeps detached workers). Blocked only when the PHP is too old for the target |
| `clear-id-one-at-a-time` | info | always | `ezcache.php --clear-id=a,b` ignores a comma list | built in: the finish step calls `ezcache.php --clear-tag=<tag>` once per tag |
| `valkey-view-cache` | warn | the `sevenx_valkey_cache` extension, active | it keeps the content view cache and the INI cache in Valkey; `ezcache.php` leaves those keys, so old pages and old settings stay | after the upgrade flush the keys of the install's InstallationHash through the extension, then reload PHP |
| `installation-hash` | info / warn | `InstallationHash` of `sevenx_valkey_cache` (in the extension's own `settings/valkeycache.ini.append.php`) is `auto` or empty | `auto` builds the hash from path and host: a tree swapped in by a rename reads the old tree's INI cache | fix the hash in that file (a word per site) |
| `kernel-overrides` | warn / block | class entries in `var/autoload/ezp_override.php`; block when `EZP_AUTOLOAD_ALLOW_KERNEL_OVERRIDE` is on in `config.php` | the autoload generator maps kernel-class copies from **every** extension folder, active or not; an old copy of a kernel class breaks the upgraded site | check each copy against the target kernel; remove or exclude stale ones (`.autoloadignore`); accept when they are current |
| `live-manifest` | info | always | `share/filelist.md5` must describe the files, or Setup > Upgrade check lists hundreds of "modified" files | built in: the target's own manifest lands with the upgrade, and the health check compares it with the files |
| `database-implementation` | block | `DatabaseImplementation` of the install is not an `ImplementationAlias` of the target kernel's `settings/site.ini` (for example `ezsqlite3` where the kernel knows `sqlite3`) | the kernel falls back to a null database: the site and every kernel upgrade step lose the database | set `DatabaseImplementation` in `settings/override/site.ini.append.php` to one of the listed names |
| `several-databases` | block | more than one distinct database across the siteaccesses | phase 1 backs up and upgrades one database per install | upgrade with the kernel's `exp:upgrade` by hand, or wait for phase 3 |
| `unsupported-database` | block | a database engine xne has no driver for, or a database the install's settings cannot reach | nothing can be backed up | check `[DatabaseSettings]`, and that xne's PHP has the driver (pdo_mysql, pdo_pgsql, pdo_sqlite, mongodb, oci8; `--php` names another PHP) |
| `php-extension-missing` | block | an `ext-*` requirement of the target's `composer.json` `require`, of its `composer.lock` `platform`, or of any package its `composer.lock` installs (never the dev ones) that the PHP the kernel's tools and Composer run with does not load (`php -m`) | Composer refuses the libraries stage half way through the upgrade (Exponential main requires `ext-sockets`), and the new `vendor/` platform check stops every request | add the line the trap names to that PHP's `php.ini` (and to the web server's PHP): Linux `extension=<name>` (install the distribution's package first when the module is missing), Windows `extension=<name>` with `ext\php_<name>.dll` present and `extension_dir = "ext"`; `zend_extension=opcache` for OPcache. Plan again. `result.php_extensions` of `xne plan --json` lists what is required and by whom |

## Adding a trap

1. A detector in `Traps::run()`: an id, a level, the message (what was found, why it matters) and the fix.
2. If it can be fixed safely as part of an upgrade, a fix step in `Plan`/`Apply` instead of a block.
3. A test in `tests/php/` that builds the situation and checks the level and the refusal (exit 3).
4. A row in this table, and the id in `Traps::IDS`.
