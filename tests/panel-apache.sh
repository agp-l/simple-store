#!/usr/bin/env bash
set -euo pipefail
panel_webroot=$(mktemp -d)
panel_container="simple-store-panel-$RANDOM-$RANDOM"
trap 'docker logs "$panel_container" 2>/dev/null || true; docker rm -f "$panel_container" >/dev/null 2>&1 || true; rm -rf "$panel_webroot"' EXIT
mkdir -p "$panel_webroot/shop"
for folder in "$panel_webroot" "$panel_webroot/shop"; do
  cp .htaccess "$folder/.htaccess"
  cp tests/fixtures/panel-apache.php "$folder/admin.php"
  cp tests/fixtures/panel-apache.php "$folder/account.php"
  printf '<?php http_response_code(404);' > "$folder/index.php"
done
php -r 'echo password_hash("test-password-123", PASSWORD_DEFAULT);' > "$panel_webroot/password-hash.txt"
chmod -R a+rX "$panel_webroot"
docker run --detach --name "$panel_container" --publish 127.0.0.1:8789:80 \
  --volume "$PWD:/app:ro" --volume "$panel_webroot:/var/www/html:ro" php:8.4-apache \
  bash -c 'a2enmod rewrite >/dev/null; printf "<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n" > /etc/apache2/conf-enabled/panel-test.conf; exec apache2-foreground' >/dev/null
python3 tests/panel-apache.py
