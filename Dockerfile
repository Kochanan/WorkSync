FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring \
    && a2enmod access_compat headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/
COPY docker/ /opt/worksync/

RUN rm -rf /var/www/html/docker \
    && mkdir -p /var/www/html/assets/uploads /var/www/html/assets/avatars /var/www/html/assets/reviews \
    && chown -R www-data:www-data /var/www/html/assets/uploads /var/www/html/assets/avatars /var/www/html/assets/reviews \
    && chmod +x /opt/worksync/entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/opt/worksync/entrypoint.sh"]
CMD ["apache2-foreground"]
