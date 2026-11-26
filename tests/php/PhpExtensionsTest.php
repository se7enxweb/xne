<?php
declare(strict_types=1);

use Xne\Client\PhpExtensions;
use Xne\Client\Plan;

// The PHP extensions preflight: what the target release requires (composer.json and every package of its
// composer.lock, never the dev ones), what a PHP loads, the php.ini lines for Linux and Windows, and the plan that
// blocks before anything changes (Exponential main requires ext-sockets; Composer refused it half way, 0.3.0.9 CI).
return [
    'required extensions: composer.json require, lock packages and platform; never require-dev or packages-dev' => static function (): void {
        $t = t_scratch('ext-req');
        \Xne\Fs::writeJson($t . '/composer.json', ['require' => ['php' => '>=8.1', 'ext-sockets' => '*', 'ext-JSON' => '*', 'vendor/a' => '^1'],
            'require-dev' => ['ext-xdebug' => '*']], 0644);
        \Xne\Fs::writeJson($t . '/composer.lock', [
            'packages' => [
                ['name' => 'vendor/a', 'require' => ['php' => '>=7.4', 'ext-intl' => '*', 'ext-sockets' => '*']],
                ['name' => 'vendor/b', 'require' => ['ext-mbstring' => '*']],
            ],
            'packages-dev' => [['name' => 'vendor/dev', 'require' => ['ext-pcov' => '*']]],
            'platform' => ['php' => '>=8.1', 'ext-sockets' => '*'],
            'platform-dev' => ['ext-xdebug' => '*'],
        ], 0644);
        t_same(['intl' => ['vendor/a'], 'json' => ['composer.json'], 'mbstring' => ['vendor/b'], 'sockets' => ['composer.json', 'vendor/a']],
            PhpExtensions::required($t));
        t_same([], PhpExtensions::required($t . '/nothing-here'), 'a tree without composer files requires nothing');
    },
    'missing: core extensions never count, opcache under both names, php -m output normalised' => static function (): void {
        $loaded = PhpExtensions::normalise(['[PHP Modules]', 'Core', 'mbstring', 'PDO', '', '[Zend Modules]', 'Zend OPcache']);
        t_same(['core', 'mbstring', 'pdo', 'zend-opcache'], $loaded);
        $miss = PhpExtensions::missing(['json' => ['x'], 'mbstring' => ['x'], 'opcache' => ['y'], 'sockets' => ['composer.json'], 'pdo' => ['z']], $loaded);
        t_same(['sockets' => ['composer.json']], $miss);
    },
    'the php.ini lines name extension= (zend_extension= for opcache) for Linux and Windows' => static function (): void {
        $l = PhpExtensions::iniLines('sockets');
        t_true(strpos($l['linux'], 'extension=sockets') === 0, $l['linux']);
        t_true(strpos($l['windows'], 'extension=sockets') === 0 && strpos($l['windows'], 'php_sockets.dll') !== false, $l['windows']);
        t_true(strpos(PhpExtensions::iniLines('zend-opcache')['windows'], 'zend_extension=opcache') === 0);
    },
    'check against this PHP: a loaded extension passes, an unknown one is missing with its lines' => static function (): void {
        $t = t_scratch('ext-check');
        $have = '';
        foreach (PhpExtensions::normalise(get_loaded_extensions()) as $e) {
            if (!in_array($e, ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'], true)) {
                $have = $e;
                break;
            }
        }
        t_true($have !== '', 'this PHP loads a non-core extension');
        \Xne\Fs::writeJson($t . '/composer.json', ['require' => ['ext-' . $have => '*', 'ext-xne_test_missing' => '*']], 0644);
        $c = PhpExtensions::check($t, PHP_BINARY);
        t_true($c['checked']);
        t_same(['xne_test_missing'], array_keys($c['missing']));
        t_same(['composer.json'], $c['missing']['xne_test_missing']['required_by']);
        $trap = Plan::extensionTrap($c);
        t_same('php-extension-missing', $trap['id']);
        t_same('block', $trap['level']);
        t_true(strpos($trap['fix'], 'Linux: extension=xne_test_missing') !== false && strpos($trap['fix'], 'Windows: extension=xne_test_missing') !== false, $trap['fix']);
        t_same([], PhpExtensions::check($t, null)['missing'], 'no PHP chosen: nothing checked here (the plan is blocked for that already)');
    },
    'plan blocks a target whose composer.json needs a missing extension, before anything changes; --accept lets it pass' => static function (): void {
        [$s, $rel, $env] = t_world('ext-plan');
        t_fake_release($rel, 'v6.0.17', 'tag', '6.0.17', ['kernel/x17.php' => "<?php // 17\n",
            'composer.json' => json_encode(['require' => ['php' => '>=7.4', 'ext-xne_test_missing' => '*']])]);
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $before = t_tree($root);
        [$x, $out, $err, $j] = t_xne(['plan', '--root=' . $root, '--to=v6.0.17', '--json'], $env);
        t_same(3, $x, $out . $err);
        $blocked = implode("\n", $j['result']['blocked']);
        t_true(strpos($blocked, 'trap php-extension-missing') !== false && strpos($blocked, 'xne_test_missing') !== false, $blocked);
        t_true(strpos($blocked, 'extension=xne_test_missing') !== false, $blocked);
        t_same(['xne_test_missing'], array_keys($j['result']['php_extensions']['missing']));
        [$x2, $out2, $err2] = t_xne(['upgrade', '--root=' . $root, '--to=v6.0.17'], $env);
        t_same(3, $x2, $out2 . $err2);
        t_true(strpos($out2 . $err2, 'php-extension-missing') !== false, $out2 . $err2);
        t_same($before, t_tree($root), 'the refused upgrade changed nothing');
        [$x3, $out3, $err3, $j3] = t_xne(['plan', '--root=' . $root, '--to=v6.0.17', '--accept=php-extension-missing', '--json'], $env);
        t_same(0, $x3, $out3 . $err3);
        [$x4, , , $j4] = t_xne(['plan', '--root=' . $root, '--to=v6.0.15', '--json'], $env);
        t_same(0, $x4);
        t_same([], $j4['result']['php_extensions']['missing'], 'a target without ext-* requirements passes');
    },
];
