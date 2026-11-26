<?php
declare(strict_types=1);

namespace Xne\Source;

use Xne\Http;
use Xne\XneException;

/**
 * Packagist: the versions of packagist_package (se7enxweb/exponential) from the composer/2.0 metadata (tags from
 * p2/<pkg>.json, branches from ~dev.json, minified entries expanded). The download is the dist of that commit, as
 * the GitHub tarball when the dist is a GitHub zipball (no zip extension needed).
 */
final class Packagist extends Source
{
    public function name(): string
    {
        return 'packagist';
    }

    protected function identity(): string
    {
        return $this->cfg->get('packagist_package');
    }

    public function unavailable(): string
    {
        return preg_match('#^[\w.-]+/[\w.-]+$#', $this->cfg->get('packagist_package')) ? '' : 'packagist_package is not set';
    }

    protected function fetchRefs(): array
    {
        $pkg = $this->cfg->get('packagist_package');
        $out = [];
        foreach (['' => 'tag', '~dev' => 'branch'] as $suffix => $kind) {
            $d = Http::getJson("https://repo.packagist.org/p2/$pkg$suffix.json");
            $prev = [];
            foreach ((array) ($d['packages'][$pkg] ?? []) as $entry) {
                $full = $entry + $prev;
                $prev = $full;
                $v = (string) ($full['version'] ?? '');
                $ref = $kind === 'branch' ? (string) preg_replace('/^dev-|\.x-dev$/', '', $v) : $v;
                if ($kind === 'branch' && substr($v, -6) === '.x-dev') {
                    $ref = substr($v, 0, -6);
                }
                $out[] = [
                    'ref' => $ref, 'kind' => $kind, 'sha' => (string) ($full['source']['reference'] ?? $full['dist']['reference'] ?? ''),
                    'version' => null, 'dist' => (string) ($full['dist']['url'] ?? ''),
                ];
            }
        }
        return $out;
    }

    public function download(array $ref, string $file): void
    {
        $url = (string) ($ref['dist'] ?? '');
        if (preg_match('#^https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/(.+)$#', $url, $m)) {
            Http::download('https://codeload.github.com/' . $m[1] . '/tar.gz/' . $m[2], $file);
            return;
        }
        throw new XneException('the Packagist dist of ' . $ref['ref'] . ' is not a GitHub archive; use the github source');
    }
}
