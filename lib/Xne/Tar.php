<?php
declare(strict_types=1);

namespace Xne;

/**
 * Pure-PHP tar.gz reader and writer (ustar, with GNU long names and pax path/linkpath records), streamed through zlib,
 * so no tar program and no Phar extension is needed and a large release is never held in memory.
 */
final class Tar
{
    /**
     * Reads every entry of a .tar.gz. $fn(array $entry, ?resource $data) is called for each one; $entry has name, type
     * (file, dir, link), size, mode, link. For a file, $fn may read exactly $entry['size'] bytes through
     * Tar::copyTo/readAll; whatever it leaves is skipped. The pax global header (GitHub puts the commit id in its
     * "comment") is returned.
     * @return array<string,string> the pax global header
     */
    public static function read(string $file, callable $fn): array
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            throw new XneException("cannot open $file");
        }
        $global = [];
        $pax = [];
        $longName = null;
        $longLink = null;
        try {
            while (true) {
                $hdr = self::readExact($gz, 512);
                if ($hdr === null || trim($hdr, "\0") === '') {
                    break;
                }
                $name = rtrim(substr($hdr, 0, 100), "\0");
                $mode = octdec(trim(substr($hdr, 100, 8), " \0")) ?: 0644;
                $size = self::num(substr($hdr, 124, 12));
                $type = $hdr[156];
                $link = rtrim(substr($hdr, 157, 100), "\0");
                $magic = substr($hdr, 257, 5);
                $prefix = $magic === 'ustar' ? rtrim(substr($hdr, 345, 155), "\0") : '';
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                $chk = self::num(substr($hdr, 148, 8));
                $calc = 0;
                $h2 = substr_replace($hdr, '        ', 148, 8);
                for ($i = 0; $i < 512; $i++) {
                    $calc += ord($h2[$i]);
                }
                if ($chk !== $calc) {
                    throw new XneException("$file: damaged tar header near $name");
                }
                $padded = (int) (ceil($size / 512) * 512);
                if ($type === 'g' || $type === 'x' || $type === 'L' || $type === 'K') {
                    $data = $size > 0 ? (string) self::readExact($gz, $padded) : '';
                    $data = substr($data, 0, $size);
                    if ($type === 'L') {
                        $longName = rtrim($data, "\0");
                    } elseif ($type === 'K') {
                        $longLink = rtrim($data, "\0");
                    } else {
                        $rec = self::parsePax($data);
                        if ($type === 'g') {
                            $global = $rec + $global;
                        } else {
                            $pax = $rec;
                        }
                    }
                    continue;
                }
                if ($longName !== null) {
                    $name = $longName;
                }
                if ($longLink !== null) {
                    $link = $longLink;
                }
                if (isset($pax['path'])) {
                    $name = $pax['path'];
                }
                if (isset($pax['linkpath'])) {
                    $link = $pax['linkpath'];
                }
                if (isset($pax['size'])) {
                    $size = (int) $pax['size'];
                    $padded = (int) (ceil($size / 512) * 512);
                }
                $longName = $longLink = null;
                $pax = [];
                $kind = ($type === '5') ? 'dir' : (($type === '2') ? 'link' : (($type === '0' || $type === "\0" || $type === '7') ? 'file' : 'other'));
                $entry = ['name' => $name, 'type' => $kind, 'size' => $kind === 'file' ? $size : 0, 'mode' => $mode & 0777, 'link' => $link];
                $state = new TarStream($gz, $kind === 'file' ? $size : 0);
                $fn($entry, $state);
                $state->skipRest();
                $pad = $padded - $size;
                if ($kind !== 'file') {
                    $pad = $padded;
                    if ($size === 0) {
                        $pad = 0;
                    }
                }
                if ($pad > 0) {
                    self::readExact($gz, $pad);
                }
            }
        } finally {
            gzclose($gz);
        }
        return $global;
    }

    /** @param resource $gz */
    public static function readExact($gz, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $c = gzread($gz, min(1048576, $n - strlen($buf)));
            if ($c === false || $c === '') {
                return $buf === '' ? null : $buf;
            }
            $buf .= $c;
        }
        return $buf;
    }

    private static function num(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80)) {
            $v = 0;
            for ($i = 1, $n = strlen($field); $i < $n; $i++) {
                $v = ($v << 8) | ord($field[$i]);
            }
            return $v;
        }
        return (int) octdec(trim($field, " \0"));
    }

    /** @return array<string,string> */
    private static function parsePax(string $data): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($data);
        while ($pos < $len) {
            $sp = strpos($data, ' ', $pos);
            if ($sp === false) {
                break;
            }
            $recLen = (int) substr($data, $pos, $sp - $pos);
            if ($recLen <= 0) {
                break;
            }
            $rec = substr($data, $sp + 1, $recLen - ($sp - $pos) - 2);
            $eq = strpos($rec, '=');
            if ($eq !== false) {
                $out[substr($rec, 0, $eq)] = substr($rec, $eq + 1);
            }
            $pos += $recLen;
        }
        return $out;
    }

    /** Opens a .tar.gz for writing. */
    public static function create(string $file): TarWriter
    {
        return new TarWriter($file);
    }
}
