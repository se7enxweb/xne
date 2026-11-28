<?php
declare(strict_types=1);

use Xne\Client\Plan;
use Xne\Method\FilePatch;
use Xne\Source\Ranker;

return [
    'the file diff classifies add, update, local edit, retire, kept, protected, shared' => static function (): void {
        $s = t_scratch('diff');
        $r = $s . '/root';
        foreach (['same.php' => 'S', 'upd.php' => 'B', 'edit.php' => 'LOCAL', 'old.php' => 'O', 'oldedit.php' => 'OE', 'settings/override/site.ini' => 'mine'] as $p => $c) {
            \Xne\Fs::atomicWrite("$r/$p", $c);
        }
        \Xne\Fs::atomicWrite("$s/elsewhere/ext/x.php", 'X');
        \Xne\Fs::mkdir("$r/extension");
        symlink("$s/elsewhere/ext", "$r/extension/shared");
        $base = ['same.php' => md5('S'), 'upd.php' => md5('B'), 'edit.php' => md5('E'), 'old.php' => md5('O'), 'oldedit.php' => md5('X')];
        $target = ['same.php' => md5('S'), 'upd.php' => md5('T'), 'edit.php' => md5('T2'), 'new.php' => md5('N'), 'settings/override/site.ini' => md5('theirs'), 'extension/shared/x.php' => md5('Y')];
        $d = FilePatch::diff($r, $target, $base, ['settings/override', 'var']);
        t_same(['new.php'], $d['add']);
        t_same(['upd.php'], $d['update']);
        t_same(['edit.php'], $d['local_edits']);
        t_same(['old.php'], $d['remove']);
        t_same(['oldedit.php'], $d['kept_retired']);
        t_same(['settings/override/site.ini'], $d['protected']);
        t_same(['extension/shared/x.php'], $d['shared']);
        t_same(1, $d['same']);
        $nb = FilePatch::diff($r, $target, null, ['settings/override']);
        t_same(['edit.php', 'upd.php'], array_values(array_intersect(['edit.php', 'upd.php'], $nb['local_edits'])), 'without a base every differing file is a local edit');
        t_same([], $nb['remove'], 'without a base nothing is retired');
    },
    'channels pick the right target' => static function (): void {
        $refs = [];
        foreach (['v6.0.13', 'v6.0.14', 'v6.0.15beta1', 'v6.0.15', 'v6.1.0', 'v7.0.0', '6.0.4.10'] as $t) {
            $refs[] = ['ref' => $t, 'kind' => 'tag', 'sha' => sha1($t), 'version' => ltrim($t, 'v')];
        }
        $refs[] = ['ref' => 'main', 'kind' => 'branch', 'sha' => sha1('main'), 'version' => null];
        t_same('v6.1.0', Ranker::target($refs, '6.0.14', 'stable')['ref']);
        t_same('v6.0.15', Ranker::target($refs, '6.0.14', 'security')['ref']);
        t_same('v6.1.0', Ranker::target($refs, '6.0.14', 'beta')['ref']);
        t_same('main', Ranker::target($refs, '6.0.14', 'main')['ref']);
        t_same('v6.0.14', Ranker::target($refs, '6.0.13', 'stable', '6.0.14')['ref']);
        t_same(null, Ranker::target($refs, '6.1.0', 'security'));
        t_same('v6.0.14', Ranker::tagOf($refs, '6.0.14')['ref']);
        t_throws(\Xne\XneException::class, static function () use ($refs): void {
            Ranker::target($refs, '6.0.14', 'nightly');
        });
    },
    'SQL update files: A <= from < B <= target, in order, unstable never' => static function (): void {
        $s = t_scratch('sql');
        foreach (['6.0/dbupdate-5.4.0-6.0.0.sql', '6.0/dbupdate-6.0.0-6.0.15.sql', '6.0/dbupdate-6.0.15-to-6.0.16.sql', '6.0/unstable/dbupdate-6.0.0-6.0.15alpha1.sql', '6.1/dbupdate-6.0.16-6.1.0.sql'] as $f) {
            \Xne\Fs::atomicWrite("$s/update/database/mysql/$f", '');
        }
        t_same(['update/database/mysql/6.0/dbupdate-6.0.0-6.0.15.sql', 'update/database/mysql/6.0/dbupdate-6.0.15-to-6.0.16.sql'], Plan::sqlFiles($s, 'mysql', '6.0.14', '6.0.16'));
        t_same([], Plan::sqlFiles($s, 'mysql', '6.0.15stable', '6.0.15'));
    },
    'git: a checkout owned by another user (the site owner) is read and checked, not refused as dubious ownership' => static function (): void {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            t_true(true, 'needs root to chown the checkout; skipped');
            return;
        }
        $s = t_scratch('git-owner');
        $env = ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.com', 'HOME' => $s];
        $g = static function (array $a) use ($s, $env): void {
            $r = \Xne\Proc::run(array_merge(['git', '-C', $s . '/site'], $a), null, $env);
            t_same(0, $r['exit'], implode(' ', $a) . ': ' . $r['err']);
        };
        \Xne\Fs::mkdir($s . '/site');
        $g(['init', '-q', '-b', 'main']);
        file_put_contents($s . '/site/index.php', "<?php\n");
        file_put_contents($s . '/site/clean.php', "<?php // clean\n");
        $g(['add', 'index.php', 'clean.php']);
        $g(['commit', '-q', '-m', 'one']);
        file_put_contents($s . '/site/index.php', "<?php // edited\n");
        \Xne\Proc::run(['chown', '-R', 'nobody', $s . '/site']);
        $plain = \Xne\Proc::run(['git', '-C', $s . '/site', 'rev-parse', 'HEAD'], null, ['HOME' => $s]);
        t_true($plain['exit'] !== 0, 'plain git refuses the checkout (the case this covers)');
        $git = new \Xne\Method\Git($s . '/site');
        t_same(40, strlen($git->head()));
        touch($s . '/site/clean.php', time() + 5); // a stat change of a clean file makes a plain git status rewrite the index
        $d = \Xne\Client\Detect::git($s . '/site');
        t_same(['index.php'], $d['dirty']);
        t_same('main', $d['branch']);
        t_same((int) posix_getpwnam('nobody')['uid'], fileowner($s . '/site/.git/index'), 'reading as root leaves .git/index to its owner');
    },
    '--shared=skip|write answers the shared-extension-folder trap; without it the trap still blocks' => static function (): void {
        $traps = [['id' => 'shared-extension-folder', 'level' => 'block', 'message' => 'extension folders link out', 'fix' => 'f'],
            ['id' => 'kernel-overrides', 'level' => 'block', 'message' => 'm', 'fix' => 'f']];
        t_same($traps, Plan::withSharedChoice($traps, ''));
        t_same($traps, Plan::withSharedChoice($traps, 'bogus'));
        foreach (['skip', 'write'] as $c) {
            $t = Plan::withSharedChoice($traps, $c);
            t_same('info', $t[0]['level']);
            t_true(strpos($t[0]['message'], "--shared=$c") !== false, 'the choice is named');
            t_same('block', $t[1]['level'], 'other traps are untouched');
            t_same(['kernel-overrides'], array_column(\Xne\Client\Traps::blocking($t, []), 'id'));
        }
    },
    'PHP for the kernel tools: the platform check minimum decides; the web PHP first, then the order; refused when none' => static function (): void {
        $K = \Xne\Client\Kernel::class;
        $v = ['/cli80' => 80030, '/p83' => 80312, '/p84' => 80405, '/p85' => 80501, '/broken' => null];
        // a hosted case: CLI 8.0, vendor needs 8.4, web says 8.4
        $c = $K::choosePhp($v, '', '8.4', ['/cli80', '/p85', '/p84', '/p83'], 80400);
        t_same('/p84', $c['binary'], $c['reason']);
        // the web PHP is too old for the vendor: the first in order that meets it
        $c = $K::choosePhp($v, '', '8.3', ['/cli80', '/p85', '/p84', '/p83'], 80400);
        t_same('/p85', $c['binary']);
        // no platform check: the kernel minimum 8.1 still rules out 8.0
        $c = $K::choosePhp($v, '', null, ['/cli80', '/p83'], null);
        t_same('/p83', $c['binary']);
        t_same(80100, $c['needs']);
        // none meets it: refused with the reason
        $c = $K::choosePhp($v, '', null, ['/cli80', '/p83', '/broken'], 80400);
        t_same(null, $c['binary']);
        t_true(strpos($c['reason'], '8.4.0') !== false, $c['reason']);
        // a --php that is too old or does not run is refused, never replaced silently
        t_same(null, $K::choosePhp($v, '/p83', null, ['/p85'], 80400)['binary']);
        t_same(null, $K::choosePhp($v, '/broken', null, ['/p85'], null)['binary']);
        t_same('/p85', $K::choosePhp($v, '/p85', '8.4', ['/p84'], 80400)['binary']);
        // the readers
        $s = t_scratch('php-choice');
        \Xne\Fs::atomicWrite($s . '/vendor/composer/platform_check.php', "<?php\n\$issues = array();\nif (!(PHP_VERSION_ID >= 80400)) {\n    \$issues[] = 'x';\n}\n");
        t_same(80400, $K::platformMinimum($s));
        t_same(null, $K::platformMinimum($s . '/none'));
        \Xne\Fs::atomicWrite($s . '/.php-version', "8.3\n");
        t_same('8.3', $K::webPhp($s));
        \Xne\Fs::atomicWrite($s . '/.htaccess', "# AddHandler application/x-httpd-php56 .php\nAddHandler application/x-httpd-ea-php84 .php .php8\n");
        t_same('8.4', $K::webPhp($s), 'the AddHandler line wins, a commented one does not count');
    },
    'web PHP on Plesk: the vhost whose DocumentRoot is the install (any path to it), its PHP-FPM pool; then Velocity; cached per run' => static function (): void {
        $K = \Xne\Client\Kernel::class;
        $s = t_scratch('web-php-plesk');
        $saved = $K::$webPaths;
        try {
            $K::$webPaths = ['plesk_system' => "$s/system", 'plesk_php' => "$s/plesk/php", 'velocity' => ["$s/vc/sites-enabled"]];
            \Xne\Fs::mkdir("$s/acct/public_html/doc.example/doc");
            symlink('public_html', "$s/acct/doc");
            $root = "$s/acct/public_html/doc.example/doc";
            $vhost = static function (string $domain, string $file, string $text) use ($s): void {
                \Xne\Fs::atomicWrite("$s/system/$domain/conf/$file", $text);
            };
            $pool = static function (string $ver, string $domain) use ($s): void {
                \Xne\Fs::atomicWrite("$s/plesk/php/$ver/etc/php-fpm.d/$domain.conf", "[$domain]\n");
            };
            // nothing serves it yet; another vhost with its own root and pool does not count
            $vhost('other.example', 'httpd.conf', "<VirtualHost *:443>\n\t\tDocumentRoot \"$s/acct/public_html/other\"\n</VirtualHost>\n");
            $pool('8.3', 'other.example');
            t_same(null, $K::webPhp($root));
            // the vhost names it through the doc/ link: same directory, so it is found; the pool says 8.5
            $vhost('doc.example', 'httpd.conf', "<VirtualHost *:443>\n\t\tServerName \"doc.example\"\n\t\tDocumentRoot \"$s/acct/doc/doc.example/doc\"\n</VirtualHost>\n");
            $pool('8.5', 'doc.example');
            t_same(null, $K::webPhp($root), 'cached for the run: the same paths give the same answer');
            $K::$webPaths['velocity'][] = "$s/none"; // other paths, another cache entry
            $i = $K::webPhpInfo($root);
            t_same('8.5', $i['version']);
            t_same('Plesk', $i['source']);
            t_true(strpos($i['versions']['8.5'][0], 'doc.example (PHP-FPM pool ') === 0, $i['versions']['8.5'][0]);
            // an alias vhost (nginx only) of the same root on 8.4: the lowest is the site's, both are listed
            $vhost('alias.example', 'nginx.conf', "server {\n\troot \"$root/\";\n\tlocation @fallback { root /var/www/vhosts/default/htdocs; }\n}\n");
            $pool('8.4', 'alias.example');
            $K::$webPaths['velocity'][] = "$s/none2";
            $i = $K::webPhpInfo($root);
            t_same('8.4', $i['version']);
            t_same(['8.4', '8.5'], array_keys($i['versions']));
            // a vhost without a pool: the Plesk PHP its configuration names (FastCGI)
            $vhost('cgi.example', 'httpd.conf', "\t\tDocumentRoot \"$root\"\n\t\tFcgidWrapper $s/plesk/php/8.2/bin/php-cgi .php\n");
            $K::$webPaths['velocity'][] = "$s/none3";
            t_same(['8.2', '8.4', '8.5'], array_keys($K::webPhpInfo($root)['versions']));
            // an AddHandler in .htaccess still decides first
            \Xne\Fs::atomicWrite("$root/.htaccess", "AddHandler application/x-httpd-lsphp83 .php\n");
            $K::$webPaths['velocity'][] = "$s/none4";
            t_same('8.3', $K::webPhp($root));
            // Velocity: a site file whose host root is the install, the PHP in its name (no Plesk vhost)
            $r2 = "$s/acct/public_html/velo";
            \Xne\Fs::mkdir($r2);
            \Xne\Fs::atomicWrite("$s/vc/sites-enabled/vcc-acct-php85-exp0123.conf", json_encode(['Q' => ['webserver' => ['hosts' => ['velo.example' => ['root' => "$s/acct/doc/velo", 'rewrite' => []]]]]], JSON_PRETTY_PRINT));
            \Xne\Fs::atomicWrite("$s/vc/sites-enabled/plain.conf", json_encode(['Q' => ['webserver' => ['hosts' => ['x' => ['root' => $r2]]]]]));
            $i = $K::webPhpInfo($r2);
            t_same('8.5', $i['version']);
            t_same('Velocity', $i['source']);
            t_same(['velo.example (Velocity vcc-acct-php85-exp0123.conf)'], $i['versions']['8.5']);
        } finally {
            $K::$webPaths = $saved;
        }
    },
    'Composer PHP constraints: what composer.lock allows rules a PHP in or out' => static function (): void {
        $K = \Xne\Client\Kernel::class;
        $cases = [
            ['>=7.4 <8.6', '8.5.13', true], ['>=7.4 <8.6', '8.6.0', false], ['>= 7.4, < 8.6', '8.6.0', false],
            ['^7.4 || ^8.0', '8.6.0', true], ['^8.0 || ^8.1 || ^8.2 || ^8.3 || ^8.4', '9.0.0', false], ['^7.2|^8.0', '7.1.3', false],
            ['~8.1', '8.9.0', true], ['~8.1', '9.0.0', false], ['~8.1.2', '8.2.0', false], ['~8.1.2', '8.1.9', true],
            ['8.*', '8.6.0', true], ['8.1.*', '8.2.0', false], ['*', '8.6.0', true], ['', '8.6.0', true],
            ['7.4 - 8.5', '8.5.13', true], ['7.4 - 8.5', '8.6.0', false], ['7.4 - 8.5.1', '8.5.2', false],
            ['>=8.1,<8.6 || >=9', '8.6.0', false], ['!=8.6.0', '8.6.0', false], ['8.1', '8.1.0', true], ['8.1', '8.1.12', false],
            ['>=8.0.0-dev', '8.0.0', true], ['dev-master', '8.6.0', null],
        ];
        foreach ($cases as [$c, $v, $want]) {
            t_same($want, $K::constraintAllows($c, $v), "$c vs $v");
        }
        t_same('se7enxweb/tron requires php >=7.4 <8.6', $K::excludedBy(['a/b' => '>=8.0', 'se7enxweb/tron' => '>=7.4 <8.6'], 80600));
        t_same(null, $K::excludedBy(['a/b' => '>=8.0', 'x/y' => 'weird'], 80513));
        $s = t_scratch('lock-php');
        \Xne\Fs::atomicWrite("$s/composer.json", json_encode(['require' => ['php' => '>=8.1']]));
        \Xne\Fs::atomicWrite("$s/composer.lock", json_encode(['packages' => [['name' => 'se7enxweb/tron', 'require' => ['php' => '>=7.4 <8.6']], ['name' => 'a/noreq']],
            'packages-dev' => [['name' => 'dev/only', 'require' => ['php' => '<8.0']]]]));
        t_same(['composer.json' => '>=8.1', 'se7enxweb/tron' => '>=7.4 <8.6'], $K::lockPhpConstraints($s));
        t_same([], $K::lockPhpConstraints(null));
    },
    'the PHP fallback when the web PHP is unknown: panel PHPs first, no newer distribution build, composer.lock respected' => static function (): void {
        $K = \Xne\Client\Kernel::class;
        $p85 = '/opt/plesk/php/8' . '.5/bin/php'; // spelled in two parts: the public export rewrites the whole path
        // a Plesk node (2026-11-27): CLI 8.3, vendor needs 8.4, Plesk 8.4 and 8.5, /usr/bin/php86 a dev build
        $v = ['/usr/bin/php' => 80336, '/usr/bin/php86' => 80600, '/opt/plesk/php/8.4/bin/php' => 80420, $p85 => 80513, '/opt/plesk/php/7.4/bin/php' => 70433];
        $ranked = $K::rankInstalled(['/opt/plesk/php/7.4/bin/php', '/opt/plesk/php/8.4/bin/php', $p85, '/usr/bin/php86'], $v);
        t_same([$p85, '/opt/plesk/php/8.4/bin/php', '/opt/plesk/php/7.4/bin/php'], $ranked, 'php86 is newer than every Plesk PHP: left out');
        $c = $K::choosePhp($v, '', null, array_merge(['/usr/bin/php'], $ranked), 80400);
        t_same($p85, $c['binary'], $c['reason']);
        t_true(strpos($c['reason'], 'unknown') !== false, $c['reason']);
        t_same(['/usr/bin/php'], array_keys($c['skipped']));
        // without a panel PHP the distribution builds are all there is, newest first
        t_same(['/usr/bin/php86', '/usr/bin/php84'], $K::rankInstalled(['/usr/bin/php84', '/usr/bin/php86'], ['/usr/bin/php84' => 80410, '/usr/bin/php86' => 80600]));
        // a distribution PHP no newer than the panel's stays, after the panel PHPs
        t_same([$p85, '/usr/bin/php85'], $K::rankInstalled(['/usr/bin/php85', $p85], ['/usr/bin/php85' => 80510, $p85 => 80513]));
        // composer.lock rules the newest out even when it is first in order
        $c = $K::choosePhp($v, '', null, ['/usr/bin/php86', $p85], 80400, ['se7enxweb/tron' => '>=7.4 <8.6']);
        t_same($p85, $c['binary']);
        t_true(strpos($c['skipped']['/usr/bin/php86'], 'se7enxweb/tron requires php >=7.4 <8.6') !== false, $c['skipped']['/usr/bin/php86']);
        // the web PHP excluded by composer.lock: the next in order, and the reason says the web PHP would not do
        $c = $K::choosePhp($v, '', '8.6', ['/usr/bin/php86', $p85], 80400, ['se7enxweb/tron' => '>=7.4 <8.6'], 'Plesk');
        t_same($p85, $c['binary']);
        // the web PHP found: named with where it was read
        $c = $K::choosePhp($v, '', '8.5', ['/usr/bin/php', $p85], 80400, [], 'Plesk: doc.example (PHP-FPM pool x)');
        t_same("the web server's PHP 8.5 (Plesk: doc.example (PHP-FPM pool x))", $c['reason']);
        // none left: refused, with composer.lock named
        $c = $K::choosePhp(['/usr/bin/php86' => 80600], '', null, ['/usr/bin/php86'], 80400, ['se7enxweb/tron' => '<8.6']);
        t_same(null, $c['binary']);
        t_true(strpos($c['reason'], 'composer.lock') !== false, $c['reason']);
        // --php is the operator's word: never second-guessed by composer.lock
        t_same('/usr/bin/php86', $K::choosePhp($v, '/usr/bin/php86', null, [], 80400, ['se7enxweb/tron' => '<8.6'])['binary']);
    },
];
