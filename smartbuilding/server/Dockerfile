FROM phpswoole/swoole:5.0-php8.1

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && docker-php-ext-install pdo pdo_mysql sockets \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# Copy only composer files to leverage cache
COPY composer.json /var/www/

# Run composer install (without dev dependencies for production-readiness)
RUN composer install --no-interaction --no-scripts --no-autoloader

# Copy the rest of the application
COPY . /var/www/

# Optimize autoloader
RUN composer dump-autoload --optimize

EXPOSE 8000
EXPOSE 9502

CMD ["php", "server.php"]
