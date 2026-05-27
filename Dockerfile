FROM php:8.3-apache

# Note: sqlite3 + pdo_sqlite are already bundled and enabled in this base image.
# Force a single MPM (prefork) and enable rewrite. Guards against the
# "More than one MPM loaded" fatal error if any other MPM is ever enabled.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite

# App code becomes the document root
COPY app/ /var/www/html/

# Container config reads everything from Railway env vars at runtime.
# No secrets are baked into the image; the local app/config.php is never shipped.
COPY docker/config.php /var/www/html/config.php

# SQLite DB lives on the mounted Railway Volume (see entrypoint chown)
RUN mkdir -p /data && chown -R www-data:www-data /data

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
CMD ["/usr/local/bin/entrypoint.sh"]
