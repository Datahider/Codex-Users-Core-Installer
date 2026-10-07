#!/bin/sh

set -eu

installer_url='https://codexgate.ru/install-core.php'
installer_file="$(mktemp /tmp/codexgate-install-core.XXXXXX.php)"
trap 'rm -f "$installer_file"' EXIT HUP INT TERM

curl -fsSL "$installer_url" -o "$installer_file"
php "$installer_file" "$@"
