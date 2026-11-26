#!/usr/bin/env bash
# Runs the integration tests one at a time, each only while this node's load1 is under the gate (default 26), so the
# sites on the node never compete with a test. Meant for a transient unit with Nice=19 and IOSchedulingClass=idle:
#   systemd-run --unit=xne-it --collect -p Nice=19 -p IOSchedulingClass=idle \
#     --setenv=XNE_IT_DIR=/srv/xne-test --setenv=XNE_IT_PHP=/usr/bin/php --setenv=XNE_IT_RUN=r2 \
#     --working-directory=<package> tests/integration/run-gated.sh <log file>
# XNE_IT_ONLY=<text> runs only the groups whose name contains it. XNE_IT_WAIT_CMD=<command> replaces the built-in gate
# (load1 from /proc/loadavg under XNE_IT_GATE, checked every minute) with a command that returns once the node may run. One log line per test (PASS/FAIL); the last line is "GATED RUN PASS n/n" or "GATED RUN FAIL ...". Read-only waits.
set -uo pipefail
LOG=${1:?log file}
GATE=${XNE_IT_GATE:-26}
PHP=${XNE_IT_PHP:-php}
cd "$(dirname "$(readlink -f "$0")")/../.." || exit 1
TESTS=(
    "CoreTest" "TarTest" "DbTest" "PlanTest" "GuardTest" "FlowTest"
    "RealTest: seeds"
    "RealTest: sqlite:"
    "RealTest: mysql: upgrade"
    "RealTest: mysql: a real failing"
    "RealTest: postgresql: upgrade"
    "RealTest: postgresql: a failure"
    "RealTest: git: 6.0.14"
    "RealTest: git: local changes"
    "RealTest: stable channel"
    "MongoTest"
    "OracleTest"
)
pass=0; fail=0; failed=""
for t in "${TESTS[@]}"; do
    case "$t" in *"${XNE_IT_ONLY:-}"*) ;; *) continue ;; esac
    if [ -n "${XNE_IT_WAIT_CMD:-}" ]; then
        until $XNE_IT_WAIT_CMD >/dev/null 2>&1; do :; done
    else
        until awk -v g="$GATE" '{ exit !($1 < g) }' /proc/loadavg; do sleep 60; done
    fi
    echo "== $(date '+%F %T') $t (load $(cut -d' ' -f1 /proc/loadavg))" >> "$LOG"
    if "$PHP" tests/run.php "$t" >> "$LOG" 2>&1; then pass=$((pass + 1)); else fail=$((fail + 1)); failed="$failed; $t"; fi
done
if [ "$fail" = 0 ]; then echo "GATED RUN PASS $pass/$pass" >> "$LOG"; else echo "GATED RUN FAIL $fail of $((pass + fail)) groups:$failed" >> "$LOG"; exit 1; fi
