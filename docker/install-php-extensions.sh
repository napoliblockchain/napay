#!/bin/sh
# Keep web and bootstrap compatible with the same Composer platform checks.
set -eu
apt-get update
apt-get install -y --no-install-recommends \
    libicu-dev libpng-dev libgmp-dev libjpeg62-turbo-dev \
    libfreetype6-dev libzip-dev unzip
docker-php-ext-configure gd --with-freetype --with-jpeg
docker-php-ext-install -j2 intl pdo_mysql gd zip bcmath gmp sockets
rm -rf /var/lib/apt/lists/*
