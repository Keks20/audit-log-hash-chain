<?php

namespace App\Services;

use App\Models\Artikal;
use App\Models\AuditZapis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kad provera nadje los zapis, trazi tragove sta je promenjeno.
 *
 * Stara vrednost se iz hesa ne moze izracunati (hes je jednosmeran), ali log
 * ima unakrsne veze: "pre" jednog zapisa je "posle" prethodnog za isti artikal,
 * sifra stoji i u stanju artikla, email pripada korisniku, vreme raste, a
 * sledeci zapis cuva originalni hes. Gde se te veze ne slazu, tu je trag.
 * Trag kaze sta se ne slaze, ne i koja strana je izmenjena.
 */
class DijagnostikaLanca
{
    private const NAZIVI_POLJA = [
        'sifra'         => 'Šifra',
        'naziv'         => 'Naziv',
        'jedinica_mere' => 'JM',
        'kolicina'      => 'Količina',
        'cena'          => 'Cena',
        'datum_nabavke' => 'Datum nabavke',
        'aktivan'       => 'Aktivan',
        'napomena'      => 'Napomena',
    ];

    /**
     * Zapis cija polja ne daju sacuvani hes.
     *
     * @return string[]
     */
    public function izmenjenSadrzaj(object $red, ?object $prethodni): array
    {
        $sledeci = DB::table('audit_log')->where('id', '>', $red->id)->orderBy('id')->first();
        $izracunat = AuditLog::hes((array) $red);

        // Sledeci zapis cuva originalni hes ovog zapisa
        if ($sledeci !== null && $sledeci->prethodni_hes === $izracunat) {
            return ["Podaci zapisa su netaknuti: promenjeno je samo polje hes (sledeći zapis #{$sledeci->id} potvrđuje heš izračunat iz podataka)."];
        }

        $tragovi = [];
        $osnovnih = 0;

        if ($sledeci !== null && $sledeci->prethodni_hes === $red->hes) {
            $tragovi[] = "Polje hes je originalno (potvrđuje ga sledeći zapis #{$sledeci->id}), dakle promenjeni su podaci zapisa.";
            $osnovnih = 1;
        }

        $pre = $this->stanje($red->pre, 'pre', $tragovi);
        $posle = $this->stanje($red->posle, 'posle', $tragovi);

        $akcijaOdgovara = match ($red->akcija) {
            AuditZapis::DODAT,
            AuditZapis::POCETNO  => $red->pre === null && $red->posle !== null,
            AuditZapis::IZMENJEN => $red->pre !== null && $red->posle !== null,
            AuditZapis::OBRISAN  => $red->pre !== null && $red->posle === null,
            default              => false,
        };
        if (! $akcijaOdgovara) {
            $tragovi[] = "Akcija „{$red->akcija}“ ne odgovara stanjima pre/posle u zapisu.";
        }

        foreach ([$pre, $posle] as $stanje) {
            if (is_array($stanje) && ($stanje['sifra'] ?? null) !== $red->sifra) {
                $tragovi[] = "Polje sifra je „{$red->sifra}“, a u stanju artikla piše „" . $this->vrednost($stanje['sifra'] ?? null) . '“.';
                break;
            }
        }

        if ($red->korisnik_id !== null) {
            $email = DB::table('users')->where('id', $red->korisnik_id)->value('email');
            if ($email !== null && $email !== $red->korisnik_email) {
                $tragovi[] = "Korisnik #{$red->korisnik_id} ima email „{$email}“, a u zapisu piše „{$red->korisnik_email}“.";
            }
        }

        if ($prethodni !== null && $red->vreme < $prethodni->vreme) {
            $tragovi[] = 'Vreme ' . $this->vreme($red->vreme) . " je pre vremena prethodnog zapisa #{$prethodni->id} (" . $this->vreme($prethodni->vreme) . ').';
        }
        if ($sledeci !== null && $red->vreme > $sledeci->vreme) {
            $tragovi[] = 'Vreme ' . $this->vreme($red->vreme) . " je posle vremena sledećeg zapisa #{$sledeci->id} (" . $this->vreme($sledeci->vreme) . ').';
        }
        if ($red->vreme > now('UTC')->format('Y-m-d H:i:s')) {
            $tragovi[] = 'Vreme ' . $this->vreme($red->vreme) . ' je u budućnosti.';
        }

        // "pre" ovog zapisa = "posle" prethodnog zapisa za isti artikal
        if (is_array($pre) && ($raniji = $this->susedZaArtikal($red, '<')) !== null) {
            $stanjeRanije = $this->stanje($raniji->posle, null);
            if (is_array($stanjeRanije)) {
                foreach ($this->razlike($pre, $stanjeRanije) as $polje => [$ovde, $tamo]) {
                    $tragovi[] = "Stanje pre: polje „{$polje}“ je „{$ovde}“, a prethodni zapis #{$raniji->id} za isti artikal kaže „{$tamo}“.";
                }
            }
        }

        // "posle" ovog zapisa = "pre" sledeceg zapisa za isti artikal (ili trenutno stanje artikla)
        if (is_array($posle)) {
            $kasniji = $this->susedZaArtikal($red, '>');
            if ($kasniji !== null) {
                $stanjeKasnije = $this->stanje($kasniji->pre, null);
                if (is_array($stanjeKasnije)) {
                    foreach ($this->razlike($posle, $stanjeKasnije) as $polje => [$ovde, $tamo]) {
                        $tragovi[] = "Stanje posle: polje „{$polje}“ je „{$ovde}“, a sledeći zapis #{$kasniji->id} za isti artikal kaže „{$tamo}“.";
                    }
                }
            } elseif (($artikal = Artikal::find($red->artikal_id)) !== null) {
                foreach ($this->razlike($posle, $artikal->kanonskiPodaci()) as $polje => [$ovde, $tamo]) {
                    $tragovi[] = "Stanje posle: polje „{$polje}“ je „{$ovde}“, a artikal u tabeli artikli sada ima „{$tamo}“.";
                }
            }
        }

        if (count($tragovi) === $osnovnih) {
            $tragovi[] = 'Nijedna unakrsna provera ne pokazuje koje polje je promenjeno (npr. artikal_id ili vreme u dozvoljenom opsegu). '
                . 'Heš je jednosmeran, pa se stara vrednost ne može izračunati.';
        }

        return $tragovi;
    }

