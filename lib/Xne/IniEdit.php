<?php
declare(strict_types=1);

namespace Xne;

/**
 * Edits the text of an Exponential INI file (settings/override/site.ini.append.php and its kind) in place: one
 * setting at a time, every other line kept as it is (comments, order, other sections, the "<?php /*" wrapper).
 *
 * An array setting is written the way the kernel reads a full list: "Key[]" (the reset) and one "Key[]=value" per
 * value, where the first line of the old list was, else at the end of the section. Keyed entries ("Key[x]=...")
 * are left alone. A section that is not there is added before the closing "* /" of a .php file, else at the end.
 */
final class IniEdit
{
    /** @param list<string> $values */
    public static function setArray(string $text, string $section, string $key, array $values): string
    {
        $block = [$key . '[]'];
        foreach ($values as $v) {
            $block[] = $key . '[]=' . $v;
        }
        return self::replace($text, $section, '/^\s*' . preg_quote($key, '/') . '\[\]\s*(=.*)?$/', $block);
    }

    public static function setValue(string $text, string $section, string $key, string $value): string
    {
        return self::replace($text, $section, '/^\s*' . preg_quote($key, '/') . '\s*=.*$/', [$key . '=' . $value]);
    }

    /**
     * Removes the lines that match $match in every copy of $section, and puts $block where the first one was (else
     * at the end of the first copy of the section, else in a new section).
     * @param list<string> $block
     */
    private static function replace(string $text, string $section, string $match, array $block): string
    {
        $nl = strpos($text, "\r\n") !== false ? "\r\n" : "\n";
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $out = [];
        $cur = null;
        $placed = false;
        $firstSectionEnd = null;
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
                if ($cur === $section && $firstSectionEnd === null) {
                    $firstSectionEnd = count($out);
                }
                $cur = $m[1];
                $out[] = $line;
                continue;
            }
            if (preg_match('#^\s*\*/#', $line)) {
                if ($cur === $section && $firstSectionEnd === null) {
                    $firstSectionEnd = count($out);
                }
                $cur = null;
                $out[] = $line;
                continue;
            }
            if ($cur === $section && preg_match($match, $line)) {
                if (!$placed) {
                    foreach ($block as $b) {
                        $out[] = $b;
                    }
                    $placed = true;
                }
                continue;
            }
            $out[] = $line;
        }
        if ($cur === $section && $firstSectionEnd === null) {
            $firstSectionEnd = count($out);
        }
        if (!$placed) {
            if ($firstSectionEnd !== null) {
                // after the last line of the section that is not blank
                $at = $firstSectionEnd;
                while ($at > 0 && trim((string) $out[$at - 1]) === '') {
                    $at--;
                }
                array_splice($out, $at, 0, $block);
            } else {
                $at = count($out);
                foreach ($out as $i => $l) {
                    if (preg_match('#^\s*\*/#', $l)) {
                        $at = $i;
                        break;
                    }
                }
                if ($at === count($out)) {
                    while ($at > 0 && trim((string) $out[$at - 1]) === '') {
                        $at--;
                    }
                }
                array_splice($out, $at, 0, array_merge(['', '[' . $section . ']'], $block, ['']));
            }
        }
        return implode($nl, $out);
    }
}
