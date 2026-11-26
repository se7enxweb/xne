<?php
declare(strict_types=1);

use Xne\Client\Defaults;
use Xne\IniEdit;

// xne defaults: an older install's settings brought to a fresh install's (hosted trees, 2026-11-26: default
// extensions not active, an admin siteaccess on a design that no longer exists, var/ a link into arc/ and every cache
// delete refused). The site's own settings stay; a rollback gives the settings files and the database back.

/** An install (6.0.14 fake) with an older admin and var/ in arc/, and a fresh reference install. */
function t_defaults_world(string $name): array
{
    [$s, $rel, $env] = t_world($name);
    $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
    \Xne\Fs::atomicWrite($root . '/settings/override/site.ini.append.php', "<?php /* #?ini charset=\"utf-8\"?\n\n[DatabaseSettings]\nDatabaseImplementation=ezsqlite3\nDatabase=test.db\n\n[ExtensionSettings]\n# the site's list\nActiveExtensions[]\nActiveExtensions[]=ezjscore\nActiveExtensions[]=vo\nActiveExtensions[]=ezjscore\n\n[SiteAccessSettings]\nAvailableSiteAccessList[]\nAvailableSiteAccessList[]=site\nAvailableSiteAccessList[]=edit\n*/ ?>\n");
    \Xne\Fs::atomicWrite($root . '/settings/siteaccess/edit/site.ini.append.php', "<?php /* #?ini charset=\"utf-8\"?\n[SiteSettings]\nSiteName=Edit\n\n[DesignSettings]\nSiteDesign=sevenx_site_admin\nAdditionalSiteDesignList[]=admin3\nAdditionalSiteDesignList[]=admin\n\n[RegionalSettings]\nLocale=eng-US\n*/ ?>\n");
    \Xne\Fs::atomicWrite($root . '/settings/siteaccess/site/site.ini.append.php', "<?php /* #?ini charset=\"utf-8\"?\n[DesignSettings]\nSiteDesign=hire\nAdditionalSiteDesignList[]=simple\n*/ ?>\n");
    foreach (['ezjscore', 'vo', 'expui', 'eztags', 'exp_adminui'] as $e) {
        \Xne\Fs::atomicWrite($root . '/extension/' . $e . '/ezinfo.php', "<?php\n");
    }
    \Xne\Fs::mkdir($s . '/arc');
    rename($root . '/var', $s . '/arc/var');
    symlink($s . '/arc/var', $root . '/var');
    $ref = t_install_from($rel, 'v6.0.14', $s . '/fresh', '6.0.14');
    \Xne\Fs::atomicWrite($ref . '/settings/override/site.ini.append.php', "<?php /*\n[ExtensionSettings]\nActiveExtensions[]\nActiveExtensions[]=ezjscore\nActiveExtensions[]=expui\nActiveExtensions[]=eztags\nActiveExtensions[]=refsite\n[SiteAccessSettings]\nAvailableSiteAccessList[]\nAvailableSiteAccessList[]=site\nAvailableSiteAccessList[]=admin\n*/ ?>\n");
    \Xne\Fs::atomicWrite($ref . '/settings/siteaccess/admin/site.ini.append.php', "<?php /*\n[ExtensionSettings]\nActiveAccessExtensions[]=exp_adminui\nActiveAccessExtensions[]=sqliimport\n[DesignSettings]\nSiteDesign=admin4l\nAdditionalSiteDesignList[]=admin4\nAdditionalSiteDesignList[]=admin3\nAdditionalSiteDesignList[]=admin2\nAdditionalSiteDesignList[]=admin\n*/ ?>\n");
    \Xne\Fs::atomicWrite($ref . '/settings/siteaccess/site/site.ini.append.php', "<?php /*\n[DesignSettings]\nSiteDesign=refdesign\n*/ ?>\n");
    return [$s, $root, $ref, $env];
}

