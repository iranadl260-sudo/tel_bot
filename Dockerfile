FROM php:8.2-cli

# نصب پیش‌نیازهای لینوکس برای MadelineProto
RUN apt-get update && apt-get install -y \
    git unzip libpng-dev libjpeg-dev libfreetype6-dev \
    libgmp-dev libxml2-dev libzip-dev ffmpeg \
    && docker-php-ext-install pdo pdo_mysql gmp sockets zip bcmath

# نصب Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "bot.php"]