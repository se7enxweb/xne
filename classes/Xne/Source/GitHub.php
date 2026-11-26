<?php
declare(strict_types=1);

namespace Xne\Source;

use Xne\Http;

/** GitHub: the tags and branches of github_repo (se7enxweb/exponential), downloaded from codeload as .tar.gz. */
final class GitHub extends Source
{
    public function name(): string
    {
        return 'github';
    }

    protected function identity(): string
    {
        return $this->cfg->get('github_repo');
    }

    public function unavailable(): string
    {
        return preg_match('#^[\w.-]+/[\w.-]+$#', $this->cfg->get('github_repo')) ? '' : 'github_repo is not set';
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Accept' => 'application/vnd.github+json'] + $this->authHeader('github_token_file', 'Authorization', 'XNE_GITHUB_TOKEN', 'Bearer ');
    }

    protected function fetchRefs(): array
    {
        $repo = $this->cfg->get('github_repo');
        $out = [];
        foreach (['tags' => 'tag', 'branches' => 'branch'] as $path => $kind) {
            for ($page = 1; $page <= 20; $page++) {
                $list = Http::getJson("https://api.github.com/repos/$repo/$path?per_page=100&page=$page", $this->headers());
                foreach ($list as $t) {
                    $out[] = ['ref' => (string) $t['name'], 'kind' => $kind, 'sha' => (string) ($t['commit']['sha'] ?? ''), 'version' => null];
                }
                if (count($list) < 100) {
                    break;
                }
            }
        }
        return $out;
    }

    public function download(array $ref, string $file): void
    {
        $repo = $this->cfg->get('github_repo');
        $what = $ref['sha'] !== '' ? $ref['sha'] : $ref['ref'];
        Http::download("https://codeload.github.com/$repo/tar.gz/" . rawurlencode($what), $file, $this->authHeader('github_token_file', 'Authorization', 'XNE_GITHUB_TOKEN', 'Bearer '));
    }
}
