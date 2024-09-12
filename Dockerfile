FROM php:7.4-cli

# Installazione di estensioni PHP e strumenti di base
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    zip \
    zsh \
    wget \
    && docker-php-ext-install zip

# Installazione di Xdebug
RUN pecl install xdebug-3.0.0 \
    && docker-php-ext-enable xdebug

# Installazione di Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Impostazioni di configurazione di Xdebug
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Imposta la working directory
WORKDIR /var/www

# Imposta i permessi corretti per il progetto
RUN chown -R www-data:www-data /var/www

RUN sh -c "$(wget -O- https://github.com/deluan/zsh-in-docker/releases/download/v1.2.0/zsh-in-docker.sh)"
