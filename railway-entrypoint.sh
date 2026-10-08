#!/bin/sh
set -eu

APP_PORT="${PORT:-80}"

# Apache solo puede cargar un MPM. mod_php requiere prefork.
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1

sed -ri "s/^Listen [0-9]+$/Listen ${APP_PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${APP_PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p "/var/www/html/uploads/parts"
chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true

# En Railway, la base necesita inicialización manual porque no existe el
# entrypoint de la imagen oficial de MySQL. En Docker Compose, en cambio,
# MySQL ejecuta /docker-entrypoint-initdb.d/init.sql automáticamente.
if [ "${RUN_DB_INIT:-1}" = "1" ]; then
    php /usr/local/bin/railway-db-init.php
fi

apache2ctl -t
exec "$@"
