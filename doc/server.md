# Exponential Network server, keys, notifications and the GitLab release mirror (phase 2)

Last verified: 2026-11-24 (xne 0.2.0)

## The API v1

Code: `classes/Xne/Server/` (`Api`, `Index`, `Advisories`, `Registry`, `Store`, `Mirror`) behind
`server/public/index.php`. No framework: the same class runs behind the network.se7enx.com vhost, inside its site
module, or self-hosted. Data folder: `server_data` (`XNE_SERVER_DATA`, default `/var/lib/xne-server`, mode 0700).

| Request | Answer |
|---|---|
| `GET /api/v1/status` | `{ok, api: "xne/1", version, index_built, releases}` (version: the xne release, four parts, e.g. `0.3.0.0`) |
| `GET /api/v1/releases` | the release index: every release tag (ref, commit, version, stable) and the main branch head |
| `GET /api/v1/advisories?version=6.0.14` | the advisories whose range contains the version |
| `POST /api/v1/check` `{"version": "6.0.14"}` | `channels` (stable, beta, main, security: what each offers), `advisories`, `upgrade_available`, `security`, `keyed`. Anonymous: only the version is read and counted per day (owner Q13). Signed: also kept as the install's last check |
| `POST /api/v1/report` | signed only: the install's report, trimmed to the key's level (health: version, PHP, install type, database engine, manifest, health; full: also extensions, traps, backups, tools). Never content, users or settings values (Q12) |

Errors: 400 bad body, 401 bad or missing signature, 404 unknown path, 503 no index yet. Every answer is JSON with `ok`.

### Signing

A keyed client sends `X-Xne-Key: <id>`, `X-Xne-Time: <unix time>` and `X-Xne-Signature`, the HMAC-SHA256 with the
key's secret of `"<METHOD>\n<path>\n<time>\n<sha256 of the body>"` (`lib/Xne/Signer.php`). A signature older or newer
than 300 seconds is refused, so a captured request cannot be replayed later. The secret never travels.

### Data files

| File | What |
|---|---|
| `index.json` | the release index (`xne server:index`, timer `xne-server-index.timer`) |
| `advisories.json` | kept by the server's operator: `[{"id": "XNE-2026-001", "title": "...", "severity": "high", "affected": ">=6.0.0 <6.0.14", "fixed_in": "6.0.14", "url": "..."}]`; `affected` is a space-separated list of `>=`, `>`, `<=`, `<`, `=` conditions |
| `keys/<id>.json` | one install key (0600): id, secret, account, level, created, revoked |
| `installs/<key id>/last-check.json`, `report-<date>.json` | what keyed installs sent |
| `counts/checks-<date>.json` | anonymous checks per version and day |

### Running it

Self-host or a test: `XNE_SERVER_DATA=/var/lib/xne-server php -S 127.0.0.1:8090 server/public/index.php`.
Behind Apache or nginx: point `/api/v1/` at `server/public/index.php` (PHP-FPM) with `XNE_SERVER_DATA` in the pool's
environment. Going live on network.se7enx.com is a change of that site: it is done with the owner's go (the site
move comes first), not by xne.

## Install keys (owner Q11)

```bash
xne server:key:issue --account=<account> --level=health|full --out=/srv/keys/<install>.key   # on the API node
xne server:key:list                        # id, account, level, created, revoked, fingerprint; never a secret
xne server:key:revoke <id>                 # signed requests with it are refused from then on
xne key:set --root=<install> < /srv/keys/<install>.key   # on the install's server: stdin only, stored 0600
xne key:status --root=<install>            # id and fingerprint only
```

The key file holds `<id>:<secret>` once, mode 0600, and is never printed; carry it to the install's server over ssh
stdin, then remove it. On the client the key is `<state_dir>/installs/<install>/key.json`
(0600; a key file readable by others is refused). A provisioning system issues keys for its accounts through the same
registry (it calls `xne server:key:issue` or `xne server:key:provision`); inside an install, exp_network keeps the key with the kernel's expSecrets
(phase 3).

## Check, report and notifications (owner Q15)

```bash
xne check  --root=<install> [--no-notify] [--json]   # channels, advisories; signed when a key is set
xne report --root=<install> [--dry-run]              # the report at report_level (needs a key)
```

