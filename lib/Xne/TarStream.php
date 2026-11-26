<?php
declare(strict_types=1);

namespace Xne;

/** The data of one tar entry, read at most once. */
final class TarStream
{
    /** @var resource */
    private $gz;
    /** @var int */
    private $left;

    /** @param resource $gz */
    public function __construct($gz, int $size)
    {
        $this->gz = $gz;
        $this->left = $size;
    }

    public function readAll(): string
    {
        $d = $this->left > 0 ? (string) Tar::readExact($this->gz, $this->left) : '';
        $this->left = 0;
        return $d;
    }

    /** Streams the data into $path, returns its md5. */
    public function copyTo(string $path): string
    {
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new XneException("cannot write $path");
        }
        $ctx = hash_init('md5');
        while ($this->left > 0) {
            $c = Tar::readExact($this->gz, min(1048576, $this->left));
            if ($c === null) {
                break;
            }
            $this->left -= strlen($c);
            hash_update($ctx, $c);
            fwrite($out, $c);
        }
        fclose($out);
        return hash_final($ctx);
    }

    /** Streams the data through md5 without keeping it. */
    public function md5(): string
    {
        $ctx = hash_init('md5');
        while ($this->left > 0) {
            $c = Tar::readExact($this->gz, min(1048576, $this->left));
            if ($c === null) {
                break;
            }
            $this->left -= strlen($c);
            hash_update($ctx, $c);
        }
        return hash_final($ctx);
    }

    public function skipRest(): void
    {
        while ($this->left > 0) {
            $c = Tar::readExact($this->gz, min(1048576, $this->left));
            if ($c === null) {
                break;
            }
            $this->left -= strlen($c);
        }
    }
}
