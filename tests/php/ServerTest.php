<?php
declare(strict_types=1);

// Phase 2: signing, advisories, the key registry, the API v1 and the client check/report/notify against a real
// HTTP server (PHP's built-in server on a free local port, serving server/public/index.php). No network.

use Xne\Server\Advisories;
use Xne\Server\Api;
use Xne\Server\Registry;
use Xne\Server\Store;
use Xne\Signer;

/** A server data folder with a release index and one advisory. */
function t_server_data(string $dir): Store
{
    $st = new Store($dir);
    $st->write('index.json', ['built' => gmdate('c'), 'source' => 'test', 'sources' => [], 'branches' => [['ref' => 'main', 'sha' => str_repeat('a', 40)]],
        'releases' => [
            ['ref' => 'v6.0.15', 'sha' => str_repeat('c', 40), 'version' => '6.0.15', 'stable' => true],
            ['ref' => 'v6.0.14', 'sha' => str_repeat('b', 40), 'version' => '6.0.14', 'stable' => true],
        ]], 0644);
    $st->write('advisories.json', [['id' => 'XNE-2026-001', 'title' => 'test advisory', 'severity' => 'high', 'affected' => '>=6.0.0 <6.0.15', 'fixed_in' => '6.0.15']], 0644);
    return $st;
}

/** Starts php -S for server/public on a free port; returns [url, process]. */
function t_server_start(string $data): array
{
    $home = dirname(__DIR__, 2);
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
    fclose($s);
    // the whole environment: Windows cannot open a socket in a process without SystemRoot
    $env = ['XNE_SERVER_DATA' => $data] + (array) getenv();
    \Xne\Fs::mkdir($data);
    $p = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $home . '/server/public/index.php'], [1 => ['file', $data . '/php-server.log', 'a'], 2 => ['file', $data . '/php-server.log', 'a']], $pipes, $home, $env);
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($c) {
            fclose($c);
            break;
        }
        usleep(100000);
    }
    return ['http://127.0.0.1:' . $port . '/api/v1', $p];
}

