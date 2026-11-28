<?php
declare(strict_types=1);

// The kernel's code stage after a file patch. A git checkout keeps its commits under a file patch, so the kernel's
// code step (6.0.15-code-update, which moves the checkout) stays pending, and exp:upgrade refuses the later stages:
// "the stage code has 1 pending step(s): run it first (or --force-order)" (doc.exponential.earth on cluster,
// 2026-11-27, rolled back). A fake kernel (upgrade.php and upgradestatus.php stand-ins that keep the order rule and
// know --code-applied when the release's command contract names it) checks that xne tells the kernel the code is in
// place: --code-applied=<sha> on a kernel that has it, --force-order on an older one only when the code step is the
// one pending step before the run, and nothing (the kernel's refusal, then the rollback) when anything else is pending.

/** Releases v6.0.14 and v6.0.15 whose kernel knows --code-applied or not; an install whose code step is pending. */
function t_code_world(string $name, bool $codeApplied, array $pendingCode = ['6.0.15-code-update']): array
{
    $s = t_scratch($name);
    $rel = $s . '/releases';
    $status = <<<'PHP'
<?php
// test stand-in for exp:upgrade:status: the code steps of var/code-pending.json are pending (a git checkout whose
// commits a file patch left as they were), unless --code-applied names the code (a kernel that knows it)
$stages = ['code', 'libraries', 'database', 'scripts', 'finish'];
$applied = false;
foreach ($argv as $a) {
    if (strpos($a, '--stages=') === 0) {
        $stages = explode(',', substr($a, 9));
    }
    if (strpos($a, '--code-applied=') === 0) {
        $applied = true;
    }
}
$code = $applied ? [] : (array) json_decode((string) @file_get_contents('var/code-pending.json'), true);
$out = [];
foreach ($stages as $st) {
    $steps = [];
    foreach ($st === 'code' ? $code : [] as $id) {
        $steps[] = ['id' => $id, 'state' => 'pending'];
    }
    $out[] = ['stage' => $st, 'pending' => count($steps), 'steps' => $steps];
}
echo json_encode(['format' => 'exp-upgrade/1', 'stages' => $out, 'errors' => [], 'exit' => 0]), "\n";
exit(0);
PHP;
    $upgrade = <<<'PHP'
<?php
// test stand-in for exp:upgrade: the kernel's order rule (an earlier stage left out must have nothing pending);
// --code-applied only when the contract names it (an older kernel stops on an unknown option), --force-order skips the rule
$contract = (string) @file_get_contents('kernel/private/classes/commands/upgradebase.php');
file_put_contents('var/upgrade-argv.json', json_encode(array_slice($argv, 1)));
$stages = [];
$force = false;
$applied = '';
foreach ($argv as $a) {
    if (strpos($a, '--stages=') === 0) {
        $stages = explode(',', substr($a, 9));
    } elseif ($a === '--force-order') {
        $force = true;
    } elseif (strpos($a, '--code-applied=') === 0) {
        $applied = substr($a, 15);
    }
}
if ($applied !== '' && strpos($contract, '[code-applied:]') === false) {
    fwrite(STDERR, "unknown option --code-applied\n");
    exit(2);
}
$code = $applied !== '' ? [] : (array) json_decode((string) @file_get_contents('var/code-pending.json'), true);
if ($code && !in_array('code', $stages, true) && !$force) {
    echo json_encode(['errors' => ['the stage code has ' . count($code) . ' pending step(s): run it first (or --force-order)'], 'stages' => []]), "\n";
    exit(1);
}
if (in_array('database', $stages, true)) {
    $pdo = new PDO('sqlite:var/storage/sqlite3/test.db');
    $pdo->exec("UPDATE ezsite_data SET value='6.0.15stable' WHERE name='ezpublish-version'");
}
echo json_encode(['summary' => ['applied' => count($stages)], 'stages' => []]), "\n";
exit(0);
PHP;
    $contract = "<?php\n// test stand-in for the kernel's upgrade command contract\nconst CODE_OPTIONS = '[to:][repos:*]" . ($codeApplied ? '[code-applied:]' : '') . "';\n";
    $common = ['bin/php/upgrade.php' => $upgrade, 'bin/php/upgradestatus.php' => $status,
        'kernel/private/classes/commands/upgradebase.php' => $contract];
    t_fake_release($rel, 'v6.0.14', 'tag', '6.0.14', $common + ['kernel/edited.php' => "<?php // 14\n"]);
    t_fake_release($rel, 'v6.0.15', 'tag', '6.0.15', $common + ['kernel/edited.php' => "<?php // 15\n", 'kernel/new.php' => "<?php // new\n"]);
    $env = ['XNE_STATE_DIR' => $s . '/state', 'XNE_CACHE_DIR' => $s . '/cache', 'XNE_SOURCES' => 'local', 'XNE_LOCAL_SOURCE' => $rel, 'XNE_CONF' => $s . '/none.conf'];
    $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
    \Xne\Fs::atomicWrite($root . '/var/code-pending.json', json_encode($pendingCode));
    return [$s, $root, $env];
}

/** @return list<string> the arguments the fake exp:upgrade was started with */
function t_code_argv(string $root): array
{
    return (array) json_decode((string) @file_get_contents($root . '/var/upgrade-argv.json'), true);
}

