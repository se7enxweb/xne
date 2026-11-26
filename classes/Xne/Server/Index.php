<?php
declare(strict_types=1);

namespace Xne\Server;

use Xne\Config;
use Xne\Source\Ranker;
use Xne\Version;

/**
 * The release index the server answers from: every release tag (ref, commit, version, stable or not) and the branch
 * heads of main, built from the same ranked sources as the client (GitLab mirror, GitHub, Packagist), so a client
 * check needs one request. Rebuilt by "xne server:index" (a timer), kept in index.json.
 */
final class Index
{
    /** @return array<string,mixed> */
    public static function build(Config $cfg): array
    {
        $r = new Ranker($cfg);
        [$src, $refs] = $r->first(true);
        $releases = [];
        $branches = [];
        foreach ($refs as $ref) {
            if ($ref['kind'] === 'tag' && $ref['version'] !== null && Version::valid((string) $ref['version'])) {
                $releases[] = ['ref' => $ref['ref'], 'sha' => $ref['sha'], 'version' => (string) $ref['version'],
                    'stable' => Version::isStable((string) $ref['version'])];
            } elseif ($ref['kind'] === 'branch' && in_array($ref['ref'], ['main', 'master'], true)) {
                $branches[] = ['ref' => $ref['ref'], 'sha' => $ref['sha']];
            }
        }
        usort($releases, static function ($a, $b) {
            return Version::compare($b['version'], $a['version']);
        });
        return ['built' => gmdate('c'), 'source' => $src->name(), 'sources' => $r->report, 'releases' => $releases, 'branches' => $branches];
    }

    /**
     * What each channel offers an install of $current. @param array<string,mixed> $index
     * @return array<string, array<string,mixed>|null>
     */
    public static function channels(array $index, string $current): array
    {
        $refs = [];
        foreach ($index['releases'] as $rel) {
            $refs[] = ['ref' => $rel['ref'], 'kind' => 'tag', 'sha' => $rel['sha'], 'version' => $rel['version']];
        }
        foreach ($index['branches'] as $b) {
            $refs[] = ['ref' => $b['ref'], 'kind' => 'branch', 'sha' => $b['sha'], 'version' => null];
        }
        $out = [];
        foreach (Ranker::CHANNELS as $c) {
            try {
                $t = Ranker::target($refs, $current, $c);
                $out[$c] = $t === null ? null : ['ref' => $t['ref'], 'sha' => $t['sha'], 'version' => $t['version']];
            } catch (\Throwable $e) {
                $out[$c] = null;
            }
        }
        return $out;
    }
}
