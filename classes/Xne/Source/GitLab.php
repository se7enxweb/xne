<?php
declare(strict_types=1);

namespace Xne\Source;

use Xne\Http;

/**
 * GitLab (gitlab.com or a self-hosted GitLab): the tags and branches of gitlab_project through the API v4, the archive
 * as .tar.gz. Ranked first when it is configured; skipped (with the reason in the plan) when it is not.
 */
final class GitLab extends Source
{
    public function name(): string
    {
        return 'gitlab';
    }

    protected function identity(): string
    {
        return $this->cfg->get('gitlab_url') . '/' . $this->cfg->get('gitlab_project');
    }

    public function unavailable(): string
    {
        return $this->cfg->get('gitlab_project') === '' ? 'gitlab_project is not set' : '';
    }

    private function api(): string
    {
        return rtrim($this->cfg->get('gitlab_url'), '/') . '/api/v4/projects/' . rawurlencode($this->cfg->get('gitlab_project'));
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return $this->authHeader('gitlab_token_file', 'PRIVATE-TOKEN', 'XNE_GITLAB_TOKEN');
    }

    protected function fetchRefs(): array
    {
        $out = [];
        foreach (['tags' => 'tag', 'branches' => 'branch'] as $path => $kind) {
            for ($page = 1; $page <= 20; $page++) {
                $list = Http::getJson($this->api() . "/repository/$path?per_page=100&page=$page", $this->headers());
                foreach ($list as $t) {
                    $out[] = ['ref' => (string) $t['name'], 'kind' => $kind, 'sha' => (string) ($t['commit']['id'] ?? ''), 'version' => null];
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
        $what = $ref['sha'] !== '' ? $ref['sha'] : $ref['ref'];
        Http::download($this->api() . '/repository/archive.tar.gz?sha=' . rawurlencode($what), $file, $this->headers());
    }
}
