<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogStranicaTest extends TestCase
{
    use RefreshDatabase;

    private function artikal(string $sifra, string $naziv): Artikal
    {
        return Artikal::create([
            'sifra' => $sifra, 'naziv' => $naziv, 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
    }

    private function izmeni(Artikal $a, string $naziv): void
    {
        $this->put("/artikli/{$a->id}", [
            'naziv' => $naziv, 'jedinica_mere' => 'kg', 'kolicina' => '10', 'cena' => '100',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertSessionHasNoErrors();
    }

    public function test_gost_ne_vidi_audit_log(): void
    {
        $this->get('/audit-log')->assertRedirect('/prijava');
        $this->get('/audit-log/provera')->assertRedirect('/prijava');
        // Naslov stranice sadrzi "Audit log", zato se proverava link, ne tekst
        $this->artikal('001001', 'Šećer');
        $this->get('/artikli')
            ->assertDontSee(route('audit-log.index'), false)
            ->assertDontSee('Istorija');
    }

    public function test_prijavljen_vidi_izmenu_sa_vremenom_po_beogradu(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'sef@magacin.test']));
        $a = $this->artikal('001001', 'Šećer');

        // 10:00 UTC leti je 12:00 u Beogradu
        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
        $this->izmeni($a, 'Šećer kristal');
        $this->travelBack();

        $this->assertSame('2026-07-01 10:00:00', DB::table('audit_log')->value('vreme'));

        $this->get('/audit-log')
            ->assertOk()
            ->assertSee('01.07.2026. 12:00:00')
            ->assertSee('sef@magacin.test')
            ->assertSee('izmenjen')
            ->assertSee('Šećer')
            ->assertSee('Šećer kristal');
    }

    public function test_istorija_jednog_artikla(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->artikal('001001', 'Šećer');
        $b = $this->artikal('001002', 'Brašno');

        $this->izmeni($a, 'Šećer kristal');
        $this->izmeni($b, 'Brašno T-500');

        // Poruka "Sacuvan artikal ... (Brasno T-500)" ostaje za sledeci zahtev; ovde se potrosi
        $this->get('/artikli');

        $this->get("/audit-log?artikal={$a->id}")
            ->assertOk()
            ->assertSee('istorija artikla')
            ->assertSee('Šećer kristal')
            ->assertDontSee('Brašno T-500');

        $this->get('/artikli')
            ->assertSee('href="' . route('audit-log.index') . '"', false)
            ->assertSee(route('audit-log.index', ['artikal' => $a->id]), false)
            ->assertSee('Istorija');
    }

    public function test_dugme_proveri_lanac(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->artikal('001001', 'Šećer');
        $this->izmeni($a, 'Šećer kristal');
        $this->izmeni($a, 'Šećer u kocki');

        $this->followingRedirects()
            ->get('/audit-log/provera')
            ->assertSee('Lanac je ispravan.')
            ->assertSee('Zapisa: 2.')
            ->assertSee('Sidro (sačuvaj van baze)')
            ->assertDontSee('table-danger');

        // Pokvaren lanac na stranici: AuditLogProveraTest (tamo "napadac" uklanja trigere)
    }

    public function test_prazan_log(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/audit-log')->assertOk()->assertSee('Nema zapisa.');
    }
}
