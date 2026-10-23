FROM php:8.3.36-cli

RUN docker-php-ext-install pdo_mysql
