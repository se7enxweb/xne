# server (skeleton, phase 2)

The server side of Exponential Network: network.se7enx.com as an Exponential site (behind a load
balancer), with the API module and the portal, plus a self-host mode with the same code.

| Part | Planned (phase 2) |
|---|---|
| `exp_network_server/` | the site extension: API v1 (check, report, releases, advisories, installs, webhooks), signed requests (HMAC/Ed25519 by install key) |
| release index | built from the same sources as the client (GitLab, GitHub, Packagist), cached, with the advisories per version range |
| install registry | keys per account (issued by `xne server:key:issue`, or by a provisioning system); anonymous installs report their version only (Q13) |
| notifications | dashboard notice data, mail to AdminEmail, webhooks, and the client's notify hooks (Q15) |

The client never needs the server for an upgrade: `xne plan` and `xne upgrade` read the release sources directly.
