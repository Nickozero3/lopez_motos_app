#!/bin/sh
set -eu

APP_PORT="${PORT:-8080}"

# Apache solo puede cargar un MPM. mod_php requiere prefork.
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1

sed -ri "s/^Listen [0-9]+$/Listen ${APP_PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${APP_PORT}>/" /etc/apache2/sites-available/000-default.conf

apache2ctl -t
exec "$@"
