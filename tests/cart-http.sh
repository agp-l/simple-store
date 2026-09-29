#!/usr/bin/env bash
set -euo pipefail

cookie_jar=$(mktemp)
server_log=$(mktemp)
php -S 127.0.0.1:9876 tests/cart-http.php >"$server_log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; rm -f "$cookie_jar" "$server_log"' EXIT

for attempt in 1 2 3 4 5; do
    if curl --silent --fail --output /dev/null 'http://127.0.0.1:9876/simple-store/cs/produkt/test'; then
        break
    fi
    sleep 1
done

token=$(curl --silent --show-error --fail --cookie-jar "$cookie_jar" \
    'http://127.0.0.1:9876/simple-store/cs/produkt/test')
[[ "$token" =~ ^[a-f0-9]{64}$ ]] || { cat "$server_log"; exit 1; }

response=$(curl --silent --show-error --fail --cookie "$cookie_jar" \
    --data-urlencode "csrf=$token" --data 'action=add' \
    'http://127.0.0.1:9876/simple-store/cs/kosik')
[[ "$response" == 'csrf accepted' ]] || { cat "$server_log"; exit 1; }

response=$(curl --silent --show-error --fail --cookie "$cookie_jar" \
    --data-urlencode "csrf=$token" --data 'action=clear' \
    'http://127.0.0.1:9876/simple-store/cs/kosik')
[[ "$response" == 'csrf accepted' ]] || { cat "$server_log"; exit 1; }

response=$(curl --silent --show-error --fail --cookie "$cookie_jar" \
    --data-urlencode "csrf=$token" --data 'action=add' \
    'http://127.0.0.1:9876/simple-store/cs/kosik')
[[ "$response" == 'csrf accepted' ]] || { cat "$server_log"; exit 1; }

echo 'Cart cookie and CSRF HTTP round trip: OK'
