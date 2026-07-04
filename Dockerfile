FROM php:8.2-fpm-alpine

# Install system dependencies

RUN apk add --no-cache \
    nginx \
    nodejs \
    npm \
    curl \
    zip \
    unzip \
    git \
    sqlite \
    sqlite-dev \
    postgresql-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs

# Copy package files and build frontend assets
COPY package.json package-lock.json ./
RUN npm ci

# Copy rest of application
COPY . .

# Complete composer install and build
RUN composer dump-autoload --optimize
RUN npm run build

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Nginx config
RUN echo 'server { \
    listen 80; \
    root /var/www/html/public; \
    index index.php; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location ~ \.php$ { fastcgi_pass 127.0.0.1:9000; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; include fastcgi_params; } \
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
