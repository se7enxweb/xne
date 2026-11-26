<?php
declare(strict_types=1);

namespace Xne;

/** Writes a ustar .tar.gz; names over 100 bytes get a GNU long-name record. */
final class TarWriter
{
    /** @var resource */
    private $gz;
    /** @var int */
    public $count = 0;

    public function __construct(string $file)
    {
        Fs::mkdir(dirname($file));
        $gz = @gzopen($file, 'wb6');
        if ($gz === false) {
            throw new XneException("cannot create $file");
        }
        $this->gz = $gz;
    }

    public function addFile(string $name, string $path): void
    {
        if (is_link($path)) {
            $this->header($name, '2', 0, 0777, Fs::readlink($path));
            $this->count++;
            return;
        }
        $size = (int) filesize($path);
        $this->header($name, '0', $size, fileperms($path) & 0777, '');
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new XneException("cannot read $path");
        }
        $written = 0;
        while (!feof($in)) {
            $c = fread($in, 1048576);
            if ($c === false || $c === '') {
                break;
            }
            gzwrite($this->gz, $c);
            $written += strlen($c);
        }
        fclose($in);
        if ($written !== $size) {
            throw new XneException("$path changed while it was archived");
        }
        $this->pad($size);
        $this->count++;
    }

    public function addString(string $name, string $data, int $mode = 0644): void
    {
        $this->header($name, '0', strlen($data), $mode, '');
        gzwrite($this->gz, $data);
        $this->pad(strlen($data));
        $this->count++;
    }

    public function close(): void
    {
        gzwrite($this->gz, str_repeat("\0", 1024));
        gzclose($this->gz);
    }

    private function pad(int $size): void
    {
        $r = $size % 512;
        if ($r !== 0) {
            gzwrite($this->gz, str_repeat("\0", 512 - $r));
        }
    }

    private function header(string $name, string $type, int $size, int $mode, string $link): void
    {
        if (strlen($name) > 100) {
            $this->rawHeader('././@LongLink', 'L', strlen($name) + 1, 0644, '');
            gzwrite($this->gz, $name . "\0");
            $this->pad(strlen($name) + 1);
        }
        if (strlen($link) > 100) {
            $this->rawHeader('././@LongLink', 'K', strlen($link) + 1, 0644, '');
            gzwrite($this->gz, $link . "\0");
            $this->pad(strlen($link) + 1);
        }
        $this->rawHeader(substr($name, 0, 100), $type, $size, $mode, substr($link, 0, 100));
    }

    private function rawHeader(string $name, string $type, int $size, int $mode, string $link): void
    {
        $h = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', time()) . "\0"
            . '        '
            . $type
            . str_pad($link, 100, "\0")
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")
            . str_pad('', 8, "\0") . str_pad('', 8, "\0")
            . str_pad('', 155, "\0");
        $h = str_pad($h, 512, "\0");
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }
        $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
        gzwrite($this->gz, $h);
    }
}
