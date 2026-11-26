<?php
declare(strict_types=1);

namespace Xne\Server;

use Xne\Config;
use Xne\Fs;
use Xne\Proc;
use Xne\XneException;

/**
 * The GitLab release mirror (owner Q5: GitLab ranks first): a bare mirror of mirror_from (GitHub
 * se7enxweb/exponential) in <cache_dir>/mirror/<name>.git, fetched with --prune, then its branches and tags pushed to
 * mirror_to (the GitLab project). Only refs/heads/* and refs/tags/* travel (no pull-request refs). A tag that differs
 * on GitLab is never forced: tags are permanent, so a moved tag is reported, not overwritten. Needs git.
 *
 * The core is sync(from, to, dir, options), shared with other mirror tools (a tool that mirrors whole GitHub
 * accounts can use it); run() is the xne server:mirror use of it and behaves as before.
 */
final class Mirror
{
    /** @return array<string,mixed> */
    public static function run(Config $cfg, bool $dryRun): array
    {
        $from = $cfg->get('mirror_from');
        $to = $cfg->get('mirror_to');
        if ($from === '' || $to === '') {
            throw XneException::usage('mirror_from and mirror_to must be set');
        }
        $dir = Fs::join($cfg->get('cache_dir'), 'mirror', preg_replace('/[^\w.-]/', '_', basename($from, '.git')) . '.git');
        return self::sync($from, $to, $dir, ['dry_run' => $dryRun, 'seed' => 'always']);
    }

    /**
     * Mirrors the branches and tags of $from to $to through the bare cache $dir (cloned when missing).
     *
     * Options:
     *  - dry_run (bool, false): fetch, then push with --dry-run (nothing changes on $to).
     *  - seed (string, 'always'): 'always' pushes the tags missing on $to one by one, oldest first, before the normal
     *    push; 'auto' does that only when the cache is at least seed_min_kb, or when the normal tag push fails;
     *    'never' skips it.
     *  - seed_min_kb (int, 512000): the cache size from which 'auto' seeds tag by tag.
     *  - chunk_commits (int, 2000): when a branch push fails, each branch is pushed again in fast-forward steps of
     *    this many first-parent commits, oldest first, then the normal push is retried once.
     *  - timeout (int, 7200): seconds for one clone, fetch or push.
     *  - env (array<string,string>): extra environment for git (GIT_TERMINAL_PROMPT=0 and LC_ALL=C are always set).
     *  - push (bool, true): false keeps a local copy only: clone or fetch $dir, push nothing ($to is not used).
     *  An empty source (no branch, no tag) is fetched and reported as empty; nothing is pushed.
     *
     * @param array<string,mixed> $opt
     * @return array<string,mixed> from, to, dir, dry_run, cloned, size_kb, seeded, chunked, branches, tags
     */
    public static function sync(string $from, string $to, string $dir, array $opt = []): array
    {
        $dryRun = !empty($opt['dry_run']);
        $seed = isset($opt['seed']) ? (string) $opt['seed'] : 'always';
        if (!in_array($seed, ['always', 'auto', 'never'], true)) {
            throw XneException::usage("seed is always, auto or never, not $seed");
        }
        $seedMin = isset($opt['seed_min_kb']) ? (int) $opt['seed_min_kb'] : 512000;
        $chunk = max(1, isset($opt['chunk_commits']) ? (int) $opt['chunk_commits'] : 2000);
        $timeout = isset($opt['timeout']) ? (int) $opt['timeout'] : 7200;
        $env = ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C'];
        if (isset($opt['env']) && is_array($opt['env'])) {
            foreach ($opt['env'] as $k => $v) {
                $env[(string) $k] = (string) $v;
            }
        }
        $out = ['from' => $from, 'to' => $to, 'dir' => $dir, 'dry_run' => $dryRun, 'cloned' => false,
            'size_kb' => 0, 'seeded' => 0, 'chunked' => []];
        if (!is_dir($dir)) {
            Fs::mkdir(dirname($dir));
            $r = Proc::run(['git', 'clone', '--quiet', '--bare', $from, $dir], null, $env, $timeout);
            if ($r['exit'] !== 0) {
                throw new XneException('git clone --bare failed: ' . trim($r['err']));
            }
            Proc::run(['git', '-C', $dir, 'config', 'remote.origin.fetch', '+refs/heads/*:refs/heads/*'], null, $env);
            $out['cloned'] = true;
        }
        $r = Proc::run(['git', '-C', $dir, 'fetch', '--quiet', '--prune', 'origin', '+refs/heads/*:refs/heads/*', 'refs/tags/*:refs/tags/*'], null, $env, $timeout);
        if ($r['exit'] !== 0) {
            throw new XneException('git fetch failed: ' . trim($r['err']));
        }
        $out['size_kb'] = self::sizeKb($dir, $env);
        $out['empty'] = false;
        $refs = Proc::run(['git', '-C', $dir, 'for-each-ref', '--count=1', '--format=%(refname)', 'refs/heads', 'refs/tags'], null, $env, 600);
        if ($refs['exit'] === 0 && trim($refs['out']) === '') {
            // an empty source (no branch, no tag): nothing to push, and a push of nothing is refused by the remote
            $out['empty'] = true;
            $out['branches'] = ['exit' => 0, 'updated' => 0];
            $out['tags'] = ['exit' => 0, 'rejected' => []];
            return $out;
        }
        if (array_key_exists('push', $opt) && !$opt['push']) {
            // a local copy only (the bare cache is the mirror): fetched, nothing pushed
            $out['branches'] = ['exit' => 0, 'updated' => 0];
            $out['tags'] = ['exit' => 0, 'rejected' => []];
            $out['pushed'] = false;
            return $out;
        }
        $args = ['git', '-C', $dir, 'push', '--porcelain', '--prune', $to, '+refs/heads/*:refs/heads/*'];
        $tags = ['git', '-C', $dir, 'push', '--porcelain', $to, 'refs/tags/*:refs/tags/*'];
        if ($dryRun) {
            $args[] = '--dry-run';
            $tags[] = '--dry-run';
        }
        if (!$dryRun && ($seed === 'always' || ($seed === 'auto' && $out['size_kb'] >= $seedMin))) {
            $out['seeded'] = self::seed($dir, $to, $env, $timeout);
        }
        $h = Proc::run($args, null, $env, $timeout);
        if ($h['exit'] !== 0 && !$dryRun) {
            // one pack too large for one push: every branch again in fast-forward steps, then the normal push once more
            $out['chunked'] = self::chunkBranches($dir, $to, $env, $timeout, $chunk);
            $h = Proc::run($args, null, $env, $timeout);
        }
        $t = Proc::run($tags, null, $env, $timeout);
        if ($t['exit'] !== 0 && !$dryRun && $seed === 'auto' && $out['seeded'] === 0 && !self::rejected($t['out'])) {
            $out['seeded'] = self::seed($dir, $to, $env, $timeout);
            $t = Proc::run($tags, null, $env, $timeout);
        }
        $rejected = self::rejected($t['out']);
        $out['branches'] = ['exit' => $h['exit'], 'updated' => substr_count($h['out'], "\n") - 2];
        $out['tags'] = ['exit' => $t['exit'], 'rejected' => $rejected];
        if ($h['exit'] !== 0) {
            throw new XneException('pushing the branches failed: ' . trim($h['err']));
        }
        if ($t['exit'] !== 0 && !$rejected) {
            throw new XneException('pushing the tags failed: ' . trim($t['err']));
        }
        return $out;
    }

