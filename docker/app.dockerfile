# Python 3.10 runtime stage
FROM python:3.10-slim AS python-runtime

# Main PHP application stage
FROM php:8.3-fpm

WORKDIR /var/www/youtube_rss

RUN apt-get update && apt-get install -y  \
    libfreetype6-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    unzip \
    --no-install-recommends \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql -j$(nproc) gd zip    

# Use the default production configuration for PHP runtime arguments
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy the app files from the app directory.
COPY . /var/www/youtube_rss

# Create directories
RUN mkdir -p \
    /var/www/youtube_rss/storage/app/public \
    /var/www/youtube_rss/storage/app/Works \
    /var/www/youtube_rss/storage/framework/cache \
    /var/www/youtube_rss/storage/framework/cache/data \
    /var/www/youtube_rss/storage/framework/sessions \
    /var/www/youtube_rss/storage/framework/views \
    /var/www/youtube_rss/storage/logs \
    /var/www/youtube_rss/bootstrap/cache \
    /var/www/youtube_rss/python/yt-dlp_jsons

# Get Composer from the official Composer image
COPY --from=composer:lts /usr/bin/composer /usr/bin/composer
# Install Composer dependencies
RUN composer install

# Bring Python 3.10 into the PHP image
COPY --from=python-runtime /usr/local /usr/local

# Create python venv and install Python dependencies
RUN python3 -m venv /var/www/youtube_rss/python/venv \
    && /var/www/youtube_rss/python/venv/bin/pip install pip \
    && /var/www/youtube_rss/python/venv/bin/pip install -r /var/www/youtube_rss/python/requirements.txt

# Set permissions
RUN chown -R www-data:www-data /var/www/youtube_rss/storage /var/www/youtube_rss/bootstrap/cache /var/www/youtube_rss/python/yt-dlp_jsons \
    && chmod -R ug+rwx /var/www/youtube_rss/storage /var/www/youtube_rss/bootstrap/cache /var/www/youtube_rss/python/yt-dlp_jsons

# Copy entrypoint script
COPY docker/docker-app-entrypoint.sh /usr/local/bin/docker-app-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-app-entrypoint.sh