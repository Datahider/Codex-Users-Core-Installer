#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$PROJECT_ROOT/bin/install-tenant.php"

test -x "$INSTALLER"

help="$($INSTALLER --help)"
grep -Fq 'install-tenant.php /path/to/tenant.ini' <<<"$help"
grep -Fq 'install-tenant.php --create /path/to/tenant.ini' <<<"$help"
grep -Fq 'install-tenant.php --update USERNAME' <<<"$help"
grep -Fq 'username=<username>' <<<"$help"
grep -Fq 'telegram_chat_id=<telegram_chat_id>' <<<"$help"
grep -Fq 'transcription_api_key=<transcription_api_key>' <<<"$help"

set +e
no_args="$($INSTALLER 2>&1)"
no_args_status=$?
set -e
test "$no_args_status" -ne 0
grep -Fq 'install-tenant.php --create /path/to/tenant.ini' <<<"$no_args"
grep -Fq 'install-tenant.php --update USERNAME' <<<"$no_args"
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

mkdir -p "$tmp_dir/bin"
cat > "$tmp_dir/bin/sudo" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'ARGS:%s\n' "$*" >> "$INSTALLER_TEST_LOG"
payload="$(cat)"
if [ -n "$payload" ]; then
    printf 'STDIN:\n%s\n' "$payload" >> "$INSTALLER_TEST_LOG"
fi
EOF
cat > "$tmp_dir/bin/systemctl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
if [ "${1:-}" = 'is-active' ]; then
    echo active
fi
EOF
chmod +x "$tmp_dir/bin/sudo" "$tmp_dir/bin/systemctl"

export INSTALLER_TEST_LOG="$tmp_dir/update.log"
PATH="$tmp_dir/bin:$PATH" "$INSTALLER" --update valid-name >/dev/null
grep -Fq 'become-user valid-name' "$INSTALLER_TEST_LOG"
grep -Fq 'git status --porcelain --untracked-files=no' "$INSTALLER_TEST_LOG"
grep -Fq 'git pull --ff-only' "$INSTALLER_TEST_LOG"
grep -Fq 'composer install --no-dev --prefer-dist --no-interaction' "$INSTALLER_TEST_LOG"
grep -Fq 'systemctl restart codex-core@valid-name.service' "$INSTALLER_TEST_LOG"

if PATH="$tmp_dir/bin:$PATH" "$INSTALLER" -u 'bad/name' >/dev/null 2>&1; then
    echo 'Installer update accepted an invalid username' >&2
    exit 1
fi

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
