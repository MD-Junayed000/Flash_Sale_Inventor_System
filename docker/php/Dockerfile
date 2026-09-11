FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions for Laravel + MySQL
RUN docker-php-ext-install pdo_mysql bcmath

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Increase PHP limits
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/zz-laravel.ini \
    && echo "upload_max_filesize=20M" >> /usr/local/etc/php/conf.d/zz-laravel.ini \
    && echo "post_max_size=20M" >> /usr/local/etc/php/conf.d/zz-laravel.ini
