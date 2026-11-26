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
];
