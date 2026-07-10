FROM dunglas/frankenphp:1-php8.4-bookworm

# Install additional PHP extensions and use development php.ini template
RUN install-php-extensions \
    intl \
    zip \
    opcache \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

WORKDIR /app
