FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql

# Включаем mod_rewrite для красивых URL (опционально)
RUN a2enmod rewrite

WORKDIR /var/www/html
