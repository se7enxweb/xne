<?php
declare(strict_types=1);

// The xne test runner (pure PHP, no PHPUnit): every tests/php/*Test.php and, when XNE_IT_DIR is set, every
// tests/integration/*Test.php returns a map "test name" => callable. A test passes when it returns without throwing.
// Usage: php tests/run.php [filter]   (filter: part of "Suite: test name", several joined by |). Exit 0 only when every test passes.
$home = dirname(__DIR__);
require $home . '/lib/Xne/Autoload.php';
\Xne\Autoload::register($home);
require __DIR__ . '/lib.php';
putenv('XNE_TEST_PHP=' . PHP_BINARY); // the PHP the fake tools' .bat wrappers start (tests/fakes)

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/php/*Test.php');
$it = getenv('XNE_IT_DIR');
if ($it !== false && $it !== '') {
    require_once __DIR__ . '/integration/Fixture.php';
    $files = array_merge($files, glob(__DIR__ . '/integration/*Test.php'));
}
$pass = 0;
$fail = 0;
$failed = [];
$t0 = microtime(true);
foreach ($files as $f) {
    $suite = basename($f, '.php');
    $tests = require $f;
    foreach ($tests as $name => $fn) {
        $id = "$suite: $name";
        if ($filter !== '' && !array_filter(explode('|', $filter), static function (string $f) use ($id): bool {
            return $f !== '' && stripos($id, $f) !== false;
        })) {
            continue;
        }
        $s = microtime(true);
        try {
            $fn();
            $pass++;
            printf("PASS  %-90s %6.1fs\n", $id, microtime(true) - $s);
        } catch (\Throwable $e) {
            $fail++;
            $failed[] = $id;
            printf("FAIL  %-90s %6.1fs\n      %s\n", $id, microtime(true) - $s, str_replace("\n", "\n      ", get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
        }
    }
}
printf("\n%d passed, %d failed, %d total, %.1fs (PHP %s)\n", $pass, $fail, $pass + $fail, microtime(true) - $t0, PHP_VERSION);
if ($fail) {
    echo "FAIL: " . implode('; ', $failed) . "\n";
    exit(1);
}
echo "PASS\n";
exit(0);
