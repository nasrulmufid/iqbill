# Stage 1: Build Vite + Tailwind CSS production assets
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources resources
COPY public public
COPY tailwind.config.js postcss.config.js vite.config.js ./
RUN npm run build

# Stage 2: Base PHP 8.2-FPM runtime with production extensions
FROM php:8.2-fpm-alpine AS php-base
WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        oniguruma \
        sqlite-libs \
        su-exec \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
        sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        sockets \
        zip \
    && apk del .build-deps \
    && cp /usr/share/zoneinfo/Asia/Jakarta /etc/localtime \
    && echo "Asia/Jakarta" > /etc/timezone

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-iqbill.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

# Stage 3: Install production Composer dependencies
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

# Stage 4: Production Application (PHP-FPM / Queue Worker / Scheduler)
FROM php-base AS app
WORKDIR /var/www/html

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /run/php-fpm storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
    && chown -R www-data:www-data /run/php-fpm storage bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Stage 5: Production Nginx Web Server
FROM nginx:1.27-alpine AS web
WORKDIR /var/www/html

COPY --from=app /var/www/html/public ./public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
