<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use Database\Seeders\KorisniciSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UlogeTest extends TestCase
{
    use RefreshDatabase;

    private function artikal(): Artikal
    {
        return Artikal::create([
            'sifra' => '001001', 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
    }

    public function test_seeder_daje_uloge_test_nalozima(): void
    {
        config(['magacin.demo_lozinka' => 'lozinka-za-test']);
        $this->seed(KorisniciSeeder::class);

        $this->assertFalse(User::where('email', 'magacioner@magacin.test')->sole()->jeSef());
        $this->assertTrue(User::where('email', 'sef@magacin.test')->sole()->jeSef());
    }

    public function test_nov_korisnik_je_magacioner(): void
    {
        // Podrazumevana vrednost kolone, ne fabrike
        $id = DB::table('users')->insertGetId(['name' => 'Novi', 'email' => 'novi@magacin.test', 'password' => 'x']);

        $this->assertSame(User::ULOGA_MAGACIONER, User::findOrFail($id)->uloga);
    }

    public function test_uloga_ne_moze_da_se_dodeli_masovnom_dodelom(): void
    {
        $korisnik = new User(['name' => 'Lukavi', 'email' => 'lukavi@magacin.test', 'password' => 'x', 'uloga' => User::ULOGA_SEF]);
        $korisnik->save();

        $this->assertFalse($korisnik->fresh()->jeSef());
    }

    public function test_magacioner_ne_moze_da_obrise_artikal(): void
    {
        $a = $this->artikal();

        $this->actingAs(User::factory()->create())
            ->delete("/artikli/{$a->id}")
            ->assertForbidden()
            ->assertSee('Nemaš pravo na ovu radnju.');

        $this->assertModelExists($a);
        $this->assertSame(0, AuditZapis::count());
    }

    public function test_magacioner_dodaje_i_menja_ali_ne_vidi_dugme_za_brisanje(): void
    {
        $a = $this->artikal();
        $this->actingAs(User::factory()->create());

        $this->put("/artikli/{$a->id}", [
            'naziv' => 'Šećer kristal', 'jedinica_mere' => 'kg', 'kolicina' => '10', 'cena' => '100',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertRedirect('/artikli');

        $this->get('/artikli')
            ->assertSee('Izmeni')
            ->assertSee('Istorija')
            ->assertDontSee('Obriši')
            ->assertSee('magacioner');

        $this->get("/audit-log?artikal={$a->id}")->assertOk()->assertDontSee('Vrati');
    }

    public function test_sef_vidi_dugmad_za_brisanje_i_vracanje(): void
    {
        $a = $this->artikal();
        $this->actingAs(User::factory()->sef()->create());

        $this->put("/artikli/{$a->id}", [
            'naziv' => 'Šećer kristal', 'jedinica_mere' => 'kg', 'kolicina' => '10', 'cena' => '100',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ]);

        $this->get('/artikli')->assertSee('Obriši')->assertSee('šef');
        $this->get("/audit-log?artikal={$a->id}")->assertSee('Vrati');
    }

    public function test_magacioner_ne_moze_da_vrati_stanje(): void
    {
        $a = $this->artikal();
        app(\App\Services\AuditLog::class)->pocetniSnimak();
        $zapis = AuditZapis::sole();

        $this->actingAs(User::factory()->create())
            ->post("/audit-log/{$zapis->id}/vrati")
            ->assertForbidden();

        $this->post('/odjava');
        $this->post("/audit-log/{$zapis->id}/vrati")->assertRedirect('/prijava');

        $this->assertSame(1, AuditZapis::count());
        $this->assertSame('Šećer', $a->fresh()->naziv);
    }
}
