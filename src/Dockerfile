FROM php:8.2-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring pdo pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite
