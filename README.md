# Audit log sa heš lancem

Laravel aplikacija za mali magacin u kojoj se svaka izmena artikla upisuje u audit log zaštićen heš lancem: svaki zapis sadrži heš prethodnog, pa se naknadno menjanje ili brisanje zapisa iz loga otkriva pri proveri lanca.

Podaci potiču iz (izmišljenog) starog dBase programa: `magacin.dbf` u kodnoj strani cp852, uvezen alatom iz repoa `legacy-formats-to-mysql` i prebačen u čistu tabelu `artikli`.

> **Status:** faze 0, 1 i 2 su gotove: lista artikala, prijava, dodavanje/izmena/brisanje i audit log sa heš lancem i proverom integriteta.

## Tehnologije

| | |
|---|---|
| Backend | PHP 8.5, Laravel 13 |
| Baza | MariaDB 10.11 |
| Frontend | Blade + Bootstrap 5.3 (CDN), bez npm-a i Vite-a |
| Okruženje | Docker Compose: `app` (Apache), `db`, `phpmyadmin` |
| Testovi | PHPUnit, nad posebnom MariaDB bazom `audit_log_test` |

## Pokretanje

Potreban je samo Docker (Docker Desktop na Windows-u).

```
git clone https://github.com/Keks20/audit-log-hash-chain.git
cd audit-log-hash-chain
docker compose up -d --build
```

Prvi start traje nešto duže: kontejner sam pravi `.env` iz `.env.example`, radi `composer install`, generiše ključ, pokreće migracije i puni bazu (37 artikala i dva test naloga). MariaDB pri prvom startu pravi i test bazu `audit_log_test`.

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
- **Dodavanje, izmena i brisanje artikla** samo za prijavljene. Šifra se ne menja posle dodavanja, popunjeno polje ne sme da se isprazni, a čuvanje bez stvarne promene se ne upisuje.
- **Audit log** (`/audit-log`): svaka izmena sa vremenom, korisnikom, akcijom i razlikom „pre → posle“, uz istoriju pojedinačnog artikla i dugme „Proveri lanac“.
- Količina i cena primaju decimalni zarez (`120,50`). Poruke su na srpskom, a vreme se prikazuje po Beogradu.

## Kako radi heš lanac

1. **Kanonski JSON artikla** (`App\Support\KanonskiJson`): ključevi su sortirani, decimale su stringovi (`"120.50"`), datum je `Y-m-d`, a `aktivan` je `true`/`false`/`null`. Float se odbija, a tekst se ne normalizuje (ni `\r\n`, ni razmaci), jer bi svaka normalizacija sakrila stvarnu promenu.
2. **Zapis loga** čuva šifru, akciju (`dodat`/`izmenjen`/`obrisan`), korisnika, stanje **pre** i **posle** (kanonski JSON), vreme u UTC i `prethodni_hes`.
3. **Heš zapisa** je SHA-256 kanonskog JSON-a svih tih polja, uključujući `prethodni_hes`. Prvi zapis ima 64 nule kao prethodni.
4. **Upis** ide u istoj transakciji sa izmenom artikla: ili se upišu oba, ili nijedno. Upisi se redaju preko jednog zaključanog reda (`audit_log_glava`), a UNIQUE na `prethodni_hes` ne dozvoljava da se lanac račva.
5. **Provera** (`php artisan audit:provera` ili dugme na stranici) prolazi kroz ceo lanac i javlja prvi zapis na kome je pukao: izmenjen sadržaj, podmetnut heš, obrisan ili ubačen zapis. Zatim proverava da glava pokazuje na poslednji zapis.
6. **Tragovi:** kad lanac nije ispravan, provera pokazuje i šta se ne slaže. Stara vrednost se iz heša ne može izračunati (heš je jednosmeran), ali log ima unakrsne veze:
   - stanje „pre“ jednog zapisa mora biti „posle“ prethodnog zapisa za isti artikal;
   - šifra mora odgovarati šifri u stanju artikla, a email email-u korisnika sa tim `korisnik_id`;
   - vreme mora biti između vremena susednih zapisa;
   - sledeći zapis čuva originalni heš.

   Na primer: „Stanje posle: polje „Naziv“ je „Šećer lažni“, a sledeći zapis #3 za isti artikal kaže „Šećer kristal“.“ Kod obrisanog zapisa iz sredine pokazuje se i promena koja je njime nestala.
7. **Trigeri u bazi** odbijaju svaki UPDATE i DELETE nad `audit_log` i brisanje glave, a glavu puštaju da se pomeri samo na sledeći zapis lanca. Izmena loga iz phpMyAdmin-a se zato odbija porukom „audit_log: zapis se ne menja“.

Log nema strane ključeve ka `artikli` ni `users`: `ON DELETE SET NULL`/`CASCADE` bi menjali zapise loga i kidali lanac. Zato čuva kopije (šifra, email).

### Ograničenje i sidro

Trigeri sprečavaju slučajnu i naivnu izmenu, ali ko ima prava nad šemom može da ih obriše (ili da uradi `TRUNCATE`, koji DELETE trigger ne pokreće). Takav napadač, ako zna algoritam, može da obriše zapise sa kraja i „popravi“ glavu, ili da ceo lanac ponovo izračuna. Takav lanac izgleda ispravno. To otkriva samo **sidro**: poslednji heš sačuvan **van baze**. Provera ga ispisuje na kraju, a kasnije se proverava sa:

```
docker compose exec app php artisan audit:provera --sidro=<heš>
```

Digitalni potpis zapisa planiran je za kasniju fazu.

## Testovi

```
docker compose exec app php artisan test
```

Testovi rade nad posebnom MariaDB bazom `audit_log_test` (`phpunit.xml`). `tests/TestCase.php` odbija da pokrene testove nad bazom čije ime se ne završava na `_test`, pa glavna baza nikad ne može biti obrisana.

Testovi u `AuditLogProveraTest` glume napadača: uklone trigere, pa menjaju, brišu i ponovo izračunavaju zapise. Zatim proveravaju da provera nađe tačan zapis i prave tragove.

Ako je volume baze napravljen pre nego što je test baza uvedena, napravi je jednom:

```
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' < docker/mariadb-init/01-test-baza.sql
```

## Podaci

- `magacin`: sirova tabela uvezena iz `magacin.dbf` (40 zapisa, 3 obrisana u dBase-u). Ne postoji posle kloniranja; nije je napravila migracija.
- `artikli`: čista tabela aplikacije.
- `database/data/artikli.json`: 37 artikala u repou. `ArtikliSeeder` iz njega puni samo **praznu** tabelu `artikli`, pa izmene iz aplikacije ostaju. Fajl se pravi komandom `php artisan artikli:izvoz`. Punjenje iz seedera se ne upisuje u log; početno stanje artikla beleži njegova prva izmena (polje „pre“).
- `audit_log` i `audit_log_glava`: log sa heš lancem i njegov poslednji heš.

## Plan

- [x] **Faza 0:** Laravel + Docker, uvoz `magacin.dbf`, tabela `artikli`, lista artikala
- [x] **Faza 1:** prijava, dodavanje, izmena i brisanje artikla
- [x] **Faza 2:** kanonski JSON, audit log sa heš lancem, provera integriteta sa tragovima, sidro, trigeri, prikaz loga
- [ ] Kasnije: digitalni potpis zapisa