    /**
     * Zapis ciji prethodni_hes ne pokazuje na zapis ispred njega.
     *
     * @return string[]
     */
    public function prekinutaVeza(object $red, ?object $prethodni): array
    {
        $tragovi = [];

        if ($red->prethodni_hes === AuditZapis::POCETNI_HES) {
            $tragovi[] = "Zapis tvrdi da je prvi u lancu, a ispred njega je zapis #{$prethodni?->id}: zapisi su ubačeni ispred njega ili je on premešten.";
        } elseif (($naveden = DB::table('audit_log')->where('hes', $red->prethodni_hes)->first()) !== null) {
            $ispred = $prethodni !== null ? "#{$prethodni->id}" : 'početak lanca';
            $tragovi[] = "Zapis kao prethodni navodi #{$naveden->id}, a ispred njega je {$ispred}: redosled zapisa je promenjen.";
        } else {
            $tragovi[] = 'Zapis na koji ovaj pokazuje kao prethodni (heš ' . substr($red->prethodni_hes, 0, 12) . '…) ne postoji: obrisan je'
                . ($prethodni === null ? ' sa početka lanca.' : '.');

            if ($prethodni !== null && $red->id - $prethodni->id > 1) {
                $tragovi[] = "Između zapisa #{$prethodni->id} i #{$red->id} nedostaje " . ($red->id - $prethodni->id - 1) . ' id '
                    . '(praznine nastaju i od poništenih transakcija, ali ovde se poklapaju sa prekidom lanca).';
            }
        }

        // Sta je bilo u obrisanom zapisu: razlika izmedju poslednjeg poznatog stanja artikla i "pre" ovog zapisa
        $pre = $this->stanje($red->pre, null);
        if (is_array($pre) && ($raniji = $this->susedZaArtikal($red, '<')) !== null) {
            $stanjeRanije = $this->stanje($raniji->posle, null);
            if (is_array($stanjeRanije)) {
                foreach ($this->razlike($stanjeRanije, $pre) as $polje => [$bilo, $postalo]) {
                    $tragovi[] = "Za artikal {$red->sifra} nedostaje zabeležena promena između zapisa #{$raniji->id} i #{$red->id}: "
                        . "„{$polje}“ „{$bilo}“ → „{$postalo}“ (verovatno sadržaj obrisanog zapisa).";
                }
            }
        }

        return $tragovi;
    }

