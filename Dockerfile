# Gumbel for Vestaboard – PHP 8.4 + Apache + SQLite, Cron im Container
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libicu-dev \
    && docker-php-ext-install intl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\ndisplay_errors = Off\nlog_errors = On\nsession.cookie_httponly = 1\nsession.upload_progress.enabled = Off\n' > "$PHP_INI_DIR/conf.d/zz-gumbel.ini"

# Webroot ist public/; .htaccess in den übrigen Ordnern sperrt sie zusätzlich
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && a2enmod headers \
    && echo 'ServerTokens Prod\nServerSignature Off' > /etc/apache2/conf-enabled/security-gumbel.conf

COPY . /var/www/html
RUN rm -rf /var/www/html/config/config.php /var/www/html/var/* \
    && mkdir -p /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html/var

# Konfiguration kommt aus Umgebungsvariablen (docker/config.php)
ENV VESTA_CONFIG=/var/www/html/docker/config.php
VOLUME ["/var/www/html/var"]
EXPOSE 80

COPY docker/entrypoint.sh /usr/local/bin/gumbel-entrypoint
RUN chmod +x /usr/local/bin/gumbel-entrypoint
ENTRYPOINT ["gumbel-entrypoint"]
CMD ["apache2-foreground"]
