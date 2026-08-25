FROM php:8.3-cli-bookworm

# System packages:
#  - ffmpeg: required by NarrationAudioService (ffprobe) and
#    StoryVideoAssemblyService (ffmpeg) — this is the whole reason this
#    image isn't just the stock php:8.3-cli.
#  - git/unzip/zip: composer needs these for VCS/zip package installs.
#  - libzip/libpng/libjpeg/libfreetype/libicu -dev: build deps for the PHP
#    extensions below (zip, gd, intl).
#  - default-mysql-client: gives the entrypoint a `mysqladmin ping` to wait
#    on the db service, and is handy for manual debugging.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        git \
        unzip \
        zip \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libicu-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd bcmath zip intl exif \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
