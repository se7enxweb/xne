<?php
declare(strict_types=1);

use Xne\Config;
use Xne\Filelist;
use Xne\Ini;
use Xne\Version;

return [
    '--php names another PHP: xne runs again under it, once' => static function (): void {
        $other = '/usr/bin/php';
        $self = realpath(PHP_BINARY);
        if (!is_executable($other) || realpath($other) === $self) {
            $other = '/usr/bin/php';
        }
        t_same(null, \Xne\Console\App::reexecPhp([], false, false));
        t_same(null, \Xne\Console\App::reexecPhp(['php' => PHP_BINARY], false, false));
        t_same(null, \Xne\Console\App::reexecPhp(['php' => '/nonexistent/php'], false, false));
        if (is_executable($other) && realpath($other) !== $self) {
            t_same(realpath($other), \Xne\Console\App::reexecPhp(['php' => $other], false, false));
            t_same(realpath($other), \Xne\Console\App::reexecPhp(['set' => "php=$other"], false, false));
            t_same(realpath($other), \Xne\Console\App::reexecPhp([], $other, false));
            t_same(null, \Xne\Console\App::reexecPhp(['php' => $other], false, '1'));
        }
    },
    'versions compare numerically, the last position counts on' => static function (): void {
        t_same(1, Version::compare('6.0.4.10', '6.0.4.9'));
        t_same(-1, Version::compare('6.0.15alpha9', '6.0.15'));
        t_same(-1, Version::compare('6.0.15beta1', '6.0.15rc1'));
        t_same(0, Version::compare('v6.0.14', '6.0.14'));
        t_same(0, Version::compare('6.0.15stable', '6.0.15'));
        t_same(-1, Version::compare('0.3.0.9', '0.3.0.10'), 'four parts: .9 before .10');
        t_same(1, Version::compare('0.3.0.10', '0.3.0.9'));
        t_same(0, Version::compare('0.3.0', '0.3.0.0'), 'a three-part version is the four-part one with .0');
        t_same(-1, Version::compare('0.3.0.99', '0.3.1'));
        t_same('0.3.0.10', Version::base('v0.3.0.10'));
        t_true(Version::valid(\Xne\Console\App::VERSION) && count(explode('.', \Xne\Console\App::VERSION)) === 4, 'xne has a four-part version');
        t_same('6.0', Version::line('6.0.14'));
        t_true(!Version::valid('6.0.14.x-dev'));
        t_true(Version::isStable('v6.0.14') && !Version::isStable('6.0.15beta2'));
    },
    'the INI reader follows eZINI for plain values' => static function (): void {
        $i = new Ini();
        $i->parse("<?php /* #?ini charset=\"utf-8\"?\n[A]\nK=v\nL[]=1\nL[]=2\n#x=y\nH[a]=b\n[A]\nL[]\nL[]=3\n*/ ?>");
        t_same('v', $i->get('A', 'K'));
        t_same(['3'], $i->get('A', 'L'));
        t_same(['a' => 'b'], $i->get('A', 'H'));
        t_same(null, $i->get('A', 'x'));
    },
    'filelist.md5 parses and formats' => static function (): void {
        $m = Filelist::parse("d41d8cd98f00b204e9800998ecf8427e  a/b.php\nbad line\n0cc175b9c0f1b6a831c399e269772661  c d.txt\n");
        t_same(['a/b.php' => 'd41d8cd98f00b204e9800998ecf8427e', 'c d.txt' => '0cc175b9c0f1b6a831c399e269772661'], $m);
        t_same($m, Filelist::parse(Filelist::format($m)));
    },
    'settings: defaults < etc < XNE_CONF < install INI < environment < command line' => static function (): void {
        $s = t_scratch('cfg');
        file_put_contents($s . '/sys.conf', "channel=beta\nbackup_keep=9\nmaintenance=page\n");
        \Xne\Fs::atomicWrite($s . '/site/settings/override/xne.ini.append.php', "<?php /*\n[NetworkSettings]\nChannel=security\nHealthURL[]=https://a.example/\nHealthURL[]=https://b.example/\n*/ ?>\n");
        putenv('XNE_CONF=' . $s . '/sys.conf');
        putenv('XNE_BACKUP_KEEP=4');
        $c = new Config(dirname(__DIR__, 2), ['maintenance' => 'keep'], $s . '/site');
        putenv('XNE_CONF');
        putenv('XNE_BACKUP_KEEP');
        t_same('security', $c->get('channel'));
        t_same(4, $c->int('backup_keep'));
        t_same('keep', $c->get('maintenance'));
        t_same(['https://a.example/', 'https://b.example/'], $c->list('health_url'));
    },
    'the command line parser takes --k=v, --k v, flags and --set' => static function (): void {
        [$cmd, $pos, $opt] = \Xne\Console\App::parse(['plan', '--json', '--to', 'v6.0.15', '--channel=beta', 'x', '--set', 'php=/x', '--set=finish=none', '-n']);
        t_same('plan', $cmd);
        t_same(['x'], $pos);
        t_same('v6.0.15', $opt['to']);
        t_same('beta', $opt['channel']);
        t_same('1', $opt['json']);
        t_same('1', $opt['dry-run']);
        t_same("php=/x\nfinish=none", $opt['set']);
    },
    'every engine file lints on this PHP and keeps to PHP 7.4 syntax' => static function (): void {
        $home = dirname(__DIR__, 2);
        foreach (array_merge(glob($home . '/lib/Xne/*.php'), glob($home . '/classes/Xne/*/*.php'), glob($home . '/classes/Xne/*.php'), [$home . '/bin/xne']) as $f) {
            $r = \Xne\Proc::run([PHP_BINARY, '-l', $f]);
            t_same(0, $r['exit'], "php -l $f: " . $r['out'] . $r['err']);
            $src = (string) file_get_contents($f);
            foreach (['/\bmatch\s*\(/' => 'match', '/\bstr_(starts|ends)_with\(|\bstr_contains\(/' => 'PHP 8 string function', '/\):\s*(mixed|never|static)\b/' => 'PHP 8 return type',
                '/function\s+__construct\([^)]*\b(public|private|protected)\s/' => 'constructor promotion', '/\?->/' => 'nullsafe operator', '/^\s*#\[/m' => 'attribute', '/\benum\s+\w+/' => 'enum'] as $re => $what) {
                t_true(!preg_match($re, $src), basename($f) . " uses $what (PHP 7.4 must run xne)");
            }
        }
    },
];
