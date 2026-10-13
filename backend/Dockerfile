FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis
COPY apache.conf /etc/apache2/conf-available/fallback.conf
RUN a2enconf fallback