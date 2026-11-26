<?php
declare(strict_types=1);

// The command reference (doc/cli.md) is checked against xne's own help: every command has a section, the section
// holds the exact text of "xne help <command>", and every option that help names is documented. A new command or
// option without its documentation fails here.

$usage = static function (): array {
    $c = new \ReflectionClassConstant(\Xne\Console\App::class, 'USAGE');
    return (array) $c->getValue();
};
$doc = static function (): string {
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/doc/cli.md'));
};

return [
    'doc/cli.md has a section per command with the exact text of xne help <command>' => static function () use ($usage, $doc): void {
        $md = $doc();
        foreach ($usage() as $cmd => $text) {
            $p = strpos($md, "\n### $cmd\n");
            t_true($p !== false, "doc/cli.md: no section ### $cmd");
            $next = strpos($md, "\n#", $p + 5);
            $section = substr($md, $p, $next === false ? null : $next - $p);
            t_true(strpos($section, "```\n" . $text . "\n```") !== false, "doc/cli.md ### $cmd: the usage block differs from xne help $cmd");
        }
    },
    'every option in xne help is explained in doc/cli.md (outside the usage blocks)' => static function () use ($usage, $doc): void {
        $md = (string) preg_replace('/^```.*?^```/ms', '', $doc());
        $r = t_xne(['help']);
        t_same(0, $r[0]);
        preg_match_all('/--[a-z][a-z-]*/', $r[1] . implode("\n", $usage()), $m);
        foreach (array_unique($m[0]) as $o) {
            t_true(strpos($md, '`' . $o) !== false, "doc/cli.md documents no $o");
        }
    },
    'every command of xne help answers --help with its usage and exit 0' => static function () use ($usage): void {
        foreach ($usage() as $cmd => $text) {
            if ($cmd === 'help') {
                continue;
            }
            [$c, , , $j] = t_xne([$cmd, '--help', '--json']);
            t_same(0, $c, "xne $cmd --help");
            t_same($text, $j['result']['usage'], "xne $cmd --help --json");
        }
    },
];
