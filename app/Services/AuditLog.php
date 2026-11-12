<?php

namespace App\Services;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Support\KanonskiJson;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Upis u audit log i provera hes lanca.
 *
 * Hes zapisa = SHA-256 kanonskog JSON-a svih polja zapisa osim id-a i samog
 * hesa, ukljucujuci prethodni_hes. Zato izmena bilo kog starog zapisa menja
 * njegov hes, a time i vezu sa svim zapisima posle njega.
 */
class AuditLog
{
    public function __construct(
        private DijagnostikaLanca $dijagnostika = new DijagnostikaLanca(),
        private PotpisZapisa $potpis = new PotpisZapisa(),
    ) {}

    /**
     * Poziva se u ISTOJ transakciji u kojoj se menja artikal: ili se upisu
     * oba, ili nijedno.
     */
    public function zapisi(string $akcija, Artikal $artikal, ?array $pre, ?array $posle, ?User $korisnik): AuditZapis
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Upis u audit log mora biti u istoj transakciji sa izmenom artikla.');
        }

        // Zakljucavamo glavu lanca: drugi upis ceka dok se ova transakcija ne zavrsi.
        // UNIQUE na prethodni_hes je druga linija odbrane od racvanja lanca.
        $glava = DB::table('audit_log_glava')->where('id', 1)->lockForUpdate()->first();

        if ($glava === null) {
            throw new LogicException('Nedostaje glava audit loga (tabela audit_log_glava).');
        }

        $polja = [
            'artikal_id'     => $artikal->id,
            'sifra'          => $artikal->sifra,
            'akcija'         => $akcija,
            'korisnik_id'    => $korisnik?->id,
            'korisnik_email' => $korisnik?->email,
            'pre'            => $pre === null ? null : KanonskiJson::encode($pre),
            'posle'          => $posle === null ? null : KanonskiJson::encode($posle),
            'vreme'          => now('UTC')->format('Y-m-d H:i:s'),
            'prethodni_hes'  => $glava->poslednji_hes,
        ];

        $polja['hes'] = self::hes($polja);

        // Potpis ne ulazi u hes: potpisuje se gotov hes, tajnim kljucem van baze.
        // Bez kljuca se ne upisuje nista, da log ne bi tiho ostao bez potpisa.
        $polja['potpis'] = $this->potpis->potpisi($polja['hes']);

        $zapis = AuditZapis::create($polja);

        DB::table('audit_log_glava')->where('id', 1)->update([
            'poslednji_id'  => $zapis->id,
            'poslednji_hes' => $zapis->hes,
        ]);

        return $zapis;
    }

    /**
     * Pocetni snimak: za svaki artikal koji nema nijedan zapis u logu upisuje
     * zapis "pocetno" sa trenutnim stanjem, bez korisnika. Tako i artikli iz
     * seedera (ili iz vremena pre loga) imaju stanje sa kojim se porede.
     *
     * Svaki artikal ide u svojoj transakciji: brave se uzimaju istim redom kao
     * u kontroleru (artikal, pa glava), pa nema deadlock-a sa izmenama u toku.
     * Sme da se pokrene vise puta.
     */
    public function pocetniSnimak(): int
    {
        $upisano = 0;

        $bezZapisa = Artikal::query()
            ->whereNotIn('id', DB::table('audit_log')->select('artikal_id'))
            ->orderBy('id')
            ->pluck('id');

        foreach ($bezZapisa as $id) {
            $upisano += DB::transaction(function () use ($id) {
                $artikal = Artikal::lockForUpdate()->find($id);

                // U medjuvremenu obrisan ili vec upisan u log iz aplikacije
                if ($artikal === null || DB::table('audit_log')->where('artikal_id', $id)->exists()) {
                    return 0;
                }

                $this->zapisi(AuditZapis::POCETNO, $artikal, null, $artikal->kanonskiPodaci(), null);

                return 1;
            });
        }

        return $upisano;
    }

    /**
     * Da li jedan zapis ima hes koji odgovara podacima i ispravan potpis.
     * Ne proverava mesto zapisa u lancu (to radi proveri()).
     */
    public function zapisJeNetaknut(int $id): bool
    {
        $red = DB::table('audit_log')->where('id', $id)->first();

        if ($red === null || ! hash_equals($red->hes, self::hes((array) $red))) {
            return false;
        }

        // Stari zapisi iz vremena pre potpisa nemaju potpis
        if ($red->potpis === null) {
            return true;
        }

        return $this->potpis->imaKljuc()
            && $this->potpis->odgovara($red->hes, $red->potpis, $this->potpis->javniKljuc());
    }

    /**
     * Hes jednog zapisa. Tipovi se svode na stalne (int, string, null), jer
     * baza moze da vrati broj kao string, a hes mora biti isti.
     */
    public static function hes(array $polja): string
    {
        $nullIliString = fn ($v) => $v === null ? null : (string) $v;

        return hash('sha256', KanonskiJson::encode([
            'akcija'         => (string) $polja['akcija'],
            'artikal_id'     => (int) $polja['artikal_id'],
            'korisnik_email' => $nullIliString($polja['korisnik_email']),
            'korisnik_id'    => $polja['korisnik_id'] === null ? null : (int) $polja['korisnik_id'],
            'posle'          => $nullIliString($polja['posle']),
            'pre'            => $nullIliString($polja['pre']),
            'prethodni_hes'  => (string) $polja['prethodni_hes'],
            'sifra'          => (string) $polja['sifra'],
            'vreme'          => (string) $polja['vreme'],
        ]));
    }

    /**
     * Prolazi kroz ceo lanac redom i vraca prvi zapis na kome je pukao,
     * pa proverava da glava pokazuje na poslednji zapis.
     *
     * Ko ima pristup bazi moze da obrise zapise sa kraja i popravi glavu, ili da
     * ceo lanac ponovo izracuna. To otkriva samo "sidro": hes zapisan van baze.
     * Ako se prosledi, mora da postoji u ispravnom delu lanca.
     *
     * Potpisi se proveravaju javnim kljucem izvedenim iz kljuca u .env, ili
     * prosledjenim javnim kljucem (sacuvan van servera, otkriva zamenu kljuca).
     *
     * Na kraju se stanje tabele artikli poredi sa logom (neslaganja): lanac
     * moze biti ispravan i kad je neko izmenio artikal mimo aplikacije.
     */
    public function proveri(?string $sidro = null, ?string $javniKljuc = null): RezultatProvere
    {
        // Lanac se cita u delovima, a glava posebnim upitom. Bez transakcije bi
        // upis koji stigne izmedju ta dva citanja pomerio glavu na zapis koji
        // provera nije videla, pa bi javila "glava se ne slaze" za ispravan lanac.
        // U transakciji (REPEATABLE READ) svi upiti vide isti snimak baze, a
        // obicno citanje nista ne zakljucava, pa upisi ne cekaju na proveru.
        return DB::transaction(fn () => $this->proveriSnimak($sidro, $javniKljuc));
    }

    private function proveriSnimak(?string $sidro, ?string $javniKljuc): RezultatProvere
    {
        $javniKljuc ??= $this->potpis->imaKljuc() ? $this->potpis->javniKljuc() : null;
        $nepotpisanih = 0;
        $biloPotpisa = false;

        $ocekivaniPrethodni = AuditZapis::POCETNI_HES;
        $broj = 0;
        $poslednjiId = null;
        $prethodniRed = null;
        $sidroNadjeno = false;

        // Sirovi redovi (bez Eloquent cast-ova): proverava se tacno ono sto je u bazi
        foreach (DB::table('audit_log')->lazyById(500) as $red) {
            $broj++;

            if ($red->prethodni_hes !== $ocekivaniPrethodni) {
                return new RezultatProvere(false, $broj,
                    'Veza sa prethodnim zapisom je prekinuta: zapis pre ovog je obrisan, ubačen ili premešten.',
                    $red->id, $poslednjiId, null,
                    $this->dijagnostika->prekinutaVeza($red, $prethodniRed));
            }

            if (self::hes((array) $red) !== $red->hes) {
                return new RezultatProvere(false, $broj,
                    'Sadržaj zapisa je izmenjen: heš se ne slaže sa podacima.',
                    $red->id, $poslednjiId, null,
                    $this->dijagnostika->izmenjenSadrzaj($red, $prethodniRed));
            }

            // Nepotpisani smeju biti samo zapisi sa pocetka lanca, nastali pre
            // uvodjenja potpisa. Posle prvog potpisanog svaki mora imati potpis.
            $potpisReda = $red->potpis ?? null;

            if ($potpisReda === null) {
                if ($biloPotpisa) {
                    return new RezultatProvere(false, $broj,
                        'Zapisu nedostaje potpis, a zapisi pre njega su potpisani: dopisan je mimo aplikacije ili mu je potpis uklonjen.',
                        $red->id, $poslednjiId);
                }

                $nepotpisanih++;
            } else {
                if ($javniKljuc === null) {
                    return new RezultatProvere(false, $broj,
                        'Zapis je potpisan, a nema ključa za proveru potpisa (AUDIT_KLJUC_POTPISA u .env).',
                        $red->id, $poslednjiId);
                }

                if (! $this->potpis->odgovara($red->hes, $potpisReda, $javniKljuc)) {
                    return new RezultatProvere(false, $broj,
                        'Potpis zapisa ne odgovara ključu: zapis je dopisan ili ponovo izračunat bez tajnog ključa, ili je ključ zamenjen.',
                        $red->id, $poslednjiId);
                }

                $biloPotpisa = true;
            }

            if ($sidro !== null && hash_equals($red->hes, $sidro)) {
                $sidroNadjeno = true;
            }

            $ocekivaniPrethodni = $red->hes;
            $poslednjiId = $red->id;
            $prethodniRed = $red;
        }

        // Glava mora da pokazuje bas na poslednji zapis. Ako ne pokazuje, neko je
        // mimo aplikacije obrisao zapise sa kraja (ili ih dodao, ili dirao glavu).
        $glava = DB::table('audit_log_glava')->where('id', 1)->first();
        $krajLanca = $broj > 0 ? $ocekivaniPrethodni : AuditZapis::POCETNI_HES;

        if ($glava === null
            || $glava->poslednji_hes !== $krajLanca
            || (int) $glava->poslednji_id !== (int) $poslednjiId) {
            return new RezultatProvere(false, $broj,
                'Glava lanca se ne slaže sa poslednjim zapisom: zapisi sa kraja su obrisani ili dodati mimo aplikacije.',
                null, $poslednjiId, null,
                $this->dijagnostika->glava($glava, $prethodniRed));
        }

        if ($sidro !== null && ! $sidroNadjeno) {
            return new RezultatProvere(false, $broj,
                'Sidro nije pronađeno u lancu: lanac je skraćen ili ponovo izračunat.',
                null, $poslednjiId);
        }

        return new RezultatProvere(true, $broj, null, null, $poslednjiId,
            $broj > 0 ? $ocekivaniPrethodni : null, [],
            $nepotpisanih, $this->neslaganjaStanja());
    }

    /**
     * Poredi tabelu artikli sa poslednjim zapisom loga za svaki artikal.
     * Radi u istom snimku baze kao provera lanca.
     *
     * Poslednji zapisi svih artikala se ucitavaju odjednom; za magacin sa
     * nekoliko hiljada artikala to je u redu.
     *
     * @return string[]
     */
    private function neslaganjaStanja(): array
    {
        $poslednji = DB::table('audit_log as a')
            ->joinSub(
                DB::table('audit_log')->selectRaw('MAX(id) as id')->groupBy('artikal_id'),
                'p', 'p.id', '=', 'a.id'
            )
            ->get(['a.id', 'a.artikal_id', 'a.sifra', 'a.akcija', 'a.posle'])
            ->keyBy('artikal_id');

        $neslaganja = [];

        foreach (Artikal::query()->lazyById(500) as $artikal) {
            $zapis = $poslednji->pull($artikal->id);

            array_push($neslaganja, ...$this->dijagnostika->stanjeArtikla($artikal, $zapis));
        }

        // Ostali su artikli kojih vise nema u tabeli
        foreach ($poslednji as $zapis) {
            if ($zapis->akcija !== AuditZapis::OBRISAN) {
                $neslaganja[] = "Artikal {$zapis->sifra} ne postoji u tabeli artikli, a u logu nije zabeleženo brisanje "
                    . "(poslednji zapis #{$zapis->id}): obrisan je mimo aplikacije.";
            }
        }

        return $neslaganja;
    }
}
