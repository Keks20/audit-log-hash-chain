# PHP 8.5 + Apache za Laravel. Kod se NE kopira u image:
# docker-compose.yml povezuje folder projekta sa /var/www/app.

FROM php:8.5-apache

# pdo_mysql - baza, intl - formatiranje, zip - composer
# (sodium za digitalni potpis u fazi 5 vec postoji u ovom image-u)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: koren sajta je public/, a .htaccess (Laravel rute) mora da radi
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

COPY docker/start.sh /usr/local/bin/app-start
# Ako git na Windows-u pretvori kraj reda u CRLF, skripta ne bi radila
RUN sed -i 's/\r$//' /usr/local/bin/app-start && chmod +x /usr/local/bin/app-start

WORKDIR /var/www/app
EXPOSE 80
CMD ["app-start"]