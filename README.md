# Audit log sa heš lancem

Laravel aplikacija za mali magacin u kojoj se svaka izmena artikla upisuje u audit log zaštićen heš lancem i digitalnim potpisom: svaki zapis sadrži heš prethodnog i potpis ključem koji stoji van baze, pa se naknadno menjanje, brisanje ili dopisivanje zapisa otkriva pri proveri.

Podaci potiču iz (izmišljenog) starog dBase programa: `magacin.dbf` u kodnoj strani cp852, uvezen alatom iz repoa `legacy-formats-to-mysql` i prebačen u čistu tabelu `artikli`.

> **Status:** sve četiri faze su gotove (vidi [Plan](#plan)).

## Čemu služi

U običnoj aplikaciji onaj ko ima pristup bazi može da promeni količinu ili cenu i da iz evidencije obriše trag o tome. Ovde to ne prolazi neprimećeno:

| Šta neko uradi mimo aplikacije | Šta to otkriva |
|---|---|
| Izmeni ili obriše zapis u logu | trigeri u bazi ga odbiju; ako ih ukloni, heš lanac |
| Ubaci zapis u sredinu ili promeni redosled | heš lanac |
| Dopiše uredan zapis na kraj, ili ponovo izračuna ceo lanac | digitalni potpis (nema tajni ključ) |
| Obriše zapise sa kraja i „popravi“ glavu | sidro sačuvano van baze |
| Izmeni, obriše ili doda artikal direktno u tabeli `artikli` | poređenje artikala sa logom |
| Zameni ključ na serveru i sve ponovo potpiše | javni ključ sačuvan van servera |

Uz to, log je i koristan svaki dan: vidi se ko je šta menjao i kada, istorija svakog artikla, i svako ranije stanje može da se vrati jednim klikom.

## Tehnologije

| | |
|---|---|
| Backend | PHP 8.5, Laravel 13 |
| Baza | MariaDB 10.11 |
| Kriptografija | SHA-256 (lanac), Ed25519 preko libsodium-a (potpis) |
| Frontend | Blade + Bootstrap 5.3 (CDN sa `integrity`), bez npm-a i Vite-a |
| Okruženje | Docker Compose: `app` (Apache), `db`, `phpmyadmin` |
| Testovi | PHPUnit, nad posebnom MariaDB bazom `audit_log_test`; GitHub Actions |

## Pokretanje

Potreban je samo Docker (Docker Desktop na Windows-u).

```
git clone https://github.com/Keks20/audit-log-hash-chain.git
cd audit-log-hash-chain
docker compose up -d --build
```

Prvi start traje nešto duže: kontejner sam pravi `.env` iz `.env.example`, radi `composer install`, generiše ključ aplikacije i ključ za potpis loga, pokreće migracije i puni bazu (37 artikala sa početnim snimkom u logu i dva test naloga). MariaDB pri prvom startu pravi i test bazu `audit_log_test`.

| Servis | Adresa |
|---|---|
| Aplikacija | http://localhost:8081 |
| phpMyAdmin | http://localhost:8082 |
| MariaDB spolja | `127.0.0.1:3308`, baza `audit_log` |

Svi portovi su vezani samo na `127.0.0.1`.

Kraj rada: `docker compose stop`. Podaci baze ostaju u Docker volume-u.

## Test nalozi

| Uloga | Email | Sme |
|---|---|---|
| Magacioner | `magacioner@magacin.test` | dodavanje i izmena artikala, pregled i izvoz loga, provera lanca |
| Šef | `sef@magacin.test` | sve to, i brisanje artikala i vraćanje stanja iz loga |

Lozinka za oba naloga je vrednost `DEMO_LOZINKA` iz `.env` (podrazumevano `lozinka`). Ta lozinka, kao i `audit` / `audit` za bazu, postoji samo za lokalni razvoj.

## Šta aplikacija radi

- **Lista artikala** je javna, sa pretragom po šifri ili nazivu. Prazne vrednosti iz starog programa prikazuju se kao „—“, a nepoznato stanje „aktivan“ kao „?“.
- **Prijava i odjava**: posle 5 pogrešnih pokušaja prijava se blokira na minut, a sesija se obnavlja posle prijave i poništava posle odjave.
- **Dodavanje i izmena artikla** za prijavljene, **brisanje** samo za šefa. Šifra se ne menja posle dodavanja, popunjeno polje ne sme da se isprazni, a čuvanje bez stvarne promene se ne upisuje.
- **Audit log** (`/audit-log`): svaka izmena sa vremenom, korisnikom, akcijom i razlikom „pre → posle“.
  - Istorija pojedinačnog artikla (radi i za obrisan artikal).
  - Filteri: akcija, korisnik, početak šifre, period (dani po Beogradu).
  - **Izvoz u CSV** za Excel, sa istim filterima, heševima i potpisima.
  - Dugme **„Proveri lanac“** i ishod poslednje automatske provere.
- **Vraćanje stanja** (šef): svaki zapis u logu ima dugme „Vrati“. Postojeći artikal se vraća na stanje iz tog zapisa, a obrisani se dodaje ponovo. I vraćanje je izmena kao svaka druga, pa ostavlja svoj zapis; log se nikad ne prepravlja.
- Količina i cena primaju decimalni zarez (`120,50`). Poruke su na srpskom, a vreme se prikazuje po Beogradu.

## Kako radi heš lanac

1. **Kanonski JSON artikla** (`App\Support\KanonskiJson`): ključevi su sortirani, decimale su stringovi (`"120.50"`), datum je `Y-m-d`, a `aktivan` je `true`/`false`/`null`. Float se odbija, a tekst se ne normalizuje (ni `\r\n`, ni razmaci), jer bi svaka normalizacija sakrila stvarnu promenu.
2. **Zapis loga** čuva šifru, akciju (`pocetno`/`dodat`/`izmenjen`/`obrisan`), korisnika, stanje **pre** i **posle** (kanonski JSON), vreme u UTC i `prethodni_hes`.
3. **Heš zapisa** je SHA-256 kanonskog JSON-a svih tih polja, uključujući `prethodni_hes`. Prvi zapis ima 64 nule kao prethodni.
4. **Potpis zapisa** je Ed25519 potpis tog heša (vidi [Digitalni potpis](#digitalni-potpis)).
5. **Upis** ide u istoj transakciji sa izmenom artikla: ili se upišu oba, ili nijedno. Upisi se redaju preko jednog zaključanog reda (`audit_log_glava`), a UNIQUE na `prethodni_hes` ne dozvoljava da se lanac račva.
6. **Provera** (`php artisan audit:provera` ili dugme na stranici) prolazi kroz ceo lanac i javlja prvi zapis na kome je pukao: izmenjen sadržaj, podmetnut heš, obrisan ili ubačen zapis, potpis koji ne odgovara. Zatim proverava da glava pokazuje na poslednji zapis, pa poredi artikle sa logom.
7. **Tragovi:** kad lanac nije ispravan, provera pokazuje i šta se ne slaže. Stara vrednost se iz heša ne može izračunati (heš je jednosmeran), ali log ima unakrsne veze:
   - stanje „pre“ jednog zapisa mora biti „posle“ prethodnog zapisa za isti artikal;
   - šifra mora odgovarati šifri u stanju artikla, a email email-u korisnika sa tim `korisnik_id`;
   - vreme mora biti između vremena susednih zapisa;
   - sledeći zapis čuva originalni heš.

   Na primer: „Stanje posle: polje „Naziv“ je „Šećer lažni“, a sledeći zapis #3 za isti artikal kaže „Šećer kristal“.“ Kod obrisanog zapisa iz sredine pokazuje se i promena koja je njime nestala.
8. **Trigeri u bazi** odbijaju svaki UPDATE i DELETE nad `audit_log` i brisanje glave, a glavu puštaju da se pomeri samo na sledeći zapis lanca. Izmena loga iz phpMyAdmin-a se zato odbija porukom „audit_log: zapis se ne menja“.

Log nema strane ključeve ka `artikli` ni `users`: `ON DELETE SET NULL`/`CASCADE` bi menjali zapise loga i kidali lanac. Zato čuva kopije (šifra, email).

### Početni snimak i stanje artikala

Lanac čuva log, ali ne i samu tabelu `artikli`: neko bi mogao da promeni cenu direktno u bazi, a da log ne dirne. Zato:

- `ArtikliSeeder` posle punjenja upisuje za svaki artikal zapis **`pocetno`** (stanje iz seedera, bez korisnika). Za bazu koja je imala artikle pre toga postoji `php artisan audit:snimak`.
- Provera na kraju poredi svaki artikal sa svojim **poslednjim zapisom** u logu i javlja: artikal izmenjen mimo aplikacije (sa poljem i obe vrednosti), artikal obrisan mimo aplikacije, artikal koji postoji iako ga je log obrisao, i artikal bez ijednog zapisa.

Primer: „Artikal 001001: polje „Cena“ u tabeli je „1.00“, a poslednji zapis #5 u logu kaže „100.00“ (izmena mimo aplikacije).“

Početni snimak beleži stanje kakvo jeste u tom trenutku; ne može da zna šta je bilo pre njega.

### Digitalni potpis

Heš nema tajnu: ko ima pristup bazi i zna algoritam može da dopiše uredan zapis ili da ponovo izračuna ceo lanac. Zato svaki zapis nosi **Ed25519 potpis** svog heša (`App\Services\PotpisZapisa`).

- **Tajni ključ** je `AUDIT_KLJUC_POTPISA` u `.env`, dakle van baze. Pravi ga `php artisan audit:kljuc` (kontejner to radi sam pri prvom startu) i nikad ga ne ispisuje. Bez ključa aplikacija odbija upis, da log ne bi tiho ostao bez potpisa.
- **Javni ključ** (`php artisan audit:kljuc --javni`) sme da vidi svako. Dovoljan je za proveru, pa revizor može da proveri log bez ikakve tajne.
- Potpis ne ulazi u heš, pa uvođenje potpisa nije promenilo nijedan stari heš. Zapisi nastali pre potpisa ostaju nepotpisani (log se ne prepravlja); provera ih dozvoljava samo na početku lanca i ispisuje koliko ih je.

### Sidro i ograničenja

Ostaje napadač koji ima i bazu i server (dakle i tajni ključ): on može da skrati ili ponovo izračuna i potpiše lanac. To otkriva samo ono što je sačuvano **van njegovog domašaja**:

- **Sidro** je poslednji heš. `php artisan audit:provera --sacuvaj` ga dopisuje u fajl `storage/app/private/sidra.jsonl`, a `--sidro=poslednje` (i dugme na stranici) traže ga u lancu. Ako ga nema, lanac je skraćen ili ponovo izračunat.
- **Javni ključ** sačuvan van servera otkriva zamenu ključa: `php artisan audit:provera --javni-kljuc=<hex>`.

Fajl sidara je na istom računaru kao aplikacija. Prava zaštita traži da kopija sidra i javnog ključa stoji negde drugde (drugi server, mejl, papir). To je ograničenje svakog sistema ove vrste: integritet se ne može dokazati samo podacima kojima napadač ima potpun pristup.

### Automatska provera

`php artisan audit:nadzor` radi isto što i ručna provera, sam: proveri lanac, potpise, sidro i stanje artikala, dopiše novo sidro ako je sve u redu, a grešku upiše u log aplikacije. Ishod se vidi na vrhu stranice `/audit-log`. Pokreće se svakog sata (`routes/console.php`, `schedule:work` iz `docker/start.sh`).

### Komande

```
docker compose exec app php artisan audit:provera                    # lanac, potpisi, stanje artikala
docker compose exec app php artisan audit:provera --sacuvaj          # i dopiši sidro u fajl
docker compose exec app php artisan audit:provera --sidro=poslednje  # traži poslednje sačuvano sidro
docker compose exec app php artisan audit:provera --sidro=<heš>
docker compose exec app php artisan audit:provera --javni-kljuc=<hex>
docker compose exec app php artisan audit:provera --json             # za skripte; izlazni kod 0/1
docker compose exec app php artisan audit:snimak                     # početni snimak za artikle bez zapisa
docker compose exec app php artisan audit:kljuc [--javni|--zameni]
docker compose exec app php artisan audit:nadzor
```

## Bezbednost

- Sav ispis ide kroz `{{ }}`, forme imaju CSRF token, odjava je POST.
- Uloga korisnika ne može da stigne iz forme (nije u `Fillable`).
- Zahtev sa neispravnim UTF-8 dobija 400 pre sesije i baze.
- Zaglavlja `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` na svakom odgovoru; Bootstrap sa CDN-a ima `integrity`.
- CSV izvoz stavlja apostrof ispred teksta koji bi Excel izvršio kao formulu.
- „Vrati“ ne vraća stanje iz zapisa koji ne prolazi proveru heša i potpisa.

## Testovi

```
docker compose exec app php artisan test
```

Testovi rade nad posebnom MariaDB bazom `audit_log_test` (`phpunit.xml`), sa svojim ključem za potpis i svojim fajlom sidara. `tests/TestCase.php` odbija da pokrene testove nad bazom čije ime se ne završava na `_test`, pa glavna baza nikad ne može biti obrisana.

Testovi u `AuditLogProveraTest`, `AuditLogPotpisTest` i `SidroTest` glume napadača: uklone trigere, pa menjaju, brišu, dopisuju i ponovo izračunavaju zapise, sa ključem i bez njega. Zatim proveravaju da provera nađe tačan zapis i prave tragove.

GitHub Actions (`.github/workflows/testovi.yml`) na svaki push radi isto što i čovek posle kloniranja: `docker compose up -d --build`, ceo paket testova i proveru lanca u svežoj bazi.

Ako je volume baze napravljen pre nego što je test baza uvedena, napravi je jednom:

```
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' < docker/mariadb-init/01-test-baza.sql
```

## Podaci

- `magacin`: sirova tabela uvezena iz `magacin.dbf` (40 zapisa, 3 obrisana u dBase-u). Ne postoji posle kloniranja; nije je napravila migracija.
- `artikli`: čista tabela aplikacije.
- `database/data/artikli.json`: 37 artikala u repou. `ArtikliSeeder` iz njega puni samo **praznu** tabelu `artikli`, pa izmene iz aplikacije ostaju. Fajl se pravi komandom `php artisan artikli:izvoz`.
- `audit_log` i `audit_log_glava`: log sa heš lancem i njegov poslednji heš.
- `users`: dva test naloga sa kolonom `uloga`.

## Plan

- [x] **Faza 0:** Laravel + Docker, uvoz `magacin.dbf`, tabela `artikli`, lista artikala
- [x] **Faza 1:** prijava, dodavanje, izmena i brisanje artikla
- [x] **Faza 2:** kanonski JSON, audit log sa heš lancem, provera integriteta sa tragovima, sidro, trigeri, prikaz loga
- [x] **Faza 3:** digitalni potpis zapisa, početni snimak, poređenje artikala sa logom, čuvanje sidra u fajl
- [x] **Faza 4:** uloge, vraćanje stanja iz loga, filteri i CSV izvoz, pretraga artikala, automatska provera, bezbednosna zaglavlja, GitHub Actions
