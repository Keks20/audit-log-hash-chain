<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use Database\Seeders\ArtikliSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pocetni snimak i poredjenje tabele artikli sa logom.
 * Ovde se kvari tabela artikli (mimo aplikacije), a log ostaje netaknut,
 * pa nema DDL-a i dovoljan je RefreshDatabase.
 */
class AuditLogStanjeTest extends TestCase
{
    use RefreshDatabase;

    private AuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = app(AuditLog::class);
    }

    private function artikal(string $sifra, string $naziv = 'Šećer'): Artikal
    {
        return Artikal::create([
            'sifra' => $sifra, 'naziv' => $naziv, 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
    }

    // --- pocetni snimak ---

    public function test_snimak_upisuje_pocetno_stanje_bez_korisnika(): void
    {
        $a = $this->artikal('001001');
        $this->artikal('001002', 'Brašno');

        $this->assertSame(2, $this->log->pocetniSnimak());

        $zapis = AuditZapis::where('artikal_id', $a->id)->sole();
        $this->assertSame(AuditZapis::POCETNO, $zapis->akcija);
        $this->assertNull($zapis->pre);
        $this->assertSame($a->kanonskiJson(), $zapis->posle);
        $this->assertNull($zapis->korisnik_id);
        $this->assertNull($zapis->korisnik_email);
        $this->assertNotNull($zapis->potpis);

        $r = $this->log->proveri();
        $this->assertTrue($r->sveURedu());
        $this->assertSame(2, $r->brojZapisa);
    }

    public function test_snimak_sme_da_se_ponovi_i_preskace_artikle_koji_imaju_zapis(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->artikal('001001');
        $this->artikal('001002');

        // Artikal izmenjen iz aplikacije vec ima zapis ("pre" je njegovo pocetno stanje)
        $this->put("/artikli/{$a->id}", [
            'naziv' => 'Šećer kristal', 'jedinica_mere' => 'kg', 'kolicina' => '10', 'cena' => '100',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->log->pocetniSnimak());
        $this->assertSame(0, $this->log->pocetniSnimak());

        $this->assertSame(1, AuditZapis::where('akcija', AuditZapis::POCETNO)->count());
        $this->assertSame(2, AuditZapis::count());
        $this->assertTrue($this->log->proveri()->sveURedu());
    }

    public function test_komanda_snimak(): void
    {
        $this->artikal('001001');

        $this->artisan('audit:snimak')
            ->expectsOutputToContain('Početni snimak upisan za artikala: 1.')
            ->assertSuccessful();

        $this->artisan('audit:snimak')
            ->expectsOutputToContain('Svi artikli već imaju zapis u logu.')
            ->assertSuccessful();
    }

    public function test_seeder_upisuje_pocetni_snimak_za_sve_artikle(): void
    {
        $this->seed(ArtikliSeeder::class);

        $this->assertSame(37, Artikal::count());
        $this->assertSame(37, AuditZapis::where('akcija', AuditZapis::POCETNO)->count());

        $r = $this->log->proveri();
        $this->assertTrue($r->sveURedu());
        $this->assertSame(37, $r->brojZapisa);
        $this->assertSame(0, $r->nepotpisanih);

        // Drugo pokretanje ne dira ni artikle ni log
        $this->seed(ArtikliSeeder::class);
        $this->assertSame(37, AuditZapis::count());
    }

    public function test_seeder_bez_kljuca_unosi_artikle_ali_ne_pada(): void
    {
        config(['magacin.kljuc_potpisa' => null]);

        $this->seed(ArtikliSeeder::class);

        $this->assertSame(37, Artikal::count());
        $this->assertSame(0, AuditZapis::count());
    }

    public function test_stranica_prikazuje_pocetni_snimak(): void
    {
        $this->artikal('001001', 'Šećer kristal');
        $this->log->pocetniSnimak();

        $this->actingAs(User::factory()->create())
            ->get('/audit-log')
            ->assertOk()
            ->assertSee('pocetno')
            ->assertSee('Šećer kristal');
    }

    // --- stanje artikala prema logu ---

    public function test_artikal_bez_zapisa_se_prijavljuje(): void
    {
        $this->artikal('001001');

        $r = $this->log->proveri();

        $this->assertTrue($r->ispravan);
        $this->assertFalse($r->sveURedu());
        $this->assertCount(1, $r->neslaganja);
        $this->assertStringContainsString('Artikal 001001 nema nijedan zapis u logu', $r->neslaganja[0]);
    }

    public function test_izmena_artikla_mimo_aplikacije_pokazuje_polje_i_vrednosti(): void
    {
        $a = $this->artikal('001001');
        $this->artikal('001002');
        $this->log->pocetniSnimak();
        $zapisId = AuditZapis::where('artikal_id', $a->id)->value('id');

        // Direktno u bazi, kao iz phpMyAdmin-a
        DB::table('artikli')->where('id', $a->id)->update(['kolicina' => '999.000', 'cena' => '1.00']);

        $r = $this->log->proveri();

        $this->assertTrue($r->ispravan);
        $this->assertFalse($r->sveURedu());
        $this->assertSame([
            "Artikal 001001: polje „Količina“ u tabeli je „999.000“, a poslednji zapis #{$zapisId} u logu kaže „10.000“ (izmena mimo aplikacije).",
            "Artikal 001001: polje „Cena“ u tabeli je „1.00“, a poslednji zapis #{$zapisId} u logu kaže „100.00“ (izmena mimo aplikacije).",
        ], $r->neslaganja);
    }

    public function test_brisanje_artikla_mimo_aplikacije_se_prijavljuje(): void
    {
        $a = $this->artikal('001001');
        $this->log->pocetniSnimak();

        DB::table('artikli')->where('id', $a->id)->delete();

        $r = $this->log->proveri();

        $this->assertFalse($r->sveURedu());
        $this->assertCount(1, $r->neslaganja);
        $this->assertStringContainsString('Artikal 001001 ne postoji u tabeli artikli, a u logu nije zabeleženo brisanje', $r->neslaganja[0]);
    }

    public function test_vracen_obrisan_artikal_mimo_aplikacije_se_prijavljuje(): void
    {
        $this->actingAs(User::factory()->sef()->create());
        $a = $this->artikal('001001');
        $this->log->pocetniSnimak();
        $red = (array) DB::table('artikli')->where('id', $a->id)->first();

        $this->delete("/artikli/{$a->id}")->assertRedirect();
        $this->assertTrue($this->log->proveri()->sveURedu());

        // Isti red vracen direktno u bazu, sa istim id-jem
        DB::table('artikli')->insert($red);

        $r = $this->log->proveri();
        $this->assertFalse($r->sveURedu());
        $this->assertStringContainsString('u logu kaže da je obrisan', $r->neslaganja[0]);
    }

    public function test_izmene_iz_aplikacije_ostaju_uskladjene(): void
    {
        $this->actingAs(User::factory()->sef()->create());
        $this->artikal('001001');
        $this->log->pocetniSnimak();

        $this->post('/artikli', [
            'sifra' => '001050', 'naziv' => 'Ulje', 'jedinica_mere' => 'l', 'kolicina' => '5,5', 'cena' => '210',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertSessionHasNoErrors();

        $novi = Artikal::where('sifra', '001050')->sole();
        $this->put("/artikli/{$novi->id}", [
            'naziv' => 'Ulje suncokretovo', 'jedinica_mere' => 'l', 'kolicina' => '5,5', 'cena' => '215,50',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertSessionHasNoErrors();

        $this->delete('/artikli/' . Artikal::where('sifra', '001001')->value('id'))->assertRedirect();

        $r = $this->log->proveri();
        $this->assertTrue($r->sveURedu());
        $this->assertSame(4, $r->brojZapisa);
    }

    public function test_komanda_pada_kad_se_artikli_ne_slazu_sa_logom(): void
    {
        $a = $this->artikal('001001');
        $this->log->pocetniSnimak();
        DB::table('artikli')->where('id', $a->id)->update(['naziv' => 'Podmetnut']);

        $this->artisan('audit:provera')
            ->expectsOutputToContain('Lanac je ispravan. Zapisa: 1.')
            ->expectsOutputToContain('Artikli se NE slažu sa logom (1):')
            ->expectsOutputToContain('polje „Naziv“ u tabeli je „Podmetnut“')
            ->assertFailed();
    }

    public function test_stranica_prikazuje_neslaganja(): void
    {
        $a = $this->artikal('001001');
        $this->log->pocetniSnimak();
        DB::table('artikli')->where('id', $a->id)->update(['naziv' => 'Podmetnut']);

        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->get('/audit-log/provera')
            ->assertSee('Lanac je ispravan.')
            ->assertSee('Artikli se ne slažu sa logom')
            ->assertSee('polje „Naziv“ u tabeli je „Podmetnut“', false)
            ->assertDontSee('Stanje svih artikala se slaže sa logom.');
    }

    public function test_json_izlaz_komande(): void
    {
        $this->artikal('001001');
        $this->log->pocetniSnimak();

        $this->artisan('audit:provera', ['--json' => true])
            ->expectsOutputToContain('"sveURedu": true')
            ->assertSuccessful();
    }
}
