FROM phpswoole/swoole:5.0-php8.1

# Install system dependencies and PHP extensions
RUN set -eux; \
    if command -v apt-get >/dev/null 2>&1; then \
        apt-get update; \
        apt-get install -y --no-install-recommends libzip-dev zip; \
        rm -rf /var/lib/apt/lists/*; \
    elif command -v apk >/dev/null 2>&1; then \
        apk add --no-cache libzip-dev zip; \
    else \
        echo "No supported package manager found (apt-get/apk)."; \
        exit 1; \
    fi; \
    if command -v docker-php-ext-install >/dev/null 2>&1; then \
        docker-php-ext-install pdo_mysql; \
    else \
        echo "docker-php-ext-install not available in base image."; \
        exit 1; \
    fi

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
