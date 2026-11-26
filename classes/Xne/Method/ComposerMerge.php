<?php
declare(strict_types=1);

namespace Xne\Method;

use Xne\Fs;

/**
 * An install whose libraries Composer manages (vendor/composer/installed.json): what belongs to Composer, and the
 * install's composer.json brought to the release's.
 *
 * Composer's folders (vendor/, every package's install path, the extension folders the legacy installer places)
 * are never written or retired by the file patch: they follow composer.lock, through the kernel's libraries stage.
 * Releases moved extensions out of the kernel repository into packages (6.0.15: ezwebin, sevenx_themes_simple,
 * swark ...); an install's own share/filelist.md5 still lists their files, so a file patch would retire them.
 *
 * composer.json of such an install is the kernel's own file (name se7enxweb/exponential). The release's file is
 * taken, with the install's extension packages the release no longer requires kept, at ">=" their locked version
 * (a release that drops an extension must not uninstall one the site uses; the release's own packages may require
 * a newer one: exponential-php-floor 1.3 requires ezodf ~7.0 where the site had ~6.0); other requirements the
 * release dropped go (kernel libraries it replaced, such as zetacomponents by expcomponents). The packages to update
 * are all root requirements (require and require-dev): the release's set is resolved as a whole, a partial update
 * keeps older locked versions the release's packages no longer accept.
 */
final class ComposerMerge
{
    /** Package types the legacy installer places into extension/<name> */
    public const EXTENSION_TYPES = ['ezpublish-legacy-extension', 'exponential-legacy-extension', 'ezpublish-legacy-extension-installer'];

    public static function managed(string $root): bool
    {
        return is_file($root . '/composer.json') && is_file($root . '/vendor/composer/installed.json');
    }

    /**
     * The paths Composer owns, relative to the install: vendor, composer.json, composer.lock, each installed
     * package's install path inside the install, and the extension folder of each extension package composer.lock
     * names. Empty when Composer does not manage the install.
     * @return list<string>
     */
    public static function packagePaths(string $root): array
    {
        if (!self::managed($root)) {
            return [];
        }
        $paths = ['vendor', 'composer.json', 'composer.lock'];
        $j = json_decode((string) file_get_contents($root . '/vendor/composer/installed.json'), true);
        $pk = is_array($j) && isset($j['packages']) ? $j['packages'] : (is_array($j) ? $j : []);
        foreach ((array) $pk as $p) {
            $ip = (string) ($p['install-path'] ?? '');
            if ($ip === '') {
                continue;
            }
            $rel = self::normalise('vendor/composer/' . $ip);
            if ($rel !== null && $rel !== '' && strncmp($rel, 'vendor/', 7) !== 0 && Fs::safeRel($rel)) {
                $paths[] = $rel;
            }
        }
        foreach (self::lockPackages($root . '/composer.lock') as $p) {
            $dir = self::extensionFolder($p);
            if ($dir !== null) {
                $paths[] = 'extension/' . $dir;
            }
        }
        return array_values(array_unique($paths));
    }

    /** True when $path is one of $prefixes or inside one. @param list<string> $prefixes */
    public static function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $x) {
            if ($path === $x || strncmp($path, $x . '/', strlen($x) + 1) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $install the install's composer.json
     * @param array<string,mixed> $target the release's composer.json
     * @param list<array<string,mixed>> $locked the packages of the install's composer.lock
     * @return array{json: array<string,mixed>, kept: array<string,string>, dropped: list<string>, added: list<string>, changed: list<string>, update: list<string>}
     */
    public static function merge(array $install, array $target, array $locked): array
    {
        $byName = [];
        foreach ($locked as $p) {
            if (isset($p['name'])) {
                $byName[strtolower((string) $p['name'])] = $p;
            }
        }
        $json = $target;
        $req = (array) ($target['require'] ?? []);
        $old = (array) ($install['require'] ?? []);
        $kept = [];
        $dropped = [];
        foreach ($old as $name => $c) {
            if (isset($req[$name]) || strpos((string) $name, '/') === false) {
                continue;
            }
            $lockedPkg = $byName[strtolower((string) $name)] ?? [];
            $type = (string) ($lockedPkg['type'] ?? '');
            if (in_array($type, self::EXTENSION_TYPES, true)) {
                $v = ltrim((string) ($lockedPkg['version'] ?? ''), 'vV');
                $req[$name] = preg_match('/^\d+(\.\d+)*$/', $v) ? '>=' . $v : '*';
                $kept[$name] = $req[$name];
            } else {
                $dropped[] = (string) $name;
            }
        }
        $json['require'] = $req;
        $added = [];
        $changed = [];
        foreach ($req as $name => $c) {
            if (strpos((string) $name, '/') === false || isset($kept[$name])) {
                continue;
            }
            if (!isset($byName[strtolower((string) $name)])) {
                $added[] = (string) $name;
            } elseif (isset($old[$name]) && (string) $old[$name] !== (string) $c) {
                $changed[] = (string) $name;
            }
        }
        $all = [];
        foreach (array_merge(array_keys($req), array_keys((array) ($json['require-dev'] ?? []))) as $name) {
            if (strpos((string) $name, '/') !== false) {
                $all[] = (string) $name;
            }
        }
        return ['json' => $json, 'kept' => $kept, 'dropped' => $dropped, 'added' => $added, 'changed' => $changed,
            'update' => array_values(array_unique($all))];
    }

    /** composer.json as Composer writes it (4 spaces, slashes and unicode as they are), with a final newline. */
    public static function encode(array $json): string
    {
        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @return list<array<string,mixed>> */
    public static function lockPackages(string $lock): array
    {
        if (!is_file($lock)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($lock), true);
        return array_values(array_filter(array_merge((array) ($j['packages'] ?? []), (array) ($j['packages-dev'] ?? [])), 'is_array'));
    }

    /** The extension folder the legacy installer gives a package, or null when it is no extension package. */
    public static function extensionFolder(array $p): ?string
    {
        if (!in_array((string) ($p['type'] ?? ''), self::EXTENSION_TYPES, true)) {
            return null;
        }
        $name = (string) ($p['name'] ?? '');
        $dir = (string) ($p['extra']['ezpublish-legacy-extension-name'] ?? $p['extra']['installer-name'] ?? substr((string) strrchr('/' . $name, '/'), 1));
        return $dir !== '' && Fs::safeRel($dir) && strpos($dir, '/') === false ? $dir : null;
    }

    /** "vendor/composer/../../extension/x" -> "extension/x"; null when it leaves the install. */
    private static function normalise(string $p): ?string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $p)) as $s) {
            if ($s === '' || $s === '.') {
                continue;
            }
            if ($s === '..') {
                if (!$out) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $s;
        }
        return implode('/', $out);
    }
}
