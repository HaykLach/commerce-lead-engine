FROM php:8.3-fpm-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql mbstring bcmath intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

RUN groupadd --gid ${GID} app \
    && useradd --uid ${UID} --gid app --create-home --shell /bin/bash app

WORKDIR /var/www/html

USER app

CMD ["php-fpm"]
