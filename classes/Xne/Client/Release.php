<?php
declare(strict_types=1);

namespace Xne\Client;

use Xne\Config;
use Xne\Fs;
use Xne\Source\Source;
use Xne\Tar;
use Xne\Version;
use Xne\XneException;

/**
 * The release store: one extracted tree per commit in <cache_dir>/releases/<sha>/tree, with meta.json (source, ref,
 * commit, version from its lib/version.php, and the md5 of every file). A tree is downloaded once and then reused by
 * every plan and every install on the server. Extraction goes to a partial folder that is renamed when complete.
 */
final class Release
{
    /** @var string */
    public $dir;
    /** @var array<string,mixed> */
    public $meta;

    private function __construct(string $dir, array $meta)
    {
        $this->dir = $dir;
        $this->meta = $meta;
    }

    public function tree(): string
    {
        return $this->dir . '/tree';
    }

    /** @return array<string,string> path => md5 (or link:target) */
    public function files(): array
    {
        return (array) $this->meta['files'];
    }

    public function version(): string
    {
        return (string) $this->meta['version'];
    }

    /** @param array<string,mixed> $ref */
    public static function ensure(Config $cfg, Source $src, array $ref, ?callable $progress = null): self
    {
        $id = $ref['sha'] !== '' ? $ref['sha'] : 'ref-' . preg_replace('/[^\w.-]/', '_', (string) $ref['ref']);
        $base = Fs::join($cfg->get('cache_dir'), 'releases');
        $dir = $base . '/' . $id;
        if (is_file($dir . '/meta.json')) {
            return new self($dir, Fs::readJson($dir . '/meta.json'));
        }
        Fs::mkdir($base);
        $tgz = $base . '/' . $id . '.tar.gz';
        if (!is_file($tgz)) {
            if ($progress) {
                $progress('downloading ' . $ref['ref'] . ' (' . substr((string) $ref['sha'], 0, 10) . ') from ' . $src->name());
            }
            $src->download($ref, $tgz);
        }
        $partial = $dir . '.partial-' . getmypid();
        Fs::mkdir($partial . '/tree');
        file_put_contents($partial . '/.xne-release', "xne release cache\n");
        if ($progress) {
            $progress('extracting ' . basename($tgz));
        }
        $files = [];
        try {
            $links = [];
            $global = Tar::read($tgz, static function (array $e, $data) use ($partial, &$files, &$links): void {
                $name = $e['name'];
                $slash = strpos($name, '/');
                $rel = $slash === false ? '' : substr($name, $slash + 1);
                $rel = rtrim($rel, '/');
                if ($rel === '' || !Fs::safeRel($rel)) {
                    return;
                }
                $abs = $partial . '/tree/' . $rel;
                if ($e['type'] === 'dir') {
                    Fs::mkdir($abs);
                } elseif ($e['type'] === 'file') {
                    Fs::mkdir(dirname($abs));
                    $files[$rel] = $data->copyTo($abs);
                    @chmod($abs, ($e['mode'] & 0111) ? 0755 : 0644);
                } elseif ($e['type'] === 'link') {
                    // made after every file and folder: Windows decides between a file and a folder link by its target
                    $links[$abs] = $e['link'];
                    $files[$rel] = 'link:' . $e['link'];
                }
            });
            foreach ($links as $abs => $target) {
                Fs::mkdir(dirname($abs));
                Fs::symlink($target, $abs);
            }
        } catch (\Throwable $ex) {
            Guard::guardedRemoveCache($base, $partial);
            throw new XneException('the release archive ' . basename($tgz) . ' is damaged: ' . $ex->getMessage());
        }
        ksort($files, SORT_STRING);
        $version = Version::fromTree($partial . '/tree');
        if ($version === null) {
            Guard::guardedRemoveCache($base, $partial);
            throw new XneException('the release ' . $ref['ref'] . ' has no lib/version.php: not an Exponential tree');
        }
        $meta = [
            'source' => $src->name(), 'ref' => $ref['ref'], 'kind' => $ref['kind'],
            'sha' => $ref['sha'] !== '' ? $ref['sha'] : (string) ($global['comment'] ?? ''),
            'version' => $version, 'files' => $files, 'created' => gmdate('c'),
        ];
        Fs::writeJson($partial . '/meta.json', $meta, 0644);
        if (!@rename($partial, $dir)) {
            Guard::guardedRemoveCache($base, $partial);
            if (is_file($dir . '/meta.json')) {
                return new self($dir, Fs::readJson($dir . '/meta.json'));
            }
            throw new XneException("cannot store the release in $dir");
        }
        return new self($dir, $meta);
    }
}
