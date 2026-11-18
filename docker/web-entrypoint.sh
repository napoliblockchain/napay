#!/bin/sh
set -eu
php /var/www/html/docker/bootstrap-check.php
exec docker-php-entrypoint "$@"
