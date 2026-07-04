# Binder app environment — PHP 5.6 (Symfony 1.x / AtoM fork) + nginx.
# Code is bind-mounted at /app (see docker-compose.yml); this image is env only.
FROM php:5.6-fpm

# Debian 9 "stretch" (php:5.6-fpm base) is archived — repoint apt at
# archive.debian.org and stop the expired-release check so packages still install.
RUN set -eux; \
    printf 'deb http://archive.debian.org/debian stretch main\n\
deb http://archive.debian.org/debian-security stretch/updates main\n' > /etc/apt/sources.list; \
    echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/10no-check-valid-until

RUN set -eux; \
    apt-get -o Acquire::AllowInsecureRepositories=true update; \
    apt-get install -y --no-install-recommends --allow-unauthenticated \
      nginx git unzip \
      libxml2-dev libxslt1-dev libldap2-dev libmemcached-dev libsasl2-dev zlib1g-dev \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      imagemagick ghostscript poppler-utils \
      default-mysql-client; \
    rm -rf /var/lib/apt/lists/*

# PHP extensions AtoM / Symfony 1 rely on.
RUN set -eux; \
    docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli xsl gd calendar sockets; \
    docker-php-ext-configure ldap --with-libdir=lib/x86_64-linux-gnu/; \
    docker-php-ext-install ldap

# memcache (php5-compatible pecl release).
RUN pecl install memcache-2.2.7 && docker-php-ext-enable memcache

COPY docker/app/php.ini    /usr/local/etc/php/conf.d/zzz-binder.ini
COPY docker/app/nginx.conf /etc/nginx/nginx.conf
COPY docker/app/config.php /usr/local/share/binder-config.php
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
EXPOSE 80
CMD ["/usr/local/bin/entrypoint.sh"]
