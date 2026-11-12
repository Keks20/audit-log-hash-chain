<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vracanje artikla na stanje iz loga. DatabaseMigrations zbog testa u kome
 * je zapis izmenjen mimo aplikacije (DROP TRIGGER zatvara transakciju).
 */
class VracanjeStanjaTest extends TestCase
{
    use DatabaseMigrations;

    private User $sef;

    private Artikal $artikal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sef = User::factory()->sef()->create(['email' => 'sef@magacin.test']);
        $this->actingAs($this->sef);

        $this->artikal = Artikal::create([
            'sifra' => '001001', 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
            'napomena' => "Prvi red.\r\nDrugi red.",
        ]);
        app(AuditLog::class)->pocetniSnimak();
    }

    private function izmeni(array $izmene): void
    {
        $a = $this->artikal->fresh();

        $this->put("/artikli/{$a->id}", array_merge([
            'naziv' => $a->naziv, 'jedinica_mere' => $a->jedinica_mere, 'kolicina' => $a->kolicina, 'cena' => $a->cena,
            'datum_nabavke' => $a->datum_nabavke->format('Y-m-d'), 'aktivan' => $a->aktivan ? '1' : '0', 'napomena' => $a->napomena,
        ], $izmene))->assertSessionHasNoErrors();
    }

    private function sveURedu(): bool
    {
        return app(AuditLog::class)->proveri()->sveURedu();
    }

    public function test_vracanje_na_ranije_stanje_pravi_nov_zapis_izmene(): void
    {
        $pocetno = AuditZapis::sole();
        $this->izmeni(['naziv' => 'Šećer kristal', 'cena' => '150,50']);
        $this->izmeni(['kolicina' => '3']);

        $this->post("/audit-log/{$pocetno->id}/vrati")
            ->assertRedirect("/audit-log?artikal={$this->artikal->id}")
            ->assertSessionHas('poruka', "Artikal 001001 je vraćen na stanje iz zapisa #{$pocetno->id}.");

        // Artikal je bajt u bajt kao u pocetnom zapisu
        $this->assertSame($pocetno->posle, $this->artikal->fresh()->kanonskiJson());

        // Stari zapisi su ostali, vracanje je cetvrti
        $this->assertSame(4, AuditZapis::count());
        $z = AuditZapis::orderByDesc('id')->first();
        $this->assertSame(AuditZapis::IZMENJEN, $z->akcija);
        $this->assertSame('sef@magacin.test', $z->korisnik_email);
        $this->assertSame($pocetno->posle, $z->posle);
        $this->assertSame(['cena', 'kolicina', 'naziv'], array_keys($z->izmene()));

        $this->assertTrue($this->sveURedu());
    }

    public function test_vracanje_na_trenutno_stanje_ne_upisuje_nista(): void
    {
        $pocetno = AuditZapis::sole();

        $this->post("/audit-log/{$pocetno->id}/vrati")
            ->assertSessionHas('poruka', "Artikal 001001 je već u stanju iz zapisa #{$pocetno->id}: nema izmena.");

        $this->assertSame(1, AuditZapis::count());
    }

    public function test_obrisan_artikal_se_vraca_kao_nov_sa_istom_sifrom(): void
    {
        $this->izmeni(['naziv' => 'Šećer kristal']);
        $this->delete("/artikli/{$this->artikal->id}")->assertRedirect();
        $brisanje = AuditZapis::where('akcija', AuditZapis::OBRISAN)->sole();

        $this->post("/audit-log/{$brisanje->id}/vrati")->assertSessionHas('poruka');

        $novi = Artikal::where('sifra', '001001')->sole();
        $this->assertNotSame($this->artikal->id, $novi->id);
        $this->assertSame($brisanje->pre, $novi->kanonskiJson());
        $this->assertSame("Prvi red.\r\nDrugi red.", $novi->napomena);

        $z = AuditZapis::orderByDesc('id')->first();
        $this->assertSame(AuditZapis::DODAT, $z->akcija);
        $this->assertSame($novi->id, $z->artikal_id);
        $this->assertNull($z->pre);

        $this->assertSame(4, AuditZapis::count());
        $this->assertTrue($this->sveURedu());
    }

    public function test_obrisan_artikal_se_ne_vraca_ako_je_sifra_zauzeta(): void
    {
        $this->delete("/artikli/{$this->artikal->id}");
        $brisanje = AuditZapis::where('akcija', AuditZapis::OBRISAN)->sole();

        $this->post('/artikli', [
            'sifra' => '001001', 'naziv' => 'Drugi artikal', 'jedinica_mere' => 'kom', 'kolicina' => '1', 'cena' => '1',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertSessionHasNoErrors();

        $this->post("/audit-log/{$brisanje->id}/vrati")
            ->assertSessionHas('greska', 'Šifru 001001 sada ima drugi artikal, pa obrisani artikal ne može da se vrati.');

        $this->assertSame('Drugi artikal', Artikal::where('sifra', '001001')->sole()->naziv);
        $this->assertSame(3, AuditZapis::count());
    }

    public function test_stanje_iz_zapisa_koji_ne_prolazi_proveru_se_ne_vraca(): void
    {
        $pocetno = AuditZapis::sole();
        $this->izmeni(['naziv' => 'Šećer kristal']);

        // Napadac podmece stanje u stari zapis, u nadi da ce ga sef "vratiti"
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_bez_izmene');
        DB::table('audit_log')->where('id', $pocetno->id)
            ->update(['posle' => str_replace('"cena":"100.00"', '"cena":"1.00"', $pocetno->posle)]);

        $this->post("/audit-log/{$pocetno->id}/vrati")
            ->assertSessionHas('greska', "Zapis #{$pocetno->id} ne prolazi proveru (heš ili potpis), stanje iz njega se ne vraća.");

        $this->assertSame('100.00', $this->artikal->fresh()->cena);
        $this->assertSame('Šećer kristal', $this->artikal->fresh()->naziv);
        $this->assertSame(2, AuditZapis::count());
    }

    public function test_zapis_sa_podmetnutim_potpisom_se_ne_vraca(): void
    {
        $pocetno = AuditZapis::sole();
        $this->izmeni(['naziv' => 'Šećer kristal']);

        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_bez_izmene');
        DB::table('audit_log')->where('id', $pocetno->id)->update(['potpis' => str_repeat('ab', 64)]);

        $this->post("/audit-log/{$pocetno->id}/vrati")->assertSessionHas('greska');

        $this->assertSame('Šećer kristal', $this->artikal->fresh()->naziv);
    }

    public function test_nepostojeci_zapis_vraca_404(): void
    {
        $this->post('/audit-log/99999/vrati')->assertNotFound();
    }

    public function test_poruka_o_gresci_se_prikazuje_na_stranici(): void
    {
        $this->delete("/artikli/{$this->artikal->id}");
        $brisanje = AuditZapis::where('akcija', AuditZapis::OBRISAN)->sole();
        Artikal::create([
            'sifra' => '001001', 'naziv' => 'Drugi', 'jedinica_mere' => 'kom',
            'kolicina' => '1', 'cena' => '1', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);

        $this->followingRedirects()
            ->post("/audit-log/{$brisanje->id}/vrati")
            ->assertSee('alert-danger', false)
            ->assertSee('Šifru 001001 sada ima drugi artikal');
    }
}
