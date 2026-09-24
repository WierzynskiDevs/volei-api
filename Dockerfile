# syntax=docker/dockerfile:1

# --- Stage 1: dependências PHP (sem dev, autoload otimizado) -----------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# --- Stage 2: runtime PHP-FPM + nginx + supervisord --------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        bash \
        gettext \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache \
    && apk del icu-dev libzip-dev postgresql-dev \
    # apk del acima remove as libs runtime junto (dependências transitivas
    # órfãs dos pacotes -dev: libpq, libicu, libzip), quebrando pdo_pgsql,
    # intl e zip em runtime mesmo com as extensões compiladas com sucesso —
    # reinstala só as libs runtime, sem os headers de build.
    && apk add --no-cache libpq icu-libs libzip

# Opcache de produção — CLAUDE.md não permite lentidão desnecessária, mas
# também não permite cache do que muda por request (config já cuida disso).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.memory_consumption=192'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

WORKDIR /var/www/html

COPY --from=vendor /app ./
COPY docker/nginx.conf.template /etc/nginx/http.d/default.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
