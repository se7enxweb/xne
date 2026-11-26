#!/usr/bin/env bash
# xne installer: links /usr/local/bin/xne, creates /etc/xne (xne.conf from the example, once), /var/lib/xne (0700)
# and /var/cache/xne. Run as root from the checkout at /opt/xne. Dry run unless --apply. Idempotent; never
# removes anything; installs no timer (cronjobs/README.md). PASS/FAIL.
#   install.sh [plan]     what would change
#   install.sh --apply    install or update
#   install.sh status     what is installed
set -uo pipefail
P=$(cd "$(dirname "$(readlink -f "$0")")" && pwd); STAMP=$(date +%Y%m%d-%H%M%S)
[ "$(id -u)" = 0 ] || { echo "FAIL: run as root"; exit 1; }
case "${1:-plan}" in
    plan)
        [ "$(readlink -f /usr/local/bin/xne 2>/dev/null)" = "$P/bin/xne" ] || echo "would link /usr/local/bin/xne"
        [ -d /etc/xne ] || echo "would create /etc/xne"
        [ -f /etc/xne/xne.conf ] || echo "would write /etc/xne/xne.conf from etc/xne.conf.example"
        [ -d /var/lib/xne ] || echo "would create /var/lib/xne (0700)"
        [ -d /var/cache/xne ] || echo "would create /var/cache/xne"
        echo "PASS: plan (--apply to install)"; exit 0 ;;
    status)
        printf '%-22s -> %s\n' /usr/local/bin/xne "$(readlink /usr/local/bin/xne 2>/dev/null || echo missing)"
        /usr/local/bin/xne version; exit $? ;;
    --apply) ;;
    *) echo "FAIL: plan|--apply|status"; exit 1 ;;
esac
chmod 0755 "$P/bin/xne" "$P/install.sh" "$P/cronjobs/xne-check-all"
install -d -m 0755 /etc/xne /var/cache/xne
install -d -m 0700 /var/lib/xne
[ -f /etc/xne/xne.conf ] || install -m 0644 "$P/etc/xne.conf.example" /etc/xne/xne.conf
# a real file in the way is kept under a dated name, never removed
if [ -e /usr/local/bin/xne ] && [ ! -L /usr/local/bin/xne ]; then mv /usr/local/bin/xne "/usr/local/bin/xne.before-pkg-$STAMP"; fi
ln -sfn "$P/bin/xne" /usr/local/bin/xne
if out=$(/usr/local/bin/xne version 2>&1); then echo "PASS: xne installed from $P ($out)"; else echo "FAIL: xne does not start: $out"; exit 1; fi
