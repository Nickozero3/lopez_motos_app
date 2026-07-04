FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring pdo pdo_mysql \
    && a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true

RUN a2enmod mpm_prefork rewrite \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY src/ /var/www/html/
COPY db/init.sql /opt/lopez-motos/init.sql
COPY railway-db-init.php /usr/local/bin/railway-db-init.php
COPY railway-entrypoint.sh /usr/local/bin/railway-entrypoint.sh

RUN chmod +x /usr/local/bin/railway-entrypoint.sh \
    && mkdir -p /var/www/html/uploads/parts /opt/lopez-motos \
    && chown -R www-data:www-data /var/www/html/uploads \
    && php -l /usr/local/bin/railway-db-init.php \
    && apache2ctl -t

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/railway-entrypoint.sh"]
CMD ["apache2-foreground"]
