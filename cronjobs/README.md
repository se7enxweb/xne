# xne units

None is installed by `install.sh`; each is enabled on the node that needs it (`systemctl link` + `enable --now`).

| Unit | Node | What |
|---|---|---|
| `xne-check.timer` / `.service` | every node with installs | daily at 05:20: `cronjobs/xne-check-all` runs `xne plan --json` for each install root in `/etc/xne/installs` and prints CURRENT, UPGRADE, BLOCKED or FAIL. Read-only. |
| `xne-mirror.timer` / `.service` | the mirror node | hourly: `xne server:mirror` copies GitHub se7enxweb/exponential (branches, tags) to the GitLab release mirror se7enxweb/exponential; a moved tag is reported, never forced. Under the load gate, nice 19. |
| `xne-server-index.timer` / `.service` | the API node | every 30 minutes: `xne server:index` rebuilds the release index the API answers from. |

`xne check` (per install) also notifies through the notify hooks (`notify_problem_cmd` while an upgrade or an
advisory is open, `notify_ok_cmd` once the install is clean, `notify_event_cmd`; see doc/server.md). On servers without systemd use cron:
`20 5 * * * /opt/xne/cronjobs/xne-check-all`. On Windows (phase 4) a scheduled task.
