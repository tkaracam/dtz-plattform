FROM php:8.2-apache

RUN a2enmod rewrite headers

COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/api \
    && mkdir -p /var/data/storage \
    && chown -R www-data:www-data /var/data

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
