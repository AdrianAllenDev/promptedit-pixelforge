FROM node:22-bookworm-slim AS assets
WORKDIR /source
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-apache-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip libonig-dev \
    && docker-php-ext-install pdo_pgsql bcmath mbstring opcache \
    && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=assets /source/public/build public/build
RUN composer dump-autoload --optimize --no-dev && chown -R www-data:www-data storage bootstrap/cache
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr PORT=8080
CMD ["sh", "-ec", "sed -ri \"s/^Listen [0-9]+/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -ri \"s/VirtualHost \\*:[0-9]+/VirtualHost *:${PORT}/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