    /**
     * Glava ne pokazuje na poslednji zapis.
     *
     * @return string[]
     */
    public function glava(?object $glava, ?object $poslednji): array
    {
        if ($glava === null) {
            return ['Red glave (audit_log_glava, id 1) ne postoji.'];
        }

        $uGlavi = (int) $glava->poslednji_id;
        $stvarno = (int) $poslednji?->id;

        return match (true) {
            $stvarno === 0 && $uGlavi > 0 => ["Glava pokazuje na zapis #{$uGlavi}, a log je prazan: obrisani su svi zapisi."],
            $uGlavi > $stvarno            => ["Glava pokazuje na zapis #{$uGlavi}, a poslednji postojeći je #{$stvarno}: zapisi posle #{$stvarno} su obrisani."],
            $uGlavi < $stvarno            => ["Glava pokazuje na zapis #{$uGlavi}, a postoje zapisi do #{$stvarno}: dodati su mimo aplikacije ili je glava vraćena unazad."],
            default                       => ["Glava i poslednji zapis #{$stvarno} imaju isti id, ali različit heš: izmenjena je glava ili poslednji zapis."],
        };
    }

    /**
     * Artikal u tabeli prema svom poslednjem zapisu u logu.
     *
     * @return string[]
     */
    public function stanjeArtikla(Artikal $artikal, ?object $zapis): array
    {
        if ($zapis === null) {
            return ["Artikal {$artikal->sifra} nema nijedan zapis u logu: dodat je mimo aplikacije, "
                . 'ili još nije napravljen početni snimak (php artisan audit:snimak).'];
        }

        if ($zapis->akcija === AuditZapis::OBRISAN) {
            return ["Artikal {$artikal->sifra} postoji u tabeli artikli, a poslednji zapis #{$zapis->id} u logu kaže da je obrisan."];
        }

        if ($zapis->posle === $artikal->kanonskiJson()) {
            return [];
        }

        $uLogu = $this->stanje($zapis->posle, null);
        $tragovi = [];

        foreach ($this->razlike($artikal->kanonskiPodaci(), is_array($uLogu) ? $uLogu : []) as $polje => [$uTabeli, $poLogu]) {
            $tragovi[] = "Artikal {$artikal->sifra}: polje „{$polje}“ u tabeli je „{$uTabeli}“, "
                . "a poslednji zapis #{$zapis->id} u logu kaže „{$poLogu}“ (izmena mimo aplikacije).";
        }

        return $tragovi;
    }

    private function susedZaArtikal(object $red, string $smer): ?object
    {
        return DB::table('audit_log')
            ->where('artikal_id', $red->artikal_id)
            ->where('id', $smer, $red->id)
            ->orderBy('id', $smer === '<' ? 'desc' : 'asc')
            ->first();
    }

    /**
     * JSON stanja u niz; neispravan JSON dodaje trag i vraca false.
     */
    private function stanje(?string $json, ?string $polje, array &$tragovi = []): array|false|null
    {
        if ($json === null) {
            return null;
        }

        $niz = json_decode($json, true);
        if (! is_array($niz)) {
            if ($polje !== null) {
                $tragovi[] = "Polje {$polje} nije ispravan JSON.";
            }

            return false;
        }

        return $niz;
    }

    /**
     * Polja u kojima se dva stanja razlikuju, sa vrednostima za prikaz.
     */
    private function razlike(array $a, array $b): array
    {
        $razlike = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $polje) {
            if (($a[$polje] ?? null) !== ($b[$polje] ?? null)) {
                $razlike[self::NAZIVI_POLJA[$polje] ?? $polje] = [$this->vrednost($a[$polje] ?? null), $this->vrednost($b[$polje] ?? null)];
            }
        }

        return $razlike;
    }

    private function vrednost(mixed $v): string
    {
        return match (true) {
            $v === null  => '—',
            $v === true  => 'da',
            $v === false => 'ne',
            default      => (string) $v,
        };
    }

    private function vreme(string $utc): string
    {
        return Carbon::parse($utc, 'UTC')->timezone(config('magacin.zona_prikaza'))->format('d.m.Y. H:i:s');
    }
}
