<?php
declare(strict_types=1);

namespace Xne\Method;

use Xne\Proc;
use Xne\XneException;

/**
 * git fast-forward for installs that are git checkouts: fetch the target commit from the install's own remote (or
 * from the release source's repository URL), refuse over local changes in tracked files and over anything that is
 * not a fast-forward, then "git merge --ff-only". The rollback is "git reset --keep <the commit before>", which
 * refuses over local changes too. git is needed only for this method.
 */
final class Git
{
    /** @var string */
    private $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    /** @return array{exit: int, out: string, err: string, ms: int, timeout: bool} */
    public function git(array $args, int $timeout = 600): array
    {
        return Proc::run(array_merge(self::command($this->root), $args), null, ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C'], $timeout);
    }

    /**
     * The git command line for the checkout at $root. xne runs as root on an install another user owns (the site's
     * owner), and git refuses such a checkout as "dubious ownership" unless it is a safe.directory: every call then
     * fails, the checkout looks clean and no commit can be fetched. The checkout xne was pointed at is trusted for
     * that one call (-c safe.directory=<its path>), never globally.
     * @return list<string>
     */
    public static function command(string $root): array
    {
        // --no-optional-locks: a read such as git status as root must not rewrite .git/index (it would leave it
        // owned by root in the site owner's checkout)
        $cmd = ['git', '--no-optional-locks'];
        foreach (array_unique([$root, (string) realpath($root)]) as $dir) {
            if ($dir !== '') {
                $cmd[] = '-c';
                $cmd[] = 'safe.directory=' . $dir;
            }
        }
        return array_merge($cmd, ['-C', $root]);
    }

    public function head(): string
    {
        $r = $this->git(['rev-parse', 'HEAD']);
        return trim($r['out']);
    }

    public function has(string $sha): bool
    {
        return $this->git(['cat-file', '-e', $sha . '^{commit}'])['exit'] === 0;
    }

    /** Makes $sha available locally: from the install's remotes first, then from $url. */
    public function fetch(string $sha, string $ref, string $url): void
    {
        if ($sha !== '' && $this->has($sha)) {
            return;
        }
        $remotes = array_filter(explode("\n", trim($this->git(['remote'])['out'])), 'strlen');
        foreach ($remotes as $r) {
            $this->git(['fetch', '--quiet', '--tags', $r], 1800);
            if ($sha !== '' && $this->has($sha)) {
                return;
            }
        }
        if ($url !== '') {
            $x = $this->git(['fetch', '--quiet', $url, $sha !== '' ? $sha : $ref], 1800);
            if ($x['exit'] === 0 && ($sha === '' || $this->has($sha))) {
                return;
            }
        }
        throw new XneException("the commit $ref ($sha) cannot be fetched into the install's git checkout");
    }

    /** @return list<string> problems that refuse a fast-forward to $sha */
    public function check(string $sha): array
    {
        $p = [];
        // rtrim: a porcelain line may start with a space (" M path"), trim() would cut the first path
        $st = rtrim($this->git(['status', '--porcelain', '--untracked-files=no'])['out']);
        if ($st !== '') {
            $p[] = 'local changes in tracked files: ' . implode(', ', array_slice(array_map(static function ($l) {
                return trim(substr($l, 3));
            }, explode("\n", $st)), 0, 10));
        }
        if ($sha !== '' && $this->has($sha) && $this->git(['merge-base', '--is-ancestor', 'HEAD', $sha])['exit'] !== 0) {
            $p[] = 'HEAD has commits the target lacks (not a fast-forward)';
        }
        return $p;
    }

    /** @return list<string> the files that differ between HEAD and $sha */
    public function changed(string $sha): array
    {
        $r = $this->git(['diff', '--name-only', 'HEAD', $sha]);
        return array_values(array_filter(explode("\n", trim($r['out'])), 'strlen'));
    }

    public function fastForward(string $sha): void
    {
        $r = $this->git(['merge', '--ff-only', '--quiet', $sha], 1800);
        if ($r['exit'] !== 0) {
            throw new XneException('git merge --ff-only failed: ' . trim($r['err']));
        }
        if ($this->head() !== $sha) {
            throw new XneException('HEAD is not the target after the fast-forward');
        }
    }

    public function resetKeep(string $sha): void
    {
        $r = $this->git(['reset', '--keep', '--quiet', $sha], 1800);
        if ($r['exit'] !== 0) {
            throw new XneException('git reset --keep failed: ' . trim($r['err']));
        }
    }

    /** The commit of a tag in the checkout ("git revert by tag", owner Q17). */
    public function tagCommit(string $tag): string
    {
        $r = $this->git(['rev-parse', '--verify', '--quiet', $tag . '^{commit}']);
        if ($r['exit'] !== 0) {
            throw \Xne\XneException::usage("no tag $tag in the install's git checkout");
        }
        return trim($r['out']);
    }
}
