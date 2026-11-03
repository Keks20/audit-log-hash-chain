#!/bin/sh
# Pokrece se pri svakom startu kontejnera "app".
set -e
cd /var/www/app

# Ko klonira repo sa GitHub-a nema .env, vendor ni kljuc - napravi ih sam
if [ ! -f .env ]; then
    cp .env.example .env
fi
if [ ! -d vendor ]; then
    composer install --no-interaction
fi
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

# Laravelove tabele (users, sessions, cache, jobs) i kasnije nase
php artisan migrate --force

exec apache2-foreground