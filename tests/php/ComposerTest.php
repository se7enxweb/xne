<?php
declare(strict_types=1);

use Xne\Method\ComposerMerge;

// An install whose libraries Composer manages (vendor/composer/installed.json), as the hosted trees of
// 2026-11-26: the release moved an extension out of the kernel repository into a package, its own
// share/filelist.md5 still listed that extension's files, and composer.json / composer.lock are links into a
// capture's arc/ folder. The file patch must leave Composer's folders alone, composer.json must follow the release
// with the site's extension packages kept, the kernel's libraries stage must get the new and changed packages, and
// a rollback must give back composer.json through its link and move out what the libraries stage installed.

/** Releases 6.0.14 (bundles extension/oldbundled) and 6.0.15 (not), with kernel stand-ins; an install of 6.0.14. */
function t_composer_world(string $name): array
{
    $s = t_scratch($name);
    $rel = $s . '/releases';
    $status = "<?php\necho json_encode(['stages' => [['stage' => 'libraries', 'pending' => 0, 'steps' => []]], 'summary' => ['pending' => 0]]), \"\\n\";\nexit(0);\n";
    $upgrade = <<<'PHP'
<?php
// test stand-in for exp:upgrade: records its arguments; the libraries stage "installs" new/pkg
file_put_contents('vendor/argv.txt', implode("\n", array_slice($argv, 1)) . "\n");
foreach ($argv as $a) {
    if ($a === '--stages=libraries,database,scripts') {
        @mkdir('extension/pkg', 0755, true);
        file_put_contents('extension/pkg/x.php', "<?php // installed by composer\n");
        file_put_contents('composer.lock', str_replace('"packages": [', '"packages": [{"name": "new/pkg", "version": "1.0.0", "type": "ezpublish-legacy-extension"}, ', file_get_contents('composer.lock')));
    }
}
echo json_encode(['summary' => ['applied' => 1], 'stages' => []]), "\n";
exit(0);
PHP;
    $kernel = ['bin/php/upgrade.php' => $upgrade, 'bin/php/upgradestatus.php' => $status];
    $cj14 = json_encode(['name' => 'se7enxweb/exponential', 'require' => ['php' => '>=7.4', 'acme/lib' => '~1.0', 'site/ext' => '~1.0', 'lib/old' => '~1.0']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $cj15 = json_encode(['name' => 'se7enxweb/exponential', 'require' => ['php' => '>=7.4', 'acme/lib' => '~2.0', 'new/pkg' => '~1.0']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    t_fake_release($rel, 'v6.0.14', 'tag', '6.0.14', $kernel + ['composer.json' => $cj14, 'kernel/edited.php' => "<?php // 14\n", 'extension/oldbundled/a.php' => "<?php // bundled in 14\n"]);
    t_fake_release($rel, 'v6.0.15', 'tag', '6.0.15', $kernel + ['composer.json' => $cj15, 'kernel/edited.php' => "<?php // 15\n"]);
    $env = ['XNE_STATE_DIR' => $s . '/state', 'XNE_CACHE_DIR' => $s . '/cache', 'XNE_SOURCES' => 'local', 'XNE_LOCAL_SOURCE' => $rel, 'XNE_CONF' => $s . '/none.conf'];
    $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
    // Composer manages it: oldbundled is a package now; site/ext an extension package, lib/old a library
    \Xne\Fs::atomicWrite($root . '/vendor/composer/installed.json', json_encode(['packages' => [
        ['name' => 'site/oldbundled', 'type' => 'ezpublish-legacy-extension', 'install-path' => '../../extension/oldbundled'],
        ['name' => 'site/ext', 'type' => 'ezpublish-legacy-extension', 'install-path' => '../../extension/ext'],
        ['name' => 'lib/old', 'type' => 'library', 'install-path' => '../lib/old'],
    ]]));
    \Xne\Fs::atomicWrite($root . '/extension/ext/e.php', "<?php // the site's extension package\n");
    // composer.json and composer.lock live in the capture's arc/ folder, linked into the install
    \Xne\Fs::mkdir($s . '/arc');
    rename($root . '/composer.json', $s . '/arc/composer.json');
    symlink($s . '/arc/composer.json', $root . '/composer.json');
    file_put_contents($s . '/arc/composer.lock', json_encode(['packages' => [
        ['name' => 'acme/lib', 'version' => '1.0.0', 'type' => 'library'],
        ['name' => 'site/ext', 'version' => '1.0.0', 'type' => 'ezpublish-legacy-extension'],
        ['name' => 'site/oldbundled', 'version' => '1.0.0', 'type' => 'ezpublish-legacy-extension'],
        ['name' => 'lib/old', 'version' => '1.0.0', 'type' => 'library'],
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    symlink($s . '/arc/composer.lock', $root . '/composer.lock');
    return [$s, $root, $env, $cj14];
}

return [
    'merge: the release composer.json, the site extension packages it dropped kept, other dropped requirements gone' => static function (): void {
        $install = ['name' => 'se7enxweb/exponential', 'require' => ['php' => '>=7.4', 'a/lib' => '~1.0', 'site/ext' => '^2', 'zeta/base' => '~1.9', 'same/x' => '~1']];
        $target = ['name' => 'se7enxweb/exponential', 'require' => ['php' => '>=8.0', 'a/lib' => '~1.2', 'exp/base' => '^1.9', 'same/x' => '~1'], 'require-dev' => ['t/unit' => '^13'], 'autoload' => ['classmap' => ['kernel/']]];
        $locked = [['name' => 'a/lib', 'type' => 'library'], ['name' => 'site/ext', 'type' => 'ezpublish-legacy-extension', 'version' => 'v2.1.3'],
            ['name' => 'zeta/base', 'type' => 'library'], ['name' => 'same/x', 'type' => 'library']];
        $m = ComposerMerge::merge($install, $target, $locked);
        t_same(['php' => '>=8.0', 'a/lib' => '~1.2', 'exp/base' => '^1.9', 'same/x' => '~1', 'site/ext' => '>=2.1.3'], $m['json']['require'], 'a kept extension: its locked version or newer');
        t_same(['classmap' => ['kernel/']], $m['json']['autoload'], 'everything else is the release file');
        t_same(['site/ext' => '>=2.1.3'], $m['kept']);
        t_same(['zeta/base'], $m['dropped']);
        t_same(['exp/base'], $m['added']);
        t_same(['a/lib'], $m['changed']);
        t_same(['a/lib', 'exp/base', 'same/x', 'site/ext', 't/unit'], $m['update'], 'every root requirement, require-dev too');
    },
    'the paths Composer owns: vendor, the composer files, install paths and the extension folders of composer.lock' => static function (): void {
        [, $root] = t_composer_world('cmpaths');
        $p = ComposerMerge::packagePaths($root);
        foreach (['vendor', 'composer.json', 'composer.lock', 'extension/oldbundled', 'extension/ext'] as $x) {
            t_true(in_array($x, $p, true), "$x in " . json_encode($p));
        }
        t_true(!in_array('lib/old', $p, true), 'an install path inside vendor/ is covered by vendor');
        t_true(ComposerMerge::under('extension/oldbundled/a.php', $p) && !ComposerMerge::under('extension/oldbundledx/a.php', $p));
        $plain = t_scratch('cmplain');
        t_same([], ComposerMerge::packagePaths($plain), 'not managed by Composer: nothing');
    },
    'upgrade: Composer folders are not retired, composer.json follows the release through its link, the libraries stage gets the packages' => static function (): void {
        [$s, $root, $env] = t_composer_world('cmup');
        [$x, , $err, $j] = t_xne(['plan', '--root=' . $root, '--json'], $env);
        t_same(0, $x, $err);
        $r = $j['result'];
        t_same(['libraries', 'database', 'scripts'], $r['database']['stages'], json_encode($r['database']));
        t_same(0, $r['files']['remove'], 'nothing of Composer is retired');
        t_true($r['files']['packages'] > 0, json_encode($r['files']));
        t_same(['site/ext' => '>=1.0.0'], $r['composer']['kept']);
        t_same(['lib/old'], $r['composer']['dropped']);
        [$x, $out, $err, $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env + ['XNE_COMPOSER' => $s . '/composer.phar']);
        t_same(0, $x, $out . $err);
        t_true(strpos((string) file_get_contents($root . '/vendor/argv.txt'), '--composer=' . $s . '/composer.phar') !== false, 'the libraries stage gets the Composer phar');
        t_true(is_file($root . '/extension/oldbundled/a.php'), 'the package folder the release no longer bundles is still there');
        t_true(is_link($root . '/composer.json'), 'composer.json is still the link into arc/');
        $cj = json_decode((string) file_get_contents($s . '/arc/composer.json'), true);
        t_same(['php' => '>=7.4', 'acme/lib' => '~2.0', 'new/pkg' => '~1.0', 'site/ext' => '>=1.0.0'], $cj['require']);
        $argv = (string) file_get_contents($root . '/vendor/argv.txt');
        t_true(strpos($argv, '--update=acme/lib,new/pkg,site/ext') !== false, $argv);
        t_true(is_file($root . '/extension/pkg/x.php'), 'the libraries stage ran');
    },
    'a rollback gives composer.json and composer.lock back through their links and moves out what the libraries stage added' => static function (): void {
        [$s, $root, $env, $cj14] = t_composer_world('cmrb');
        $tree = t_tree($root);
        $lock = (string) file_get_contents($s . '/arc/composer.lock');
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--json'], $env + ['XNE_TEST_FAIL_AFTER' => 'database']);
        t_same(1, $x, $err);
        t_true($j['result']['rolled_back'] === true, json_encode($j['result']));
        t_true(is_link($root . '/composer.json') && is_link($root . '/composer.lock'), 'still links');
        t_same($cj14, file_get_contents($s . '/arc/composer.json'), 'composer.json back');
        t_same($lock, file_get_contents($s . '/arc/composer.lock'), 'composer.lock back');
        t_true(!is_file($root . '/extension/pkg/x.php'), 'the installed package is moved out');
        t_same($tree, t_tree($root));
    },
    'the Composer phar for the libraries stage: the setting, else a real phar, never a shell wrapper' => static function (): void {
        $s = t_scratch('cmphar');
        \Xne\Fs::atomicWrite($s . '/a/composer', "#!/bin/bash\nexec /somewhere/composer.phar \"$@\"\n");
        chmod($s . '/a/composer', 0755);
        \Xne\Fs::atomicWrite($s . '/b/composer.phar', "#!/usr/bin/env php\n<?php Phar::mapPhar('composer.phar'); __HALT_COMPILER(); ?>\n");
        \Xne\Fs::atomicWrite($s . '/c/composer.phar', "not a phar\n");
        $K = \Xne\Client\Kernel::class;
        t_same($s . '/b/composer.phar', $K::composerPhar('', $s . '/site', [$s . '/a', $s . '/c', $s . '/b']), 'the wrapper and a fake phar are passed over');
        t_same('/opt/x/composer.phar', $K::composerPhar('/opt/x/composer.phar', $s . '/site', [$s . '/b']), 'the setting wins');
        t_same(null, $K::composerPhar('', $s . '/site', [$s . '/a']), 'only a wrapper: the kernel looks itself');
        \Xne\Fs::atomicWrite($s . '/site/composer.phar', "<?php __HALT_COMPILER();\n");
        t_same($s . '/site/composer.phar', $K::composerPhar('', $s . '/site', [$s . '/a']), "the install's own phar");
    },
];
