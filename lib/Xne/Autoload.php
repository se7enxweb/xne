<?php
declare(strict_types=1);

namespace Xne;

/**
 * PSR-4 autoloader without Composer: the namespace Xne\ maps to lib/Xne/ (infrastructure: HTTP, tar, filelist.md5,
 * INI, processes) and to classes/Xne/ (the engine: client stages, sources, methods, database drivers, console).
 * Works on PHP 7.4 to 8.5 and on every operating system.
 */
final class Autoload
{
    /** @var list<string> */
    private static $roots = [];

    public static function register(string $home): void
    {
        self::$roots = [$home . '/lib/Xne', $home . '/classes/Xne'];
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        if (strncmp($class, 'Xne\\', 4) !== 0) {
            return;
        }
        $rel = str_replace('\\', '/', substr($class, 4)) . '.php';
        foreach (self::$roots as $root) {
            $f = $root . '/' . $rel;
            if (is_file($f)) {
                require_once $f;
                return;
            }
        }
    }
}
