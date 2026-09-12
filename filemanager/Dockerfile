FROM php:8.2-apache

RUN a2enmod rewrite

RUN docker-php-ext-install pdo_mysql mysqli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        smbclient \
        python3 \
        python3-pip \
        curl \
        netcat-openbsd \
    && pip3 install --no-cache-dir --break-system-packages pypsrp \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN mkdir -p /var/log/filemanager /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html /var/log/filemanager \
    && chmod -R 775 /var/log/filemanager /var/www/html/storage/logs

EXPOSE 80
