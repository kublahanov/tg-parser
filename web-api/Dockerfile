FROM php:8.4-fpm-alpine

# Установка необходимых расширений через apk
RUN apk add --no-cache \
    git \
    unzip \
    zip \
    libzip-dev \
    oniguruma-dev \
    && docker-php-ext-install \
    pdo_mysql \
    zip \
    mbstring

WORKDIR /app

# Установка Composer
COPY composer.json ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader

# Копируем остальной код
COPY . .

EXPOSE 9000

CMD ["php-fpm", "-F"]