return [
    'IniEdit: a list replaced where it was, comments and other lines kept; a missing section added inside the PHP wrapper' => static function (): void {
        $t = "<?php /*\n[A]\n# note\nK[]\nK[]=a\nX=1\nK[]=b\n[B]\nK[]=other\n[A]\nK[]=c\n*/ ?>\n";
        t_same("<?php /*\n[A]\n# note\nK[]\nK[]=x\nK[]=y\nX=1\n[B]\nK[]=other\n[A]\n*/ ?>\n", IniEdit::setArray($t, 'A', 'K', ['x', 'y']));
        t_same("<?php /*\n[A]\n# note\nK[]\nK[]=a\nX=2\nK[]=b\n[B]\nK[]=other\n[A]\nK[]=c\n*/ ?>\n", IniEdit::setValue($t, 'A', 'X', '2'));
        t_same("<?php /*\n[B]\nY=1\nK[]\nK[]=z\n\n*/ ?>\n", IniEdit::setArray("<?php /*\n[B]\nY=1\n\n*/ ?>\n", 'B', 'K', ['z']), 'at the end of the section');
        t_same("<?php /*\n[B]\nY=1\n\n[C]\nZ=3\n\n*/ ?>\n", IniEdit::setValue("<?php /*\n[B]\nY=1\n*/ ?>\n", 'C', 'Z', '3'), 'a new section before the wrapper end');
        t_same("[B]\nY=1\nK[]=keyed\n", IniEdit::setValue("[B]\nY=0\nK[]=keyed\n", 'B', 'Y', '1'), 'a plain .ini file');
    },
    'plan: the reference extensions the install has are added after its own; admin4l and access extensions on the admin; AllowedDeletionDirs' => static function (): void {
        [$s, $root, $ref] = t_defaults_world('defplan');
        $p = Defaults::plan($root, $ref);
        t_same(['expui', 'eztags'], $p['summary']['extensions_added'], 'refsite is not in the install');
        t_same(['edit'], array_keys($p['summary']['admin_siteaccesses']), 'site is no admin');
        t_same(['exp_adminui'], $p['summary']['admin_siteaccesses']['edit']['access_extensions_added'], 'sqliimport is not in the install');
        t_same([realpath($s . '/arc/var')], $p['summary']['allowed_deletion_dirs']);
        $over = Defaults::edit((string) file_get_contents($root . '/settings/override/site.ini.append.php'), $p['files']['settings/override/site.ini.append.php']);
        t_true(strpos($over, "# the site's list\nActiveExtensions[]\nActiveExtensions[]=ezjscore\nActiveExtensions[]=vo\nActiveExtensions[]=expui\nActiveExtensions[]=eztags\n") !== false, $over);
        t_true(strpos($over, 'AllowedDeletionDirs[]=' . realpath($s . '/arc/var')) !== false, $over);
        $edit = Defaults::edit((string) file_get_contents($root . '/settings/siteaccess/edit/site.ini.append.php'), $p['files']['settings/siteaccess/edit/site.ini.append.php']);
        t_true(strpos($edit, "SiteDesign=admin4l\nAdditionalSiteDesignList[]\nAdditionalSiteDesignList[]=admin4\nAdditionalSiteDesignList[]=admin3\nAdditionalSiteDesignList[]=admin2\nAdditionalSiteDesignList[]=admin\n") !== false, $edit);
        t_true(strpos($edit, "ActiveAccessExtensions[]=exp_adminui") !== false && strpos($edit, "SiteName=Edit") !== false, $edit);
    },
    'xne defaults applies with a backup, a second run has nothing to do, and xne rollback gives the settings back' => static function (): void {
        [$s, $root, $ref, $env] = t_defaults_world('defapply');
        $tree = t_tree($root);
        [$x, $out, $err, $j] = t_xne(['defaults', '--root=' . $root, '--reference=' . $ref, '--json'], $env);
        t_same(0, $x, $out . $err);
        t_true($j['result']['result']['ok'] === true, json_encode($j['result']['result']));
        t_true(strpos((string) file_get_contents($root . '/settings/siteaccess/edit/site.ini.append.php'), 'SiteDesign=admin4l') !== false);
        [$x, , , $j2] = t_xne(['defaults', '--root=' . $root, '--reference=' . $ref, '--json'], $env);
        t_same(0, $x);
        t_true($j2['result']['up_to_date'] === true, json_encode($j2['result']['summary']));
        [$x, , $err] = t_xne(['rollback', '--root=' . $root, '--json'], $env);
        t_same(0, $x, $err);
        t_same($tree, t_tree($root));
    },
    'xne defaults rolls back by itself when a kernel step fails' => static function (): void {
        [$s, $root, $ref, $env] = t_defaults_world('deffail');
        $tree = t_tree($root);
        [$x, , , $j] = t_xne(['defaults', '--root=' . $root, '--reference=' . $ref, '--json'], $env + ['XNE_TEST_EZCACHE_FAIL' => '1']);
        t_same(1, $x);
        t_true($j['result']['result']['rolled_back'] === true, json_encode($j['result']['result']));
        t_same($tree, t_tree($root));
    },
    'xne defaults without --reference is a usage error' => static function (): void {
        [, $root, , $env] = t_defaults_world('defusage');
        [$x] = t_xne(['defaults', '--root=' . $root, '--json'], $env);
        t_same(2, $x);
    },
    'the INI cache is cleared before the kernel database stage; settings already there but tables pending: the stage runs' => static function (): void {
        [$s, $root, $ref, $env] = t_defaults_world('defdb');
        // kernel stand-ins: the database stage makes the tables only when the INI cache was cleared before it
        \Xne\Fs::atomicWrite($root . '/bin/php/ezcache.php', "<?php\nfile_put_contents('var/calls.txt', 'ezcache ' . implode(' ', array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\nif (in_array('--clear-tag=ini', \$argv, true)) { file_put_contents('var/ini-fresh', '1'); }\nexit(0);\n");
        \Xne\Fs::atomicWrite($root . '/bin/php/upgrade.php', "<?php\nfile_put_contents('var/calls.txt', \"upgrade\\n\", FILE_APPEND);\nif (is_file('var/ini-fresh')) { file_put_contents('var/tables', '1'); }\necho json_encode(['summary' => ['applied' => 1]]), \"\\n\";\nexit(0);\n");
        \Xne\Fs::atomicWrite($root . '/bin/php/upgradestatus.php', "<?php\n\$p = is_file('var/tables') ? 0 : 1;\necho json_encode(['stages' => [['stage' => 'database', 'pending' => \$p, 'steps' => [['id' => '6.0.15-active-extension-tables', 'state' => \$p ? 'pending' : 'present']]]]]), \"\\n\";\nexit(0);\n");
        [$x, $out, $err, $j] = t_xne(['defaults', '--root=' . $root, '--reference=' . $ref, '--json'], $env);
        t_same(0, $x, $out . $err . json_encode($j['result']['result'] ?? null));
        $calls = (string) file_get_contents($root . '/var/calls.txt');
        t_true(strpos($calls, "ezcache --clear-tag=ini") < strpos($calls, "upgrade"), $calls);
        t_true(is_file($root . '/var/tables'), 'the tables were made');
        // a run before the fix left the settings but not the tables: the next run does the database stage
        unlink($root . '/var/tables');
        [$x, $out, $err, $j] = t_xne(['defaults', '--root=' . $root, '--reference=' . $ref, '--json'], $env);
        t_same(0, $x, $out . $err);
        t_same(1, $j['result']['database_pending'] ?? null, json_encode($j['result']));
        t_true(is_file($root . '/var/tables'));
    },
    'a database step still pending after the stage fails the run and rolls back' => static function (): void {
        [$s, $root, $ref, $env] = t_defaults_world('defpend');
        \Xne\Fs::atomicWrite($root . '/bin/php/upgrade.php', "<?php\necho json_encode(['summary' => ['applied' => 0]]), \"\\n\";\nexit(0);\n");
        \Xne\Fs::atomicWrite($root . '/bin/php/upgradestatus.php', "<?php\necho json_encode(['stages' => [['stage' => 'database', 'pending' => 1, 'steps' => [['id' => 'x', 'state' => 'pending']]]]]), \"\\n\";\nexit(0);\n");
        $tree = t_tree($root);
        [$x, , , $j] = t_xne(['defaults', '--root=' . $root, '--reference=' . $ref, '--json'], $env);
        t_same(1, $x);
        t_true(strpos((string) $j['result']['result']['error'], 'pending database') !== false, json_encode($j['result']['result']));
        t_same($tree, t_tree($root));
    },
];
