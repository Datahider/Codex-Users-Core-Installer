#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$PROJECT_ROOT/bin/install-tenant.php"

test -x "$INSTALLER"

help="$($INSTALLER --help)"
grep -Fq 'install-tenant.php /path/to/tenant.ini' <<<"$help"
grep -Fq 'install-tenant.php --create /path/to/tenant.ini' <<<"$help"
grep -Fq 'username=<username>' <<<"$help"
grep -Fq 'telegram_chat_id=<telegram_chat_id>' <<<"$help"
grep -Fq 'transcription_api_key=<transcription_api_key>' <<<"$help"

set +e
no_args="$($INSTALLER 2>&1)"
no_args_status=$?
set -e
test "$no_args_status" -ne 0
grep -Fq 'install-tenant.php --create /path/to/tenant.ini' <<<"$no_args"
grep -Fq 'username=<username>' <<<"$no_args"

tmp_dir="$(mktemp -d /home/web/tmp/tenant-installer-test.XXXXXX)"
trap 'rm -rf "$tmp_dir"' EXIT

"$INSTALLER" --create "$tmp_dir/created.ini"
grep -Fxq 'username=<username>' "$tmp_dir/created.ini"
grep -Fxq 'telegram_chat_id=<telegram_chat_id>' "$tmp_dir/created.ini"
grep -Fxq 'transcription_api_key=<transcription_api_key>' "$tmp_dir/created.ini"

if "$INSTALLER" -c "$tmp_dir/created.ini" >/dev/null 2>&1; then
    echo 'Installer overwrote an existing INI file' >&2
    exit 1
fi

"$INSTALLER" -c "$tmp_dir/short-option.ini"
test -f "$tmp_dir/short-option.ini"

cat > "$tmp_dir/invalid.ini" <<'EOF'
username=bad/name
telegram_chat_id=-100123
transcription_api_key=sk-test
EOF
chmod 600 "$tmp_dir/invalid.ini"

if "$INSTALLER" --validate "$tmp_dir/invalid.ini" >/dev/null 2>&1; then
    echo 'Installer accepted invalid INI data' >&2
    exit 1
fi

cat > "$tmp_dir/valid.ini" <<'EOF'
username=valid-name
telegram_chat_id=-100123
transcription_api_key=sk-test
EOF
chmod 644 "$tmp_dir/valid.ini"
"$INSTALLER" --validate "$tmp_dir/valid.ini" >/dev/null

echo 'Tenant installer contract: OK'
