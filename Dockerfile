# Используем официальный PHP + Apache образ
FROM php:8.2-apache

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    zip \
    libzip-dev \
    && docker-php-ext-install zip

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копируем проект
COPY . /var/www/html/

# Переходим в директорию проекта и устанавливаем зависимости
WORKDIR /var/www/html
RUN composer install

# Экспонируем порт 80
EXPOSE 80
