FROM php:8.3-cli

# Extensions PHP requises par Laravel 13 + SQLite
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite zip mbstring bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --optimize-autoloader --no-scripts

COPY . .

# Le .env réel est ignoré : on part de l'exemple et on génère la clé d'app
RUN cp .env.example .env && php artisan key:generate

# Dossier dédié à la base SQLite = là où vivent les sessions/cookies
RUN mkdir -p /var/www/html/sqlite \
    && touch /var/www/html/sqlite/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache sqlite

EXPOSE 8000

# Au démarrage : on crée les tables, puis on lance le serveur
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000