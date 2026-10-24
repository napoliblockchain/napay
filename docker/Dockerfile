FROM composer:2.10.3 AS composer
FROM php:8.3.36-apache

COPY docker/install-php-extensions.sh /usr/local/bin/napay-install-php-extensions
RUN sh /usr/local/bin/napay-install-php-extensions \
    && a2enmod rewrite \
    && { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/php-opcache-cfg.ini

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# HTTPS belongs to Caddy; Node/Workbox is a separate asset-build concern.
# Application credentials are supplied at runtime, never as build arguments.
ENV DOCKERCONTAINER=true
WORKDIR /var/www/html
