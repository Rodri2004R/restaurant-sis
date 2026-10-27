FROM php:8.2-apache
COPY . /var/www/html/
RUN docker-php-ext-install pdo pdo_mysql || true
EXPOSE 80
CMD ["apache2-foreground"]