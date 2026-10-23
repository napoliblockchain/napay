FROM php:8.3.36-apache

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libicu-dev \
    libpng-dev \
    libgmp-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    && docker-php-ext-install -j$(nproc) intl pdo_mysql gd zip bcmath gmp sockets

# Install utility
RUN apt-get install -y --no-install-recommends apt-utils
RUN apt-get install -y net-tools iputils-ping
RUN apt install -y htop
RUN apt-get install -y nano
RUN apt-get install -y certbot
RUN apt-get install -y python3-certbot-apache

RUN a2enmod rewrite

# copy yii2 000-default.conf configurated file
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# set php cache
RUN docker-php-ext-install opcache
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/php-opcache-cfg.ini



# Install Composer
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer && \
    chmod +x /usr/local/bin/composer

# Install nodejs
ENV NODE_VERSION=19.6.1
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
ENV NVM_DIR=/root/.nvm
RUN . "$NVM_DIR/nvm.sh" && nvm install ${NODE_VERSION}
RUN . "$NVM_DIR/nvm.sh" && nvm use v${NODE_VERSION}
RUN . "$NVM_DIR/nvm.sh" && nvm alias default v${NODE_VERSION}
ENV PATH="/root/.nvm/versions/node/v${NODE_VERSION}/bin/:${PATH}"

# from compose args
ARG MYSQL_HOST
ARG MYSQL_DBNAME
ARG MYSQL_USER
ARG MYSQL_PASSWORD

# environment variables
ENV DOCKERCONTAINER=true
ENV MYSQL_HOST=$MYSQL_HOST
ENV MYSQL_DBNAME=$MYSQL_DBNAME
ENV MYSQL_USER=$MYSQL_USER
ENV MYSQL_PASSWORD=$MYSQL_PASSWORD