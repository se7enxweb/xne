<?php
declare(strict_types=1);

namespace Xne\Source;

use Xne\Config;
use Xne\Version;
use Xne\XneException;

/**
 * Source ranking (owner Q5): the sources in the order of the "sources" setting (gitlab, github, packagist by
 * default); the first one that answers is used, the others are reported with the reason they were skipped. Target
 * selection by channel (Q18): stable, beta (alpha/beta/rc too), main (the nightly branch, F2), security (the newest
 * stable release of the install's own x.y line), or an explicit --to ref.
 */
final class Ranker
{
    public const CHANNELS = ['stable', 'beta', 'main', 'security'];

    /** @var Config */
    private $cfg;
    /** @var list<array{source: string, status: string}> */
    public $report = [];

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
    }

    public static function make(string $name, Config $cfg): Source
    {
        switch ($name) {
            case 'gitlab':
                return new GitLab($cfg);
            case 'github':
                return new GitHub($cfg);
            case 'packagist':
                return new Packagist($cfg);
            case 'local':
                return new Local($cfg);
        }
        throw XneException::usage("unknown source $name (gitlab, github, packagist, local)");
    }

    /** @return array{0: Source, 1: list<array<string,mixed>>} the first usable source and its refs */
    public function first(bool $refresh = false): array
    {
        $this->report = [];
        $last = '';
        foreach ($this->cfg->list('sources') as $name) {
            $src = self::make($name, $this->cfg);
            $why = $src->unavailable();
            if ($why !== '') {
                $this->report[] = ['source' => $name, 'status' => 'skipped: ' . $why];
                continue;
            }
            try {
                $refs = $src->refs($refresh);
            } catch (XneException $e) {
                $this->report[] = ['source' => $name, 'status' => 'failed: ' . $e->getMessage()];
                $last = $e->getMessage();
                continue;
            }
            if (!$refs) {
                $this->report[] = ['source' => $name, 'status' => 'failed: no refs'];
                continue;
            }
            $this->report[] = ['source' => $name, 'status' => 'used (' . count($refs) . ' refs)'];
            foreach (array_slice($this->cfg->list('sources'), count($this->report)) as $rest) {
                $this->report[] = ['source' => $rest, 'status' => 'not needed'];
            }
            return [$src, $refs];
        }
        throw new XneException('no release source answered' . ($last !== '' ? ": $last" : ''));
    }

    /**
     * The target ref for $current on $channel, or the ref named $to. Null when the install is up to date.
     * @param list<array<string,mixed>> $refs
     * @return array<string,mixed>|null
     */
    public static function target(array $refs, string $current, string $channel, string $to = ''): ?array
    {
        if ($to !== '') {
            foreach ($refs as $r) {
                if ($r['ref'] === $to || ($r['kind'] === 'tag' && ltrim($r['ref'], 'vV') === ltrim($to, 'vV'))) {
                    return $r;
                }
            }
            if (preg_match('/^[0-9a-f]{40}$/', $to)) {
                return ['ref' => $to, 'kind' => 'commit', 'sha' => $to, 'version' => null];
            }
            throw XneException::usage("no tag or branch $to in the release source");
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw XneException::usage("unknown channel $channel (" . implode(', ', self::CHANNELS) . ')');
        }
        if ($channel === 'main') {
            foreach (['main', 'master'] as $b) {
                foreach ($refs as $r) {
                    if ($r['kind'] === 'branch' && $r['ref'] === $b) {
                        return $r;
                    }
                }
            }
            throw new XneException('the release source has no main branch');
        }
        $best = null;
        foreach ($refs as $r) {
            if ($r['kind'] !== 'tag' || $r['version'] === null || !Version::valid((string) $r['version'])) {
                continue;
            }
            $v = (string) $r['version'];
            if (Version::major($v) !== Version::major($current)) {
                continue;
            }
            if (($channel === 'stable' || $channel === 'security') && !Version::isStable($v)) {
                continue;
            }
            if ($channel === 'security' && Version::line($v) !== Version::line($current)) {
                continue;
            }
            if (Version::compare($v, $current) <= 0) {
                continue;
            }
            if ($best === null || Version::compare($v, (string) $best['version']) > 0) {
                $best = $r;
            }
        }
        return $best;
    }

    /** The tag of exactly $version (v6.0.14 or 6.0.14), the base of a file patch. @param list<array<string,mixed>> $refs */
    public static function tagOf(array $refs, string $version): ?array
    {
        foreach ($refs as $r) {
            if ($r['kind'] === 'tag' && $r['version'] !== null && Version::valid((string) $r['version']) && Version::compare((string) $r['version'], $version) === 0) {
                return $r;
            }
        }
        return null;
    }
}
