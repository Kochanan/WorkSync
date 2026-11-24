#!/bin/sh
set -eu

cd /var/www/html
[ -f config.php ] || cp config.example.php config.php
[ -f mail_config.php ] || cp mail_config.example.php mail_config.php

if [ -n "${DB_HOST:-}" ]; then
    php /opt/worksync/init-db.php
fi

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*) echo "PORT must be a numeric port" >&2; exit 1 ;;
esac
sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
