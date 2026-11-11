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

        $zapis = AuditZapis::create($polja);

        DB::table('audit_log_glava')->where('id', 1)->update([
            'poslednji_id'  => $zapis->id,
            'poslednji_hes' => $zapis->hes,
        ]);

        return $zapis;
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
     */
    public function proveri(?string $sidro = null): RezultatProvere
    {
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
            $broj > 0 ? $ocekivaniPrethodni : null);
    }
}
