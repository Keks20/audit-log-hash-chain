<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\PotpisZapisa;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Digitalni potpis zapisa. DatabaseMigrations iz istog razloga kao u
 * AuditLogProveraTest: "napadac" brise trigere (DDL zatvara transakciju).
 */
class AuditLogPotpisTest extends TestCase
{
    use DatabaseMigrations;

    private AuditLog $log;

    private PotpisZapisa $potpis;

    private User $korisnik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = app(AuditLog::class);
        $this->potpis = new PotpisZapisa();
        $this->korisnik = User::factory()->create(['email' => 'magacioner@magacin.test']);
    }

    private function artikal(string $sifra = '001001'): Artikal
    {
        return Artikal::create([
            'sifra' => $sifra, 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
    }

    private function dodaj(string $sifra): AuditZapis
    {
        return DB::transaction(function () use ($sifra) {
            $a = $this->artikal($sifra);

            return $this->log->zapisi(AuditZapis::DODAT, $a, null, $a->kanonskiPodaci(), $this->korisnik);
        });
    }

    private function ukloniTrigere(): void
    {
        foreach (['audit_log_bez_izmene', 'audit_log_bez_brisanja', 'audit_log_glava_bez_brisanja', 'audit_log_glava_samo_napred'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
        }
    }

    // Uredan zapis kakav bi dopisao neko ko ima bazu i zna algoritam, ali nema kljuc
    private function dopisiBezAplikacije(?string $potpis): int
    {
        $prethodni = DB::table('audit_log_glava')->value('poslednji_hes');
        $a = $this->artikal('001077');

        $polja = [
            'artikal_id' => $a->id, 'sifra' => $a->sifra, 'akcija' => AuditZapis::DODAT,
            'korisnik_id' => $this->korisnik->id, 'korisnik_email' => $this->korisnik->email,
            'pre' => null, 'posle' => $a->kanonskiJson(),
            'vreme' => now('UTC')->format('Y-m-d H:i:s'), 'prethodni_hes' => $prethodni,
        ];
        $polja['hes'] = AuditLog::hes($polja);
        $polja['potpis'] = $potpis;

        $id = DB::table('audit_log')->insertGetId($polja);

        // Trigger glave ovo pusta: zapis je uredan nastavak lanca
        DB::table('audit_log_glava')->where('id', 1)->update(['poslednji_id' => $id, 'poslednji_hes' => $polja['hes']]);

        return $id;
    }

    public function test_svaki_zapis_dobija_potpis_koji_odgovara_javnom_kljucu(): void
    {
        $zapis = $this->dodaj('001001');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $zapis->potpis);
        $this->assertTrue($this->potpis->odgovara($zapis->hes, $zapis->potpis, $this->potpis->javniKljuc()));

        $r = $this->log->proveri();
        $this->assertTrue($r->ispravan);
        $this->assertSame(0, $r->nepotpisanih);
    }

    public function test_potpis_ne_ulazi_u_hes(): void
    {
        $zapis = $this->dodaj('001001');
        $red = (array) DB::table('audit_log')->where('id', $zapis->id)->first();

        $this->assertSame($zapis->hes, AuditLog::hes($red));
        unset($red['potpis']);
        $this->assertSame($zapis->hes, AuditLog::hes($red));
    }

    public function test_potpis_je_deterministican_i_vezan_za_hes(): void
    {
        $hes = str_repeat('ab', 32);

        $this->assertSame($this->potpis->potpisi($hes), $this->potpis->potpisi($hes));
        $this->assertNotSame($this->potpis->potpisi($hes), $this->potpis->potpisi(str_repeat('ac', 32)));
        $this->assertFalse($this->potpis->odgovara(str_repeat('ac', 32), $this->potpis->potpisi($hes), $this->potpis->javniKljuc()));
    }

    public function test_neispravan_oblik_potpisa_ili_kljuca_samo_ne_odgovara(): void
    {
        $hes = str_repeat('ab', 32);
        $potpis = $this->potpis->potpisi($hes);
        $kljuc = $this->potpis->javniKljuc();

        $this->assertFalse($this->potpis->odgovara($hes, 'nije-hex', $kljuc));
        $this->assertFalse($this->potpis->odgovara($hes, substr($potpis, 2), $kljuc));
        $this->assertFalse($this->potpis->odgovara($hes, $potpis, 'zz'));
        $this->assertFalse($this->potpis->odgovara($hes, $potpis, ''));
    }

    public function test_bez_kljuca_nema_upisa_ni_izmene_artikla(): void
    {
        config(['magacin.kljuc_potpisa' => null]);
        $a = $this->artikal();

        try {
            DB::transaction(function () use ($a) {
                $pre = $a->kanonskiPodaci();
                $a->update(['naziv' => 'Bez potpisa']);
                $this->log->zapisi(AuditZapis::IZMENJEN, $a, $pre, $a->kanonskiPodaci(), $this->korisnik);
            });
            $this->fail('Upis bez ključa je prošao.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('AUDIT_KLJUC_POTPISA', $e->getMessage());
        }

        // Transakcija je ponistena: ni artikal nije izmenjen
        $this->assertSame('Šećer', $a->fresh()->naziv);
        $this->assertSame(0, AuditZapis::count());
    }

    public function test_neispravan_kljuc_se_odbija(): void
    {
        config(['magacin.kljuc_potpisa' => base64_encode('prekratko')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nije ispravan Ed25519 ključ');

        $this->potpis->potpisi(str_repeat('ab', 32));
    }

    public function test_dopisan_zapis_bez_potpisa_se_otkriva(): void
    {
        $this->dodaj('001001');
        $id = $this->dopisiBezAplikacije(null);

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($id, $r->losZapisId);
        $this->assertStringContainsString('Zapisu nedostaje potpis', $r->poruka);
    }

    public function test_dopisan_zapis_sa_tudjim_kljucem_se_otkriva(): void
    {
        $this->dodaj('001001');

        // Napadac pravi svoj kljuc i potpisuje njime
        $prethodni = DB::table('audit_log_glava')->value('poslednji_hes');
        config(['magacin.kljuc_potpisa' => PotpisZapisa::noviKljuc()]);
        $tudji = new PotpisZapisa();
        $a = $this->artikal('001078');
        $polja = [
            'artikal_id' => $a->id, 'sifra' => $a->sifra, 'akcija' => AuditZapis::DODAT,
            'korisnik_id' => null, 'korisnik_email' => null, 'pre' => null, 'posle' => $a->kanonskiJson(),
            'vreme' => now('UTC')->format('Y-m-d H:i:s'), 'prethodni_hes' => $prethodni,
        ];
        $polja['hes'] = AuditLog::hes($polja);
        $polja['potpis'] = $tudji->potpisi($polja['hes']);
        $id = DB::table('audit_log')->insertGetId($polja);
        DB::table('audit_log_glava')->where('id', 1)->update(['poslednji_id' => $id, 'poslednji_hes' => $polja['hes']]);

        // Provera sa pravim kljucem
        config(['magacin.kljuc_potpisa' => env('AUDIT_KLJUC_POTPISA')]);

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($id, $r->losZapisId);
        $this->assertStringContainsString('Potpis zapisa ne odgovara ključu', $r->poruka);
    }

    public function test_zamenjen_kljuc_otkriva_javni_kljuc_sacuvan_van_servera(): void
    {
        $this->dodaj('001001');
        $praviJavni = $this->potpis->javniKljuc();

        $this->assertTrue($this->log->proveri(null, $praviJavni)->ispravan);

        // Napadac sa pristupom serveru zameni kljuc u .env i ponovo potpise zapise
        $this->ukloniTrigere();
        config(['magacin.kljuc_potpisa' => PotpisZapisa::noviKljuc()]);
        foreach (DB::table('audit_log')->get() as $red) {
            DB::table('audit_log')->where('id', $red->id)->update(['potpis' => $this->potpis->potpisi($red->hes)]);
        }

        // Kljuc iz .env se slaze sa potpisima, pa provera bez spoljnog kljuca prolazi
        $this->assertTrue($this->log->proveri()->ispravan);

        $r = $this->log->proveri(null, $praviJavni);
        $this->assertFalse($r->ispravan);
        $this->assertStringContainsString('Potpis zapisa ne odgovara ključu', $r->poruka);
    }

    public function test_uklonjen_potpis_iz_sredine_se_otkriva(): void
    {
        $this->dodaj('001001');
        $drugi = $this->dodaj('001002');
        $this->dodaj('001003');
        $this->ukloniTrigere();

        DB::table('audit_log')->where('id', $drugi->id)->update(['potpis' => null]);

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($drugi->id, $r->losZapisId);
        $this->assertStringContainsString('Zapisu nedostaje potpis', $r->poruka);
    }

    public function test_stari_zapisi_bez_potpisa_su_dozvoljeni_samo_na_pocetku(): void
    {
        // Stanje baze koja je imala zapise pre uvodjenja potpisa
        $this->dodaj('001001');
        $this->dodaj('001002');
        $this->ukloniTrigere();
        DB::table('audit_log')->update(['potpis' => null]);

        $this->dodaj('001003');

        $r = $this->log->proveri();
        $this->assertTrue($r->ispravan);
        $this->assertSame(3, $r->brojZapisa);
        $this->assertSame(2, $r->nepotpisanih);

        $this->artisan('audit:provera')
            ->expectsOutputToContain('Nepotpisanih zapisa sa početka lanca (pre uvođenja potpisa): 2.')
            ->assertSuccessful();
    }

    public function test_potpisan_log_bez_kljuca_ne_prolazi_proveru(): void
    {
        $zapis = $this->dodaj('001001');
        config(['magacin.kljuc_potpisa' => null]);

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($zapis->id, $r->losZapisId);
        $this->assertStringContainsString('nema ključa za proveru potpisa', $r->poruka);
    }

    public function test_komanda_prima_javni_kljuc(): void
    {
        $this->dodaj('001001');

        $this->artisan('audit:provera', ['--javni-kljuc' => strtoupper($this->potpis->javniKljuc())])
            ->expectsOutputToContain('Lanac je ispravan. Zapisa: 1.')
            ->assertSuccessful();

        $tudji = bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        $this->artisan('audit:provera', ['--javni-kljuc' => $tudji])
            ->expectsOutputToContain('Potpis zapisa ne odgovara ključu')
            ->assertFailed();
    }

    public function test_stranica_prikazuje_broj_potpisanih(): void
    {
        $this->dodaj('001001');

        $this->actingAs($this->korisnik)
            ->followingRedirects()
            ->get('/audit-log/provera')
            ->assertSee('Lanac je ispravan.')
            ->assertSee('Potpisano: 1')
            ->assertSee('Stanje svih artikala se slaže sa logom.');
    }
}
