<?php
declare(strict_types=1);

namespace Xne\Source;

use Xne\Config;
use Xne\Fs;
use Xne\Version;

/**
 * A place releases come from. Each source lists refs (tags and branches, each with its commit) and downloads one ref
 * as a .tar.gz. The lists are cached for index_ttl seconds in <cache_dir>/index/, so a plan takes seconds.
 */
abstract class Source
{
    /** @var Config */
    protected $cfg;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
    }

    abstract public function name(): string;

    /** '' when the source can be used, else why not (not configured ...). */
    abstract public function unavailable(): string;

    /** @return list<array{ref: string, kind: string, sha: string, version: ?string}> */
    abstract protected function fetchRefs(): array;

    /** Downloads the tree of $ref (commit $sha) as a .tar.gz into $file. */
    abstract public function download(array $ref, string $file): void;

    /** @return list<array{ref: string, kind: string, sha: string, version: ?string, source: string}> */
    public function refs(bool $refresh = false): array
    {
        $f = Fs::join($this->cfg->get('cache_dir'), 'index', $this->name() . '-' . substr(md5($this->identity()), 0, 10) . '.json');
        if (!$refresh && is_file($f) && time() - (int) filemtime($f) < $this->cfg->int('index_ttl')) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) {
                return $d;
            }
        }
        $refs = [];
        foreach ($this->fetchRefs() as $r) {
            $r['source'] = $this->name();
            if ($r['version'] === null && $r['kind'] === 'tag' && Version::valid($r['ref'])) {
                $r['version'] = ltrim($r['ref'], 'vV');
            }
            $refs[] = $r;
        }
        Fs::mkdir(dirname($f));
        Fs::writeJson($f, $refs, 0644);
        return $refs;
    }

    /** What makes this source's list distinct (repo, project, package). */
    abstract protected function identity(): string;

    /** @return array<string,string> */
    protected function authHeader(string $tokenKey, string $header, string $envName, string $prefix = ''): array
    {
        $t = $this->cfg->secretFile($tokenKey);
        if ($t === '') {
            $e = getenv($envName);
            $t = $e === false ? '' : trim($e);
        }
        return $t === '' ? [] : [$header => $prefix . $t];
    }
}
