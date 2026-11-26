<?php
declare(strict_types=1);

namespace Xne\Source;

use Xne\Fs;
use Xne\XneException;

/**
 * A local release folder (air-gapped servers, a company mirror, tests): local_source names a folder with index.json
 * ([{"ref": "v6.0.14", "kind": "tag", "sha": "<commit>"}, ...]) and one <ref>.tar.gz per entry (GitHub's tarball
 * layout: one top folder). Listed as "local" in the sources setting, ranked where it is put.
 */
final class Local extends Source
{
    public function name(): string
    {
        return 'local';
    }

    protected function identity(): string
    {
        return $this->cfg->get('local_source');
    }

    public function unavailable(): string
    {
        $d = $this->cfg->get('local_source');
        return $d !== '' && is_file($d . '/index.json') ? '' : 'local_source is not set or has no index.json';
    }

    protected function fetchRefs(): array
    {
        $out = [];
        foreach (Fs::readJson($this->cfg->get('local_source') . '/index.json') as $r) {
            $out[] = ['ref' => (string) $r['ref'], 'kind' => (string) ($r['kind'] ?? 'tag'), 'sha' => (string) ($r['sha'] ?? ''), 'version' => isset($r['version']) ? (string) $r['version'] : null];
        }
        return $out;
    }

    public function download(array $ref, string $file): void
    {
        $src = $this->cfg->get('local_source') . '/' . preg_replace('/[^\w.-]/', '_', (string) $ref['ref']) . '.tar.gz';
        if (!is_file($src)) {
            throw new XneException("the local release folder has no " . basename($src));
        }
        Fs::atomicCopy($src, $file, 0644);
    }
}
