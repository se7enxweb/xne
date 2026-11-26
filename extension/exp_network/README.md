# exp_network (skeleton, phase 3)

The Exponential extension of Exponential Network (xne). Phase 1 ships the CLI engine in the xne package; this
extension is the place for what an install shows and runs itself, built in phase 3 on the same engine:

| Part | Planned |
|---|---|
| `settings/xne.ini.append.php` | the per-install defaults ([NetworkSettings]); an install overrides them in settings/override/xne.ini.append.php, which xne already reads |
| `bin/php/network.php` | **in place (phase 2)**: `php extension/exp_network/bin/php/network.php check|plan|upgrade|rollback ...` (also `./bin/php/console ext:exp_network:network ...`) runs xne for this install; `exp:network:*` names need a kernel console alias (a kernel feature, phase 3) |
| `modules/network` + `design/admin4/templates/network` | Setup > Network in every admin design, admin4 first (owner Q14) |
| `ezinfo.php`, `extension.xml` | name, version, licence (GPL v2 or later), info URL |

Until then, run the CLI: `xne plan`, `xne upgrade`, `xne rollback` (see ../../doc/cli.md).
