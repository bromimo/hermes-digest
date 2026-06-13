FROM php:8.3-cli

# System deps: git/unzip for composer, libonig for mbstring
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libonig-dev \
 && docker-php-ext-install mbstring \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better layer caching)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

# App code
COPY . .
RUN composer dump-autoload --optimize --no-dev

EXPOSE 8000

ENV MCP_HOST=0.0.0.0 \
    MCP_PORT=8000

CMD ["php", "bin/server.php"]
