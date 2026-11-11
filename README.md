# Audit log sa heš lancem

Laravel aplikacija za mali magacin u kojoj će svaka izmena artikla biti upisana u audit log zaštićen heš lancem: svaki zapis sadrži heš prethodnog, pa se naknadno menjanje ili brisanje zapisa iz loga otkriva pri proveri lanca.

Podaci potiču iz (izmišljenog) starog dBase programa: `magacin.dbf` u kodnoj strani cp852, uvezen alatom iz repoa `legacy-formats-to-mysql` i prebačen u čistu tabelu `artikli`.

> **Status:** faze 0 i 1 su gotove (lista artikala, prijava, dodavanje, izmena i brisanje). Heš lanac je sledeći (faza 2).

## Tehnologije

| | |
|---|---|
| Backend | PHP 8.5, Laravel 13 |
| Baza | MariaDB 10.11 |
| Frontend | Blade + Bootstrap 5.3 (CDN), bez npm-a i Vite-a |
| Okruženje | Docker Compose: `app` (Apache), `db`, `phpmyadmin` |
| Testovi | PHPUnit (Feature testovi) |

## Pokretanje

Potreban je samo Docker (Docker Desktop na Windows-u).

```
git clone https://github.com/Keks20/audit-log-hash-chain.git
cd audit-log-hash-chain
docker compose up -d --build
```

Prvi start traje nešto duže: kontejner sam pravi `.env` iz `.env.example`, radi `composer install`, generiše ključ, pokreće migracije i puni bazu (37 artikala i dva test naloga).

| Servis | Adresa |
|---|---|
| Aplikacija | http://localhost:8081 |
| phpMyAdmin | http://localhost:8082 |
| MariaDB spolja | `127.0.0.1:3308`, baza `audit_log` |

Svi portovi su vezani samo na `127.0.0.1`.

Kraj rada: `docker compose stop`. Podaci baze ostaju u Docker volume-u.

## Test nalozi

| Uloga | Email |
|---|---|
| Magacioner | `magacioner@magacin.test` |
| Šef | `sef@magacin.test` |

Lozinka za oba naloga je vrednost `DEMO_LOZINKA` iz `.env` (podrazumevano `lozinka`). Ta lozinka, kao i `audit` / `audit` za bazu, postoji samo za lokalni razvoj.

## Šta aplikacija radi

- **Lista artikala** je javna. Prazne vrednosti iz starog programa prikazuju se kao „—“, a nepoznato stanje „aktivan“ kao „?“.
- **Prijava i odjava**: posle 5 pogrešnih pokušaja prijava se blokira na minut, a sesija se obnavlja posle prijave i poništava posle odjave.
- **Dodavanje artikla**: šifra od tačno 6 cifara i jedinstvena, sva polja osim napomene su obavezna.
- **Izmena artikla**: šifra se ne menja (ona je identitet artikla za audit log), popunjeno polje ne sme da se isprazni, a prazno polje iz starog programa sme da ostane prazno. Čuvanje bez stvarne promene se ne upisuje.
- **Brisanje artikla** uz potvrdu (pravo brisanje; poslednje stanje će čuvati audit log).
- Količina i cena primaju decimalni zarez (`120,50`). Poruke su na srpskom.

## Testovi

```
docker compose exec app php artisan test
```

Testovi rade nad SQLite bazom u memoriji (`phpunit.xml`), pa nikad ne diraju lokalnu MariaDB bazu.

## Podaci

- `magacin`: sirova tabela uvezena iz `magacin.dbf` (40 zapisa, 3 obrisana u dBase-u). Ne postoji posle kloniranja; nije je napravila migracija.
- `artikli`: čista tabela aplikacije.
- `database/data/artikli.json`: 37 artikala u repou. `ArtikliSeeder` iz njega puni samo **praznu** tabelu `artikli`, pa izmene iz aplikacije ostaju. Fajl se pravi komandom `php artisan artikli:izvoz`.
- `ArtikliIzMagacinaSeeder`: zapis kako su artikli prvobitno prebačeni iz tabele `magacin`.

## Plan

- [x] **Faza 0:** Laravel + Docker, uvoz `magacin.dbf`, tabela `artikli`, lista artikala
- [x] **Faza 1:** prijava, dodavanje, izmena i brisanje artikla
- [ ] **Faza 2:** kanonski JSON artikla, audit log tabela, heš svakog zapisa vezan za prethodni, provera integriteta lanca
