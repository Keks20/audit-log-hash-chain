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
# Kljuc za potpis audit loga (stoji samo u .env, van baze)
if ! grep -q '^AUDIT_KLJUC_POTPISA=..*' .env; then
    php artisan audit:kljuc
fi

# Laravelove tabele (users, sessions, cache, jobs) i kasnije nase
php artisan migrate --force

# Pocetni podaci iz database/data/*.json
# (seederi preskacu tabele koje vec imaju podatke)
php artisan db:seed --force

# Raspored (routes/console.php): automatska provera audit loga svakog sata.
# Na pravom serveru bi ovo bio cron sa "schedule:run"; ovde nema crona u kontejneru.
php artisan schedule:work &

exec apache2-foreground