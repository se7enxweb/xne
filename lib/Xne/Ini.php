<?php
declare(strict_types=1);

namespace Xne;

/**
 * Reads Exponential INI files the way the kernel's eZINI does for plain values: [Section], Key=Value, Key[]=Value
 * (append), Key[k]=Value (hash), a bare Key[] (empties the array), # comments, and the .php wrapper of .append.php
 * files. Read-only: xne never writes settings/override (core design rule).
 */
final class Ini
{
    /** @var array<string, array<string, mixed>> */
    private $data = [];
    /** @var list<string> */
    public $files = [];

    /** Loads the files in order; later files override earlier ones. Missing files are skipped. */
    public static function load(array $files): self
    {
        $ini = new self();
        foreach ($files as $f) {
            if (is_file($f)) {
                $ini->parse((string) file_get_contents($f));
                $ini->files[] = $f;
            }
        }
        return $ini;
    }

    /** The files that make one INI for one place (settings/override by default), in the kernel's order. */
    public static function candidates(string $dir, string $name): array
    {
        return [$dir . '/' . $name, $dir . '/' . $name . '.append', $dir . '/' . $name . '.append.php'];
    }

    public function parse(string $text): void
    {
        $section = null;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || strncmp($t, '<?php', 5) === 0 || strncmp($t, '*/', 2) === 0 || strncmp($t, '/*', 2) === 0 || $t === '?>') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]/', $t, $m)) {
                $section = trim($m[1]);
                if (!isset($this->data[$section])) {
                    $this->data[$section] = [];
                }
                continue;
            }
            if ($section === null) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_\-\.]+)\[\]\s*$/', $t, $m)) {
                $this->data[$section][$m[1]] = [];
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9_\-\.]+)(\[([^\]]*)\])?=(.*)$/', $t, $m)) {
                continue;
            }
            $key = $m[1];
            $val = rtrim($m[4]);
            if ($m[2] !== '') {
                if (!isset($this->data[$section][$key]) || !is_array($this->data[$section][$key])) {
                    $this->data[$section][$key] = [];
                }
                if ($m[3] === '') {
                    $this->data[$section][$key][] = $val;
                } else {
                    $this->data[$section][$key][$m[3]] = $val;
                }
            } else {
                $this->data[$section][$key] = $val;
            }
        }
    }

    /** @return mixed|null */
    public function get(string $section, string $key, $default = null)
    {
        return $this->data[$section][$key] ?? $default;
    }

    public function has(string $section, string $key): bool
    {
        return isset($this->data[$section][$key]);
    }

    /** @return array<string, mixed> */
    public function section(string $section): array
    {
        return $this->data[$section] ?? [];
    }
}
