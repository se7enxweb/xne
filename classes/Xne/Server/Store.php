<?php
declare(strict_types=1);

namespace Xne\Server;

use Xne\Fs;
use Xne\XneException;

/**
 * The server's data folder (server_data, default /var/lib/xne-server, mode 0700): index, advisories, keys, reports,
 * counters. When root writes (the CLI: server:index, server:key:*), files and folders are given to server_owner (the
 * API's PHP-FPM user, e.g. xne-api), so the API can read them and nobody else can.
 */
final class Store
{
    /** @var string */
    public $dir;
    /** @var string */
    private $owner;

    public function __construct(string $dir, string $owner = '')
    {
        if ($dir === '') {
            throw new XneException('server_data is not set');
        }
        $this->dir = rtrim($dir, '/');
        $this->owner = $owner;
        Fs::mkdir($this->dir, 0700);
        $this->own($this->dir);
    }

    public function path(string $rel): string
    {
        if (!Fs::safeRel($rel)) {
            throw new XneException("unsafe path $rel");
        }
        return $this->dir . '/' . $rel;
    }

    /** @return array<mixed>|null */
    public function read(string $rel): ?array
    {
        $f = $this->path($rel);
        return is_file($f) ? Fs::readJson($f) : null;
    }

    public function write(string $rel, array $data, int $mode = 0600): void
    {
        $f = $this->path($rel);
        for ($d = dirname($f), $new = []; strlen($d) > strlen($this->dir) && !is_dir($d); $d = dirname($d)) {
            $new[] = $d;
        }
        Fs::mkdir(dirname($f), 0700);
        Fs::writeJson($f, $data, $mode);
        foreach (array_merge([$f], $new ?? []) as $p) {
            $this->own($p);
        }
    }

    private function own(string $path): void
    {
        if ($this->owner === '' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        @chown($path, $this->owner);
        @chgrp($path, $this->owner);
    }
}
