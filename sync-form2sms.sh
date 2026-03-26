#!/usr/bin/env bash
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${SCRIPT_DIR}/"
DST="/var/www/html/wordpress/wp-content/plugins/form2sms/"

[[ ! -d "$DST" ]] && sudo mkdir -p "$DST" && sudo chown apache:apache "$DST"

echo -n "[$(date '+%Y-%m-%d %H:%M:%S')] Start – pokazywane będą TYLKO rzeczywiste zmiany"

while true; do
    real_changes=$(sudo rsync -a --delete \
        --exclude='.git' \
        --exclude='vendor' \
        --exclude='tests' \
        --exclude='node_modules' \
        --exclude='.claude' \
        --exclude='dist' \
        --exclude='*.zip' \
        --exclude='*.csv' \
        --exclude='composer.json' \
        --exclude='composer.lock' \
        --exclude='phpunit.xml.dist' \
        --exclude='infection.json' \
        --exclude='.env' \
        --exclude='.env.example' \
        --exclude='make_install.sh' \
        --exclude='sync-*.sh' \
        --itemize-changes \
        --out-format='%i %n%L' \
        "$SRC" "$DST" 2>/dev/null \
        | grep -v '^\.[fd]' \
        | grep -v '^cd')

    if [[ -n "$real_changes" ]]; then
        echo -e "\033[1;33m[$(date '+%Y-%m-%d %H:%M:%S')] Zsynchronizowano:\033[0m"
        echo "$real_changes" | sed 's/^/  /'
        sudo chown -R apache:apache "$DST" 2>/dev/null
    fi

    sleep 2
done
