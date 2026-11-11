<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

class ArtikalBrisanjeTest extends TestCase
{
    use RefreshDatabase;

    private function artikal(string $sifra = '001001', string $naziv = 'Šećer kristal'): Artikal
    {
        return Artikal::create([
            'sifra'         => $sifra,
            'naziv'         => $naziv,
            'jedinica_mere' => 'kg',
            'kolicina'      => '10.000',
            'cena'          => '100.00',
            'datum_nabavke' => '1996-11-23',
            'aktivan'       => true,
        ]);
    }

    public function test_gost_ne_moze_da_obrise_artikal(): void
    {
        $a = $this->artikal();

        $this->delete("/artikli/{$a->id}")->assertRedirect('/prijava');

        $this->assertModelExists($a);
    }

    public function test_prijavljen_korisnik_brise_artikal(): void
    {
        $a = $this->artikal();
        $drugi = $this->artikal('001002', 'Brašno T-400');

        $this->actingAs(User::factory()->create())
            ->delete("/artikli/{$a->id}")
            ->assertRedirect('/artikli')
            ->assertSessionHas('poruka', 'Obrisan artikal 001001 (Šećer kristal).');

        // Pravo brisanje: reda vise nema u tabeli
        $this->assertDatabaseMissing('artikli', ['sifra' => '001001']);
        $this->assertModelExists($drugi);
    }

    public function test_brisanje_nepostojeceg_artikla_vraca_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->delete('/artikli/999')
            ->assertNotFound();
    }

    public function test_obrisana_sifra_moze_ponovo_da_se_koristi(): void
    {
        $a = $this->artikal();
        $this->actingAs(User::factory()->create());

        $this->delete("/artikli/{$a->id}");

        $this->post('/artikli', [
            'sifra'         => '001001',
            'naziv'         => 'Šećer kristal',
            'jedinica_mere' => 'kg',
            'kolicina'      => '5',
            'cena'          => '120',
            'datum_nabavke' => '2026-11-01',
            'aktivan'       => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Artikal::where('sifra', '001001')->count());
    }

    public function test_dugme_za_brisanje_samo_za_prijavljene_sa_potvrdom(): void
    {
        $this->artikal('001003', "Ulje \"Dijamant\" 1'l");

        $this->get('/artikli')->assertDontSee('Obriši');

        $html = $this->actingAs(User::factory()->create())
            ->get('/artikli')
            ->assertSee('Obriši')
            ->getContent();

        // Navodnici iz naziva ne smeju da prekinu onsubmit="..." atribut:
        // ceo poziv confirm(...) mora ostati unutar atributa, sa kodiranim navodnicima
        $this->assertSame(1, preg_match('/onsubmit="return confirm\(([^"]*)\)"/', $html, $m));

        $ocekivano = (string) Js::from("Obrisati artikal 001003 (Ulje \"Dijamant\" 1'l)?");
        $this->assertSame($ocekivano, $m[1]);

        // Jedini apostrofi su oni koji otvaraju i zatvaraju JavaScript string
        $this->assertSame(2, substr_count($m[1], "'"));
    }
}
