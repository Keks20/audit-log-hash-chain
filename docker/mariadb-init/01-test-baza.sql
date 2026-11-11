-- Posebna baza za testove: RefreshDatabase je prazni pri svakom pokretanju,
-- pa testovi nikad ne diraju audit_log (ni tabelu magacin u njoj).
--
-- MariaDB ovaj fajl pokrece samo kad pravi PRAZAN volume (novi klon).
-- Za postojeci volume jednom rucno:
--   docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' < docker/mariadb-init/01-test-baza.sql

CREATE DATABASE IF NOT EXISTS audit_log_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON audit_log_test.* TO 'audit'@'%';