    /** @return list<string> the porcelain lines of refused refs (moved tags) */
    private static function rejected(string $porcelain): array
    {
        $rejected = [];
        foreach (explode("\n", $porcelain) as $line) {
            if (strncmp($line, '!', 1) === 0) {
                $rejected[] = trim($line);
            }
        }
        return $rejected;
    }

    /** @param array<string,string> $env */
    private static function sizeKb(string $dir, array $env): int
    {
        $r = Proc::run(['git', '-C', $dir, 'count-objects', '-v'], null, $env, 600);
        $kb = 0;
        if ($r['exit'] === 0 && preg_match_all('/^(size|size-pack): (\d+)$/m', $r['out'], $m)) {
            foreach ($m[2] as $v) {
                $kb += (int) $v;
            }
        }
        return $kb;
    }

    /**
     * The first push of a large repository can be refused by the remote (one pack of several GB). The tags missing on
     * the mirror are pushed one at a time, oldest first, so each push carries only what that release adds; a failing
     * tag is retried by the normal push afterwards. Nothing is forced.
     * @param array<string,string> $env
     * @return int the tags pushed one by one
     */
    private static function seed(string $dir, string $to, array $env, int $timeout = 3600): int
    {
        $remote = Proc::run(['git', '-C', $dir, 'ls-remote', '--tags', $to], null, $env, 600);
        if ($remote['exit'] !== 0) {
            return 0;
        }
        $have = [];
        foreach (explode("\n", trim($remote['out'])) as $line) {
            if (preg_match('#\trefs/tags/([^\^]+)$#', $line, $m)) {
                $have[$m[1]] = true;
            }
        }
        $n = 0;
        $local = Proc::run(['git', '-C', $dir, 'for-each-ref', '--sort=creatordate', '--format=%(refname:short)', 'refs/tags'], null, $env, 600);
        foreach (array_filter(explode("\n", trim($local['out'])), 'strlen') as $tag) {
            if (!isset($have[$tag])) {
                Proc::run(['git', '-C', $dir, 'push', '--quiet', $to, 'refs/tags/' . $tag . ':refs/tags/' . $tag], null, $env, $timeout);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Pushes every branch in fast-forward steps of $step first-parent commits, oldest first. Never forced: a step the
     * remote refuses ends that branch; the normal push afterwards reports what is still wrong.
     * @param array<string,string> $env
     * @return array<string,int> branch => steps pushed
     */
    private static function chunkBranches(string $dir, string $to, array $env, int $timeout, int $step): array
    {
        $done = [];
        $heads = Proc::run(['git', '-C', $dir, 'for-each-ref', '--format=%(refname:short)', 'refs/heads'], null, $env, 600);
        foreach (array_filter(explode("\n", trim($heads['out'])), 'strlen') as $branch) {
            $list = Proc::run(['git', '-C', $dir, 'rev-list', '--first-parent', '--reverse', 'refs/heads/' . $branch], null, $env, 1800);
            $commits = array_values(array_filter(explode("\n", trim($list['out'])), 'strlen'));
            $n = 0;
            for ($i = $step - 1; $i < count($commits); $i += $step) {
                $p = Proc::run(['git', '-C', $dir, 'push', '--quiet', $to, $commits[$i] . ':refs/heads/' . $branch], null, $env, $timeout);
                if ($p['exit'] !== 0) {
                    break;
                }
                $n++;
            }
            $done[$branch] = $n;
        }
        return $done;
    }
}
