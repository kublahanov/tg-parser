FROM php:8.4-cli

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libonig-dev \
    && docker-php-ext-install \
    pdo_mysql \
    zip \
    mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis

# Установка Composer
# COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Копируем composer.json и устанавливаем зависимости
#COPY composer.json composer.lock ./

#RUN composer install --no-dev --optimize-autoloader

# Настраиваем Composer для использования зеркала Packagist
#RUN composer config repo.packagist composer https://packagist.org

# Увеличиваем таймаут и отключаем GitHub API
#ENV COMPOSER_PROCESS_TIMEOUT=2000
#RUN composer install --no-dev --optimize-autoloader --prefer-dist

# Копируем исходный код
COPY . /app

# Права на кеш и логи
RUN mkdir -p runtime/logs runtime/cache \
    && chmod -R 777 runtime

EXPOSE 8080

CMD ["php", "-S", "[::]:8080", "-t", "/app/public"]
