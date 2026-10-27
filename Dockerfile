# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: PHP dependencies (pinned composer major, not "latest")
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: frontend build on a pinned Node. Node and npm exist ONLY in this stage, so the
# production image carries no Node/npm (smaller image, smaller attack surface), and the
# lock file is always read by the same npm that CI uses.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY public ./public
# Tailwind scans these framework views for class names (see tailwind.config.js content).
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: runtime
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

# PHP2Shell (CVE-2026-109618 / CVE-2026-109619, unauthenticated RCE): fail the BUILD, not a production
# request, if the base image is older than the fixed release of its branch. Bump the table when PHP ships
# a newer security release. Rebuild with `docker build --pull` so the base layer is refreshed.
RUN php -r '$m=[]; preg_match("/^(\d+\.\d+)/",PHP_VERSION,$m); \
    $min=["8.2"=>"8.2.35","8.3"=>"8.3.36","8.4"=>"8.4.27","8.5"=>"8.5.12"]; \
    if(!isset($min[$m[1]])||version_compare(PHP_VERSION,$min[$m[1]],"<")){fwrite(STDERR,"PHP ".PHP_VERSION." is below the PHP2Shell fix\n");exit(1);}'

# Hardening: no upload-progress session hook (PHP2Shell path), no version banner, and request limits that
# match the 10 MB Excel / 2 MB image rules in the application.
RUN printf 'session.upload_progress.enabled=0\nexpose_php=0\npost_max_size=12M\nupload_max_filesize=10M\n' \
    > /usr/local/etc/php/conf.d/zz-hardening.ini

RUN apk add --no-cache \
    nginx \
    curl \
    zip \
    unzip \
    git \
    sqlite \
    sqlite-dev \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql zip gd

# Fail the build (not a production request) if an extension that Excel import/export
# (phpoffice/phpspreadsheet needs zip + gd) or PDF generation depends on is missing.
RUN php -m | grep -qix zip && php -m | grep -qix gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Application code, then the vendor directory built in stage 1 (vendor is in .dockerignore,
# so a developer's local vendor folder can never end up in the image).
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN composer dump-autoload --optimize

# Built frontend assets from stage 2
COPY --from=assets /app/public/build ./public/build

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Nginx config
#  - client_max_body_size 1m is the deliberate request-size limit (same as nginx's built-in default, now
#    explicit so it is visible). Any request body over 1 MB is refused by nginx with HTTP 413 before PHP
#    runs, so the PHP limits above (12M/10M) and the app's 2 MB / 10 MB rules can never be reached.
#  - Only /index.php may execute PHP. Any other .php path (for example a file that ever lands under
#    public/) returns 404 instead of being run: the standard defence against web-shell uploads.
#  - server_tokens off hides the nginx version; dotfiles (.env, .git, ...) are never served.
RUN echo 'server { \
    listen 80; \
    server_tokens off; \
    client_max_body_size 1m; \
    root /var/www/html/public; \
    index index.php; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location = /index.php { fastcgi_pass 127.0.0.1:9000; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; include fastcgi_params; } \
    location ~ \.php$ { return 404; } \
    location ~ /\.(?!well-known) { deny all; } \
}' > /etc/nginx/http.d/default.conf

# Startup script
RUN echo '#!/bin/sh' > /start.sh && \
    echo 'php artisan config:cache' >> /start.sh && \
    echo 'php artisan route:cache' >> /start.sh && \
    echo 'php artisan view:cache' >> /start.sh && \
    echo 'php artisan migrate --force' >> /start.sh && \
    echo 'php-fpm -D' >> /start.sh && \
    echo 'nginx -g "daemon off;"' >> /start.sh && \
    chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]