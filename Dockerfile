FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads /var/www/html/logs

RUN chmod -R 755 /var/www/html/
RUN chmod 777 /var/www/html/uploads /var/www/html/logs

EXPOSE 80

CMD ["apache2-foreground"]