<?php
declare(strict_types=1);

use Xne\Tar;

return [
    'a tar.gz written by xne reads back exactly (long names, links, binary, empty)' => static function (): void {
        $s = t_scratch('tar');
        $long = str_repeat('deep/', 30) . 'file.txt';
        file_put_contents($s . '/bin', random_bytes(70000));
        file_put_contents($s . '/empty', '');
        // Windows creates a link only with Developer Mode or the right to; without it the link part is left out
        $link = @symlink('target/x', $s . '/lnk');
        $tw = Tar::create($s . '/a.tar.gz');
        $tw->addString($long, "long\n");
        $tw->addFile('b/bin', $s . '/bin');
        $tw->addFile('e', $s . '/empty');
        if ($link) {
            $tw->addFile('l', $s . '/lnk');
        }
        $tw->close();
        $seen = [];
        Tar::read($s . '/a.tar.gz', static function (array $e, $d) use (&$seen): void {
            $seen[$e['name']] = $e['type'] === 'file' ? md5($d->readAll()) : $e['type'] . ':' . $e['link'];
        });
        t_same(md5("long\n"), $seen[$long]);
        t_same(md5_file($s . '/bin'), $seen['b/bin']);
        t_same(md5(''), $seen['e']);
        if ($link) {
            t_same('link:target/x', $seen['l']);
        } else {
            echo "      (link part skipped here: this account may not create symbolic links)\n";
        }
    },
    'a damaged archive is refused' => static function (): void {
        $s = t_scratch('tarbad');
        $tw = Tar::create($s . '/a.tar.gz');
        $tw->addString('x', 'y');
        $tw->close();
        $raw = gzdecode((string) file_get_contents($s . '/a.tar.gz'));
        $raw[10] = 'Z';
        file_put_contents($s . '/b.tar.gz', gzencode($raw));
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            Tar::read($s . '/b.tar.gz', static function (): void {
            });
        });
    },
];
