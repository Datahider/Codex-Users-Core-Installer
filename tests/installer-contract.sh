#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$PROJECT_ROOT/bin/install-core.php"

test -x "$INSTALLER"

help="$($INSTALLER --help)"
grep -Fq 'install-core.php --pair CODE' <<<"$help"
grep -Fq -- '--user USERNAME' <<<"$help"
grep -Fq -- '--yes' <<<"$help"
grep -Fq 'install-core.php --update USERNAME' <<<"$help"

set +e
no_args="$($INSTALLER 2>&1)"
no_args_status=$?
set -e
test "$no_args_status" -ne 0
grep -Fq 'install-core.php --pair CODE' <<<"$no_args"
grep -Fq 'install-core.php --update USERNAME' <<<"$no_args"

test -f "$PROJECT_ROOT/systemd/codex-core@.service"
grep -Fq 'User=%i' "$PROJECT_ROOT/systemd/codex-core@.service"
grep -Fq 'Group=%i' "$PROJECT_ROOT/systemd/codex-core@.service"
grep -Fq 'WorkingDirectory=/home/%i/Codex-Users-Core' "$PROJECT_ROOT/systemd/codex-core@.service"
grep -Fq 'ExecStart=/home/%i/Codex-Users-Core/bin/run-core.php' "$PROJECT_ROOT/systemd/codex-core@.service"

if "$INSTALLER" --update 'bad/name' >/dev/null 2>&1; then
    echo 'Installer update accepted an invalid username' >&2
    exit 1
fi

if "$INSTALLER" --pair bad --user valid-name --yes >/dev/null 2>&1; then
    echo 'Installer accepted invalid pairing code' >&2
    exit 1
fi

grep -Fq "run(['systemctl', 'daemon-reload'])" "$INSTALLER"
grep -Fq "run(['systemctl', 'enable', '--now', \$service])" "$INSTALLER"
grep -Fq "run(['systemctl', 'restart', \$service])" "$INSTALLER"
grep -Fq "'/etc/systemd/system/codex-core@.service'" "$INSTALLER"
grep -Fq "CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . \$pairing_code" "$INSTALLER"

echo 'Tenant installer contract: OK'