return [
    'codeStageArgs: --code-applied on a kernel that has it, --force-order only for the code step alone, else nothing' => static function (): void {
        $sha = str_repeat('ab', 20);
        t_same(['args' => ['--code-applied=' . $sha], 'note' => 'code stage: in place by the file patch (--code-applied=abababababab)'],
            \Xne\Client\Kernel::codeStageArgs(true, ['database', 'scripts'], $sha, null));
        $only = \Xne\Client\Kernel::codeStageArgs(false, ['database', 'scripts'], $sha, ['code' => ['6.0.15-code-update'], 'libraries' => []]);
        t_same(['--force-order'], $only['args']);
        t_true(strpos($only['note'], 'no --code-applied') !== false, $only['note']);
        t_same([], \Xne\Client\Kernel::codeStageArgs(false, ['database'], $sha, ['code' => ['6.0.15-code-update'], 'libraries' => ['6.0.15-libraries-install']])['args'], 'libraries pending too');
        t_same([], \Xne\Client\Kernel::codeStageArgs(false, ['database'], $sha, ['code' => ['6.0.15-code-update', '6.0.16-other']])['args'], 'another code step');
        t_same([], \Xne\Client\Kernel::codeStageArgs(false, ['database'], $sha, ['code' => []])['args'], 'nothing pending: no flag');
        t_same([], \Xne\Client\Kernel::codeStageArgs(false, ['database'], $sha, null)['args'], 'status unknown: no flag');
        t_same([], \Xne\Client\Kernel::codeStageArgs(false, ['code', 'database'], $sha, [])['args'], 'the code stage runs itself');
        t_same(['code', 'libraries'], \Xne\Client\Kernel::earlierStages(['database', 'scripts']));
        t_same(['code'], \Xne\Client\Kernel::earlierStages(['libraries', 'database', 'scripts']));
        t_same([], \Xne\Client\Kernel::earlierStages(['code', 'database']));
        t_same(['code' => ['6.0.15-code-update'], 'libraries' => ['x']], \Xne\Client\Kernel::pendingStepsOf(['stages' => [
            ['stage' => 'code', 'pending' => 1, 'steps' => [['id' => '6.0.15-code-update', 'state' => 'pending'], ['id' => 'y', 'state' => 'not-needed']]],
            ['stage' => 'libraries', 'pending' => 1, 'steps' => [['id' => 'x', 'state' => 'failed']]]]]));
    },
    'the plan names the code stage answer of the release kernel' => static function (): void {
        [, $root, $env] = t_code_world('codeplan', true);
        [$x, , $err, $j] = t_xne(['plan', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(0, $x, $err);
        $db = $j['result']['database'];
        t_same('code-applied', $db['code_stage']);
        t_true(strpos($db['kernel_command'], '--code-applied=' . $j['result']['target']['sha']) !== false, $db['kernel_command']);
        [, $root2, $env2] = t_code_world('codeplanold', false);
        [, , , $j2] = t_xne(['plan', '--root=' . $root2, '--method=filepatch', '--json'], $env2);
        t_same('force-order-if-only-code-pending', $j2['result']['database']['code_stage']);
        t_true(strpos($j2['result']['database']['kernel_command'], '--code-applied') === false);
    },
    'a file patch on a kernel with --code-applied: the later stages run, and xne rollback gives everything back' => static function (): void {
        [, $root, $env] = t_code_world('codenew', true);
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, $out, $err, $j] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(0, $x, $out . $err);
        $r = $j['result'];
        t_true($r['ok'] === true, json_encode($r));
        $argv = t_code_argv($root);
        t_true(in_array('--code-applied=' . $r['target']['sha'], $argv, true), json_encode($argv));
        t_true(!in_array('--force-order', $argv, true), 'never --force-order on a kernel that has --code-applied');
        t_true((bool) preg_grep('/^code stage: in place by the file patch \(--code-applied=/', $r['steps']), json_encode($r['steps']));
        $pdo = new PDO('sqlite:' . $root . '/var/storage/sqlite3/test.db');
        t_same('6.0.15stable', $pdo->query("SELECT value FROM ezsite_data WHERE name='ezpublish-version'")->fetchColumn());
        [$x2, , $err2] = t_xne(['rollback', '--root=' . $root, '--json'], $env);
        t_same(0, $x2, $err2);
        t_same($tree, t_tree($root), 'files back exactly');
        t_same($db, t_db($root), 'database back exactly');
    },
    'a file patch on an older kernel: --force-order when the code step is the only pending step' => static function (): void {
        [, $root, $env] = t_code_world('codeold', false);
        [$x, $out, $err, $j] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(0, $x, $out . $err);
        $argv = t_code_argv($root);
        t_true(in_array('--force-order', $argv, true), json_encode($argv));
        t_true(!preg_grep('/^--code-applied/', $argv), 'an older kernel never gets --code-applied');
        t_true((bool) preg_grep('/no --code-applied, so --force-order/', $j['result']['steps']), json_encode($j['result']['steps']));
    },
    'a file patch on an older kernel with another pending step: no --force-order, the kernel refuses, xne rolls back' => static function (): void {
        [, $root, $env] = t_code_world('codeother', false, ['6.0.15-code-update', '6.0.16-code-other']);
        $tree = t_tree($root);
        $db = t_db($root);
        [$x, , $err, $j] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(1, $x, $err);
        t_true($j['result']['rolled_back'] === true, json_encode($j['result']));
        t_true(strpos((string) $j['result']['error'], 'the stage code has 2 pending') !== false, (string) $j['result']['error']);
        t_true(!in_array('--force-order', t_code_argv($root), true), 'never --force-order over another pending step');
        t_same($tree, t_tree($root), 'files back exactly');
        t_same($db, t_db($root), 'database back exactly');
    },
    'nothing pending in the code stage of an older kernel: no flag at all' => static function (): void {
        [, $root, $env] = t_code_world('codenone', false, []);
        [$x, $out, $err] = t_xne(['upgrade', '--root=' . $root, '--method=filepatch', '--json'], $env);
        t_same(0, $x, $out . $err);
        $argv = t_code_argv($root);
        t_true(!in_array('--force-order', $argv, true) && !preg_grep('/^--code-applied/', $argv), json_encode($argv));
    },
];
