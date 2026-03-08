FROM php:8.2-cli
LABEL authors="https://github.com/kublahanov"

# Установка необходимых расширений
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    && docker-php-ext-install \
    pdo_mysql \
    zip \
    gd \
    && pecl install redis \
    && docker-php-ext-enable redis

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Копируем исходный код парсера
COPY . .

CMD ["sleep", "infinity"]