`check` asks `network_url` (default `https://network.se7enx.com/api/v1`); when the server does not answer it falls
back to the release sources (no advisories then) and says so in `server_error`. A refused key is exit 3. Then it
runs the notify hooks (`notify=auto`, the default; `notify=none` or `--no-notify` runs none).

### Notify hooks

Three settings name commands that `xne check` starts, so any alerting, chat or ticket tool can be plugged in:

| Setting | Runs | Typical use |
|---|---|---|
| `notify_problem_cmd` | on every check while an upgrade or a security advisory is open | open (or refresh) an incident; repeats fold on `{key}` |
| `notify_ok_cmd` | on every check when the install is clean | resolve the incident `{key}` opened |
| `notify_event_cmd` | once per check while something is open | post an event to a chat or a bus |

A hook is a command line split into words at whitespace (`"..."` or `'...'` keep spaces in one word) and started
directly, never through a shell, with a 60-second limit. In each word these placeholders are replaced, and the same
values are in the hook's environment as `XNE_NOTIFY_<NAME>` (`XNE_NOTIFY_STATE`, `XNE_NOTIFY_KEY` ...):

| Placeholder | Value |
|---|---|
| `{state}` | `problem` or `ok` |
| `{key}` | `xne-<install id>`, stable per install |
| `{install}` | the install root |
| `{version}` | the installed Exponential version |
| `{title}` | e.g. `Exponential 6.0.14 at /srv/www/site: security advisory (stable v6.0.15, security v6.0.15)` |
| `{severity}` | `crit` (a security advisory), `warn` (an upgrade), `ok` |
| `{security}` | `1` or `0` |
| `{action}` | what to do next (`xne plan --root=...`, then `xne upgrade`) |
| `{channels}`, `{advisories}` | `channel ref` pairs and advisory ids, comma-separated |
| `{body}` | JSON: `version`, `channels`, `advisories` |

An unknown placeholder stays as it is. The result's `notified` lists each hook that ran (`problem hook problem`,
`event hook problem`, `ok hook ok`), with `(failed: exit N)` when it did not exit 0.

```ini
notify_problem_cmd=/usr/local/bin/my-alert open --key {key} --severity {severity} --title {title}
notify_ok_cmd=/usr/local/bin/my-alert close --key {key}
notify_event_cmd=/usr/local/bin/my-chat-post --subject {title} --body {body}
```

The daily `cronjobs/xne-check-all` runs `check` for every install of `/etc/xne/installs`.

## The GitLab release mirror (owner Q5)

GitLab ranks first among the sources when a project is set. A GitLab project can mirror GitHub
se7enxweb/exponential (branches and tags only), so a server or a fleet has its own copy of the releases.

```bash
xne server:mirror [--dry-run]    # mirror_from -> mirror_to, through a bare mirror in <cache_dir>/mirror/
```

A tag that differs on GitLab is never forced (tags are permanent): the run fails and names it. Run it from
`xne-mirror.timer` (hourly). A server that uses the mirror as its first source sets, in `/etc/xne/xne.conf`:

```ini
gitlab_project=<group>/exponential
gitlab_token_file=/etc/xne/gitlab.token
```

The default leaves GitLab unset, so GitHub answers first.

## Running the API in production

- A reverse proxy routes `/api/v1/` to the API (`server/nginx-api-location.conf` is an nginx example); plain http
  answers 301 to https.
- `xne-api.service` runs its own PHP-FPM pool (user `xne-api`, `server/xne-api-fpm.conf`) with the data folder
  `/var/lib/xne-server` (0700); `xne-server-index.timer` rebuilds the index every 30 minutes.
- Several API nodes: `server_owner=xne-api` and `server_peers` (ssh targets of the other nodes) in `/etc/xne/xne.conf`,
  so every issued or revoked key reaches all of them.
- A provisioning system that brings a customer install live runs
  `xne server:key:provision --account=<account> --level=<level> --domain=<domain> --target=<ssh target>`: the key is
  issued (copy in `server_data/issued/`, 0600), sent to `xne key:set --domain=<domain>` on the site's server over ssh
  stdin, and the fingerprints must match.
