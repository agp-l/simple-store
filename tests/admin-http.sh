#!/usr/bin/env bash
set -euo pipefail

cookie_jar=$(mktemp)
server_log=$(mktemp)
php -S 127.0.0.1:9877 tests/admin-http.php >"$server_log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; rm -f "$cookie_jar" "$server_log"' EXIT

for attempt in 1 2 3 4 5; do
    if curl --silent --fail --output /dev/null 'http://127.0.0.1:9877/simple-store/admin.php'; then
        break
    fi
    sleep 1
done

url='http://127.0.0.1:9877/simple-store/admin.php'
response=$(curl --silent --show-error --fail --cookie-jar "$cookie_jar" "$url")
[[ "$response" =~ ^[a-f0-9]{64}\|anonymous$ ]] || { cat "$server_log"; exit 1; }
token=${response%%|*}

response=$(curl --silent --show-error --fail --cookie "$cookie_jar" \
    --cookie-jar "$cookie_jar" --data-urlencode "csrf=$token" --data 'action=login' "$url")
[[ "$response" == 'login accepted' ]] || { cat "$server_log"; exit 1; }

response=$(curl --silent --show-error --fail --cookie "$cookie_jar" "$url")
[[ "$response" =~ ^[a-f0-9]{64}\|signed$ ]] || { cat "$server_log"; exit 1; }
token=${response%%|*}

response=$(curl --silent --show-error --fail --cookie "$cookie_jar" \
    --data-urlencode "csrf=$token" --data 'action=edit' "$url")
[[ "$response" == 'action accepted' ]] || { cat "$server_log"; exit 1; }

echo 'Admin session and CSRF HTTP round trip: OK'