return [
    'signatures verify, a changed body, an old time or a wrong secret fail' => static function (): void {
        $k = Signer::newKey();
        $h = Signer::headers($k['id'], $k['secret'], 'POST', '/api/v1/check', '{"a":1}', 1000);
        t_true(Signer::verify($k['secret'], 'POST', '/api/v1/check', $h['X-Xne-Time'], '{"a":1}', $h['X-Xne-Signature'], 1100));
        t_true(!Signer::verify($k['secret'], 'POST', '/api/v1/check', $h['X-Xne-Time'], '{"a":2}', $h['X-Xne-Signature'], 1100), 'body');
        t_true(!Signer::verify($k['secret'], 'POST', '/api/v1/check', $h['X-Xne-Time'], '{"a":1}', $h['X-Xne-Signature'], 1400), 'replay');
        t_true(!Signer::verify(str_repeat('0', 64), 'POST', '/api/v1/check', $h['X-Xne-Time'], '{"a":1}', $h['X-Xne-Signature'], 1100), 'secret');
    },
    'advisory ranges' => static function (): void {
        t_true(Advisories::matches('>=6.0.0 <6.0.15', '6.0.14'));
        t_true(!Advisories::matches('>=6.0.0 <6.0.15', '6.0.15'));
        t_true(Advisories::matches('=6.0.4.10', '6.0.4.10'));
        t_true(!Advisories::matches('', '6.0.1'));
    },
    'the registry issues 0600 keys, lists them without secrets, revokes' => static function (): void {
        $s = t_scratch('reg');
        $r = new Registry(new Store($s . '/data'));
        $k = $r->issue('acme', 'health', $s . '/k.txt');
        t_mode(0600, $s . '/k.txt');
        t_mode(0600, $s . '/data/keys/' . $k['id'] . '.json');
        $line = trim((string) file_get_contents($s . '/k.txt'));
        t_true(strpos(json_encode($r->list()), explode(':', $line)[1]) === false, 'no secret in the list');
        t_throws(\Xne\XneException::class, static function () use ($r, $s): void {
            $r->issue('acme', 'health', $s . '/k.txt');
        }, 'never overwrites a key file');
        $r->revoke($k['id']);
        t_same(null, $r->get($k['id']));
    },
    'API v1: status, releases, advisories, anonymous and signed check, report by level, refusals' => static function (): void {
        $s = t_scratch('api');
        $st = t_server_data($s . '/data');
        $api = new Api($st);
        t_same(200, $api->handle('GET', '/api/v1/status', [], '')[0]);
        t_same(\Xne\Console\App::VERSION, $api->handle('GET', '/api/v1/status', [], '')[1]['version'], 'status reports the xne version');
        t_same(2, count($api->handle('GET', '/api/v1/releases', [], '')[1]['releases']));
        t_same('XNE-2026-001', $api->handle('GET', '/api/v1/advisories?version=6.0.14', [], '')[1]['advisories'][0]['id']);
        [$c, $j] = $api->handle('POST', '/api/v1/check', [], '{"version":"6.0.14"}');
        t_same(200, $c);
        t_same('v6.0.15', $j['channels']['stable']['ref']);
        t_true($j['security'] && $j['upgrade_available'] && !$j['keyed']);
        t_same(['6.0.14' => 1], $st->read('counts/checks-' . gmdate('Ymd') . '.json'));
        t_same(400, $api->handle('POST', '/api/v1/check', [], 'nope')[0]);
        t_same(404, $api->handle('GET', '/api/v1/nope', [], '')[0]);
        t_same(401, $api->handle('POST', '/api/v1/report', [], '{"version":"6.0.14"}')[0], 'anonymous report');
        $reg = new Registry($st);
        $k = $reg->issue('acme', 'health', $s . '/k.txt');
        [$id, $secret] = explode(':', trim((string) file_get_contents($s . '/k.txt')));
        $body = '{"version":"6.0.14","php":"8.5","extensions":[{"name":"x"}],"content":"never"}';
        $h = array_change_key_case(Signer::headers($id, $secret, 'POST', '/api/v1/report', $body), CASE_LOWER);
        [$c, $j] = $api->handle('POST', '/api/v1/report', $h, $body);
        t_same(200, $c, json_encode($j));
        $stored = $st->read('installs/' . $id . '/report-' . gmdate('Ymd') . '.json');
        t_same(['version' => '6.0.14', 'php' => '8.5'], $stored['report'], 'health level keeps no extensions, nothing unknown');
        $h['x-xne-signature'] = str_repeat('0', 64);
        t_same(401, $api->handle('POST', '/api/v1/report', $h, $body)[0], 'bad signature');
    },
    'client: key:set from stdin, check through a live server (signed) with the notify hooks, report, fallback' => static function (): void {
        [$s, $rel, $env] = t_world('net');
        $root = t_install_from($rel, 'v6.0.14', $s . '/site', '6.0.14');
        $st = t_server_data($s . '/data');
        $k = (new Registry($st))->issue('acme', 'full', $s . '/k.txt');
        [$url, $proc] = t_server_start($s . '/data');
        try {
            $fakes = dirname(__DIR__) . '/fakes';
            $hook = '"' . t_fake_bin($fakes . '/notify-hook') . '"';
            $env += ['XNE_NETWORK_URL' => $url, 'XNE_FAKE_LOG' => $s . '/notify.log', 'XNE_REPORT_LEVEL' => 'full',
                'XNE_NOTIFY_PROBLEM_CMD' => $hook . ' problem --key {key} --title {title} --severity {severity} --unknown {nope}',
                'XNE_NOTIFY_OK_CMD' => $hook . ' ok --key {key}',
                'XNE_NOTIFY_EVENT_CMD' => $hook . " event 'a word with spaces' --body {body}"];
            [$x, , $err] = t_xne(['key:set', '--root=' . $root, (string) file_get_contents($s . '/k.txt')], $env);
            t_same(2, $x, 'a key given as an argument is not read: key:set reads stdin only');
            [$x, $out, $err, $j] = t_xne(['key:set', '--root=' . $root, '--json'], $env, null, (string) file_get_contents($s . '/k.txt'));
            t_same(0, $x, $out . $err);
            t_true(strpos($out, explode(':', trim((string) file_get_contents($s . '/k.txt')))[1]) === false, 'the secret is never printed');
            [$x, $out, $err, $j] = t_xne(['check', '--root=' . $root, '--json'], $env);
            t_same(0, $x, $out . $err);
            t_same('server', $j['result']['via']);
            t_true($j['result']['keyed'] && $j['result']['security']);
            t_same('XNE-2026-001', $j['result']['advisories'][0]['id']);
            $log = array_map('json_decode', file($s . '/notify.log', FILE_IGNORE_NEW_LINES), array_fill(0, 2, true));
            t_same(['problem hook problem', 'event hook problem'], $j['result']['notified']);
            t_same('state=problem', $log[0][0], 'the hook gets the values in its environment too');
            t_same('problem', $log[0][1]);
            t_same('crit', $log[0][array_search('--severity', $log[0], true) + 1]);
            t_true(strpos($log[0][array_search('--title', $log[0], true) + 1], 'security advisory') !== false, 'one word, spaces kept');
            t_same('{nope}', $log[0][array_search('--unknown', $log[0], true) + 1], 'an unknown placeholder stays');
            t_same('event', $log[1][1]);
            t_same('a word with spaces', $log[1][2]);
            t_same('6.0.14', json_decode($log[1][array_search('--body', $log[1], true) + 1], true)['version']);
            [$x, $out, $err, $j] = t_xne(['check', '--root=' . $root, '--json', '--set', 'notify=none'], $env);
            t_same([], $j['result']['notified'], 'notify=none runs no hook');
            t_true(is_file($s . '/data/installs/' . $k['id'] . '/last-check.json'), 'the signed check is kept for the install');
            [$x, $out, $err] = t_xne(['report', '--root=' . $root], $env);
            t_same(0, $x, $out . $err);
            $rep = (new Store($s . '/data'))->read('installs/' . $k['id'] . '/report-' . gmdate('Ymd') . '.json');
            t_true(isset($rep['report']['extensions']) && !isset($rep['report']['content']), 'full level: extensions, never content');
            (new Registry($st))->revoke($k['id']);
            [$x] = t_xne(['check', '--root=' . $root, '--no-notify'], $env);
            t_same(3, $x, 'a revoked key is refused');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
        [$x, $out, $err, $j] = t_xne(['check', '--root=' . $root, '--no-notify', '--json'], $env + ['XNE_NETWORK_URL' => 'http://127.0.0.1:9/api/v1']);
        t_same(0, $x, $out . $err);
        t_same('source local', $j['result']['via'], 'falls back to the release sources');
        t_true(isset($j['result']['server_error']));
    },
    'server:mirror copies branches and tags, and never forces a moved tag' => static function (): void {
        $s = t_scratch('mirror');
        $g = static function (array $a, ?string $cwd = null): void {
            $r = \Xne\Proc::run(array_merge(['git'], $a), $cwd, ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.com']);
            t_same(0, $r['exit'], implode(' ', $a) . ': ' . $r['err']);
        };
        $g(['init', '-q', '-b', 'main', $s . '/src']);
        file_put_contents($s . '/src/a.txt', "1\n");
        $g(['add', 'a.txt'], $s . '/src');
        $g(['commit', '-q', '-m', 'one'], $s . '/src');
        $g(['tag', 'v6.0.1'], $s . '/src');
        $g(['init', '-q', '--bare', $s . '/dst.git']);
        $env = ['XNE_CACHE_DIR' => $s . '/cache', 'XNE_MIRROR_FROM' => $s . '/src', 'XNE_MIRROR_TO' => $s . '/dst.git', 'XNE_CONF' => $s . '/none'];
        [$x, $out, $err] = t_xne(['server:mirror'], $env);
        t_same(0, $x, $out . $err);
        $refs = \Xne\Proc::run(['git', '-C', $s . '/dst.git', 'show-ref'])['out'];
        t_true(strpos($refs, 'refs/heads/main') !== false && strpos($refs, 'refs/tags/v6.0.1') !== false, $refs);
        file_put_contents($s . '/src/a.txt', "2\n");
        $g(['commit', '-q', '-am', 'two'], $s . '/src');
        $g(['tag', '-f', 'v6.0.1'], $s . '/src');
        [$x, $out] = t_xne(['server:mirror'], $env);
        t_same(1, $x, 'a moved tag is reported, not forced');
        t_true(strpos($out . '', 'tag not overwritten') !== false || $x === 1);
    },
    'Mirror::sync: dry run changes nothing, seed auto by size, bad seed refused' => static function (): void {
        $s = t_scratch('mirror-sync');
        $g = static function (array $a, ?string $cwd = null): void {
            $r = \Xne\Proc::run(array_merge(['git'], $a), $cwd, ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.com']);
            t_same(0, $r['exit'], implode(' ', $a) . ': ' . $r['err']);
        };
        $g(['init', '-q', '-b', 'main', $s . '/src']);
        foreach (['1', '2', '3'] as $i) {
            file_put_contents($s . '/src/a.txt', "$i\n");
            $g(['add', 'a.txt'], $s . '/src');
            $g(['commit', '-q', '-m', "c$i"], $s . '/src');
            $g(['tag', "v$i"], $s . '/src');
        }
        $g(['init', '-q', '--bare', $s . '/dst.git']);
        $r = \Xne\Server\Mirror::sync($s . '/src', $s . '/dst.git', $s . '/cache/src.git', ['dry_run' => true, 'seed' => 'auto']);
        t_true($r['cloned'] && $r['dry_run']);
        t_same('', \Xne\Proc::run(['git', '-C', $s . '/dst.git', 'show-ref'])['out'], 'a dry run pushes nothing');
        $r = \Xne\Server\Mirror::sync($s . '/src', $s . '/dst.git', $s . '/cache/src.git', ['seed' => 'auto', 'seed_min_kb' => 1 << 30]);
        t_same(0, $r['seeded'], 'a small repository is not seeded tag by tag');
        t_true(!$r['cloned'] && $r['size_kb'] >= 0);
        $refs = \Xne\Proc::run(['git', '-C', $s . '/dst.git', 'show-ref'])['out'];
        t_true(strpos($refs, 'refs/heads/main') !== false && strpos($refs, 'refs/tags/v3') !== false, $refs);
        $g(['init', '-q', '--bare', $s . '/dst2.git']);
        $r = \Xne\Server\Mirror::sync($s . '/src', $s . '/dst2.git', $s . '/cache/src.git', ['seed' => 'auto', 'seed_min_kb' => 0]);
        t_same(3, $r['seeded'], 'at the size limit every tag goes alone, oldest first');
        $g(['init', '-q', '--bare', $s . '/empty.git']);
        $r = \Xne\Server\Mirror::sync($s . '/empty.git', $s . '/dst.git', $s . '/cache/empty.git', ['seed' => 'auto']);
        t_true($r['empty'] && $r['tags']['rejected'] === [], 'an empty source is a success with nothing pushed');
        $g(['init', '-q', '--bare', $s . '/dst3.git']);
        $r = \Xne\Server\Mirror::sync($s . '/src', $s . '/dst3.git', $s . '/cache/local.git', ['push' => false]);
        t_true($r['cloned'] && $r['pushed'] === false && is_dir($s . '/cache/local.git'));
        t_same('', \Xne\Proc::run(['git', '-C', $s . '/dst3.git', 'show-ref'])['out'], 'push=false keeps a local copy only');
        t_throws(\Xne\XneException::class, static function () use ($s): void {
            \Xne\Server\Mirror::sync($s . '/src', $s . '/dst.git', $s . '/cache/src.git', ['seed' => 'sometimes']);
        });
    },
    'server_peers: an issued and a revoked key reach the peer over ssh stdin' => static function (): void {
        [$s, $rel, $env] = t_world('peers');
        $env['XNE_SERVER_DATA'] = $s . '/a';
        $env['XNE_PEER_DATA'] = $s . '/b';
        $env['XNE_SERVER_PEERS'] = 'web';
        $env['PATH'] = dirname(__DIR__) . '/fakes' . PATH_SEPARATOR . getenv('PATH');
        [$x, $out, $err, $j] = t_xne(['server:key:issue', '--account=acme', '--level=full', '--out=' . $s . '/k.txt', '--json'], $env);
        t_same(0, $x, $out . $err);
        t_same(['web' => 'ok'], $j['result']['replicated']);
        $id = $j['result']['id'];
        t_mode(0600, $s . '/b/keys/' . $id . '.json');
        t_same((new Store($s . '/a'))->read('keys/' . $id . '.json'), (new Store($s . '/b'))->read('keys/' . $id . '.json'));
        [$x] = t_xne(['server:key:revoke', $id], $env);
        t_same(0, $x);
        t_true(!empty((new Store($s . '/b'))->read('keys/' . $id . '.json')['revoked']), 'the revoke reached the peer');
        [$x] = t_xne(['server:key:import'], $env, null, '{"id":"nope"}');
        t_same(2, $x, 'a bad record is refused');
    },
    'server:key:provision issues a key and installs it on the domain\'s server, fingerprints matching' => static function (): void {
        [$s, $rel, $env] = t_world('prov');
        $root = t_install_from($rel, 'v6.0.14', $s . '/vh/acme/doc/example.com', '6.0.14');
        $env += ['XNE_SERVER_DATA' => $s . '/data', 'XNE_PEER_DATA' => $s . '/peer', 'XNE_VHOSTS_DIR' => $s . '/vh',
            'PATH' => dirname(__DIR__) . '/fakes' . PATH_SEPARATOR . getenv('PATH')];
        [$x, $out, $err, $j] = t_xne(['server:key:provision', '--account=kc42', '--level=health', '--domain=example.com', '--target=db', '--json'], $env);
        t_same(0, $x, $out . $err);
        t_true($j['result']['installed']);
        $line = '';
        foreach ((array) glob($s . '/data/issued/example.com-*.key') as $f) {
            t_mode(0600, (string) $f);
            $line = trim((string) file_get_contents((string) $f));
        }
        t_true(strpos($out . $err, explode(':', $line)[1]) === false, 'the secret is never printed');
        [$x, $out, , $j] = t_xne(['key:status', '--domain=example.com', '--json'], $env);
        t_same(0, $x, $out);
        t_same(\Xne\Fs::slashes($root), \Xne\Fs::slashes((string) realpath($root)));
        t_same(explode(':', $line)[0], $j['result']['key']);
        [$x] = t_xne(['server:key:provision', '--account=kc42', '--level=health', '--domain=nope.example', '--target=db'], $env);
        t_same(1, $x, 'no install for the domain: issued, not installed, FAIL');
        [$x] = t_xne(['server:key:provision', '--account=kc42', '--level=health', '--domain=example.com', '--target=db;reboot'], $env);
        t_same(2, $x, 'a target with shell characters is refused');
    },
    'server:key:issue, server:key:list (no secret), server:index on a local source' => static function (): void {
        [$s, $rel, $env] = t_world('srvcli');
        $env['XNE_SERVER_DATA'] = $s . '/data';
        [$x, $out, $err, $j] = t_xne(['server:key:issue', '--account=acme', '--level=health', '--out=' . $s . '/k.txt', '--json'], $env);
        t_same(0, $x, $out . $err);
        $secret = explode(':', trim((string) file_get_contents($s . '/k.txt')))[1];
        [$x, $out] = t_xne(['server:key:list', '--json'], $env);
        t_true(strpos($out, $secret) === false);
        [$x, $out, $err, $j] = t_xne(['server:index', '--json'], $env);
        t_same(0, $x, $out . $err);
        t_same(4, $j['result']['releases'], 'v6.0.13, v6.0.14, v6.0.15, v6.0.16beta1 (main is a branch)');
    },
];
