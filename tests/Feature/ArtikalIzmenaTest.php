<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtikalIzmenaTest extends TestCase
{
    use RefreshDatabase;

    private function artikal(array $izmene = []): Artikal
    {
        return Artikal::create(array_merge([
            'sifra'         => '001001',
            'naziv'         => 'Šećer kristal',
            'jedinica_mere' => 'kg',
            'kolicina'      => '532.572',
            'cena'          => '331.68',
            'datum_nabavke' => '1996-11-23',
            'aktivan'       => true,
            'napomena'      => "Dobavljač kasni sa isporukom.\r\nPozvati u ponedeljak.",
        ], $izmene));
    }

    // Podaci forme tacno onakvi kakve bi pregledac poslao za dati artikal
    private function forma(Artikal $a, array $izmene = []): array
    {
        return array_merge([
            'naziv'         => $a->naziv,
            'jedinica_mere' => $a->jedinica_mere,
            'kolicina'      => $a->kolicina,
            'cena'          => $a->cena,
            'datum_nabavke' => $a->datum_nabavke?->format('Y-m-d'),
            'aktivan'       => $a->aktivan === null ? '' : ($a->aktivan ? '1' : '0'),
            'napomena'      => $a->napomena,
        ], $izmene);
    }

    private function prijavljen(): static
    {
        return $this->actingAs(User::factory()->create());
    }

    // --- gost ---

    public function test_gost_ne_moze_na_izmenu_ni_dodavanje(): void
    {
        $a = $this->artikal();

        $this->get("/artikli/{$a->id}/izmena")->assertRedirect('/prijava');
        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => 'Hakovano']))->assertRedirect('/prijava');
        $this->get('/artikli/novi')->assertRedirect('/prijava');
        $this->post('/artikli', ['sifra' => '009999', 'naziv' => 'X', 'jedinica_mere' => 'kg'])->assertRedirect('/prijava');

        $this->assertSame('Šećer kristal', $a->fresh()->naziv);
        $this->assertSame(1, Artikal::count());
    }

    public function test_gost_ne_vidi_dugmad_za_izmenu(): void
    {
        $this->artikal();

        $this->get('/artikli')
            ->assertOk()
            ->assertDontSee('Izmeni')
            ->assertDontSee('Dodaj artikal');
    }

    public function test_posle_prijave_vraca_na_stranicu_koju_je_gost_hteo(): void
    {
        $a = $this->artikal();
        $korisnik = User::factory()->create(['password' => 'tajna']);

        $this->get("/artikli/{$a->id}/izmena")->assertRedirect('/prijava');

        $this->post('/prijava', ['email' => $korisnik->email, 'password' => 'tajna'])
            ->assertRedirect("/artikli/{$a->id}/izmena");
    }

    // --- izmena ---

    public function test_forma_za_izmenu_prikazuje_postojece_vrednosti(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->get("/artikli/{$a->id}/izmena")
            ->assertOk()
            ->assertSee('Izmena artikla')
            ->assertSee('value="Šećer kristal"', false)
            ->assertSee('value="532.572"', false)
            ->assertSee('value="1996-11-23"', false);
    }

    public function test_izmena_cuva_nove_vrednosti(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->put("/artikli/{$a->id}", $this->forma($a, [
                'naziv'    => 'Šećer u kocki',
                'kolicina' => '10,5',      // decimalni zarez
                'cena'     => '99',
                'aktivan'  => '0',
                'napomena' => '',          // napomena sme da se obrise
            ]))
            ->assertRedirect('/artikli')
            ->assertSessionHas('poruka', 'Sačuvan artikal 001001 (Šećer u kocki).');

        $a->refresh();
        $this->assertSame('Šećer u kocki', $a->naziv);
        $this->assertSame('10.500', $a->kolicina);
        $this->assertSame('99.00', $a->cena);
        $this->assertFalse($a->aktivan);
        $this->assertNull($a->napomena);
    }

    public function test_postojeca_vrednost_ne_sme_da_se_isprazni(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->put("/artikli/{$a->id}", $this->forma($a, [
                'kolicina'      => '',
                'cena'          => '',
                'datum_nabavke' => '',
                'aktivan'       => '',
            ]))
            ->assertSessionHasErrors([
                'kolicina'      => 'Polje količina je obavezno.',
                'cena'          => 'Polje cena je obavezno.',
                'datum_nabavke' => 'Polje datum nabavke je obavezno.',
                'aktivan'       => 'Polje aktivan je obavezno.',
            ]);

        $this->assertSame('532.572', $a->fresh()->kolicina);
    }

    public function test_stari_artikal_sa_nepoznatim_vrednostima_moze_da_se_sacuva(): void
    {
        // Kao zapisi iz starog programa: nepoznata kolicina i "?" za aktivan
        $a = $this->artikal(['kolicina' => null, 'aktivan' => null]);

        $this->prijavljen()
            ->get("/artikli/{$a->id}/izmena")
            ->assertSee('? (nepoznato)');

        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => 'Šećer, stari']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/artikli');

        $a->refresh();
        $this->assertSame('Šećer, stari', $a->naziv);
        $this->assertNull($a->kolicina);
        $this->assertNull($a->aktivan);
    }

    public function test_artikal_sa_poznatom_vrednoscu_ne_nudi_upitnik(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->get("/artikli/{$a->id}/izmena")
            ->assertDontSee('? (nepoznato)');

        $this->get('/artikli/novi')
            ->assertDontSee('? (nepoznato)');
    }

    public function test_cuvanje_bez_promene_ne_dira_artikal(): void
    {
        $a = $this->artikal();
        $pre = $a->updated_at;

        $this->travel(5)->minutes();

        // Forma poslata bez ijedne promene (napomena i dalje sa \r\n)
        $this->prijavljen()
            ->put("/artikli/{$a->id}", $this->forma($a))
            ->assertRedirect('/artikli')
            ->assertSessionHas('poruka', 'Artikal 001001: nema izmena.');

        // Ista vrednost sa decimalnim zarezom nije izmena: "331,68" = "331.68"
        $this->put("/artikli/{$a->id}", $this->forma($a, ['cena' => '331,68', 'kolicina' => '532.572']))
            ->assertSessionHas('poruka', 'Artikal 001001: nema izmena.');

        $this->assertEquals($pre, $a->fresh()->updated_at);
    }

    public function test_previse_decimala_se_odbija(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->put("/artikli/{$a->id}", $this->forma($a, ['kolicina' => '1.2345', 'cena' => '1,234']))
            ->assertSessionHasErrors([
                'kolicina' => 'Količina može imati najviše 3 decimale.',
                'cena'     => 'Cena može imati najviše 2 decimale.',
            ]);
    }

    public function test_sifra_se_ne_menja_ni_kad_je_neko_posalje(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->put("/artikli/{$a->id}", $this->forma($a, ['sifra' => '999999', 'naziv' => 'Novo ime']));

        $this->assertSame('001001', $a->fresh()->sifra);
        $this->assertSame('Novo ime', $a->fresh()->naziv);
    }

    public function test_neispravna_izmena_vraca_srpske_poruke(): void
    {
        $a = $this->artikal();

        $this->prijavljen()
            ->from("/artikli/{$a->id}/izmena")
            ->put("/artikli/{$a->id}", $this->forma($a, [
                'naziv'         => '',
                'jedinica_mere' => 'tona',
                'kolicina'      => '-1',
                'cena'          => '12,345',
                'datum_nabavke' => today(config('magacin.zona_prikaza'))->addDay()->format('Y-m-d'),
                'aktivan'       => 'mozda',
                'napomena'      => str_repeat('x', 1001),
            ]))
            ->assertRedirect("/artikli/{$a->id}/izmena")
            ->assertSessionHasErrors([
                'naziv'         => 'Polje naziv je obavezno.',
                'jedinica_mere' => 'Izabrana vrednost za polje jedinica mere nije dozvoljena.',
                'kolicina'      => 'Polje količina ne sme biti manje od 0.',
                'cena'          => 'Cena može imati najviše 2 decimale.',
                'datum_nabavke' => 'Datum nabavke ne sme biti u budućnosti.',
                'aktivan'       => 'Polje aktivan mora biti da ili ne.',
                'napomena'      => 'Polje napomena može imati najviše 1000 znakova.',
            ]);

        $this->assertSame('Šećer kristal', $a->fresh()->naziv);
    }

    public function test_naziv_duzi_od_30_znakova_se_odbija(): void
    {
        // SQLite ne proverava duzinu kolone, zato to mora validacija
        $a = $this->artikal();

        $this->prijavljen()
            ->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => str_repeat('a', 31)]))
            ->assertSessionHasErrors(['naziv' => 'Polje naziv može imati najviše 30 znakova.']);
    }

    // --- dodavanje ---

    public function test_dodavanje_novog_artikla(): void
    {
        $this->prijavljen()
            ->post('/artikli', [
                'sifra'         => '001038',
                'naziv'         => 'Pirinač',
                'jedinica_mere' => 'kg',
                'kolicina'      => '25',
                'cena'          => '189,90',
                'datum_nabavke' => '2026-11-01',
                'aktivan'       => '1',
                'napomena'      => '',
            ])
            ->assertRedirect('/artikli')
            ->assertSessionHas('poruka', 'Dodat artikal 001038 (Pirinač).');

        $a = Artikal::where('sifra', '001038')->firstOrFail();
        $this->assertSame('25.000', $a->kolicina);
        $this->assertSame('189.90', $a->cena);
        $this->assertTrue($a->aktivan);
        $this->assertNull($a->napomena);
    }

    public function test_novi_artikal_mora_imati_sva_polja_osim_napomene(): void
    {
        $this->prijavljen()
            ->post('/artikli', [
                'sifra'         => '001039',
                'naziv'         => '',
                'jedinica_mere' => '',
                'kolicina'      => '',
                'cena'          => '',
                'datum_nabavke' => '',
                'aktivan'       => '',
                'napomena'      => '',
            ])
            ->assertSessionHasErrors([
                'naziv'         => 'Polje naziv je obavezno.',
                'jedinica_mere' => 'Polje jedinica mere je obavezno.',
                'kolicina'      => 'Polje količina je obavezno.',
                'cena'          => 'Polje cena je obavezno.',
                'datum_nabavke' => 'Polje datum nabavke je obavezno.',
                'aktivan'       => 'Polje aktivan je obavezno.',
            ])
            ->assertSessionDoesntHaveErrors('napomena');

        $this->assertSame(0, Artikal::count());
    }

    public function test_sifra_mora_biti_sest_cifara_i_jedinstvena(): void
    {
        $this->artikal();
        $prijavljen = $this->prijavljen();

        foreach (['', '12345', '1234567', '00100A'] as $losa) {
            $prijavljen->post('/artikli', ['sifra' => $losa, 'naziv' => 'X', 'jedinica_mere' => 'kg'])
                ->assertSessionHasErrors('sifra');
        }

        $prijavljen->post('/artikli', ['sifra' => '001001', 'naziv' => 'X', 'jedinica_mere' => 'kg'])
            ->assertSessionHasErrors(['sifra' => 'Artikal sa tom šifrom već postoji.']);

        $this->assertSame(1, Artikal::count());
    }

    public function test_prijavljen_vidi_dugmad_i_formu_za_novi_artikal(): void
    {
        $this->artikal();

        $this->prijavljen()
            ->get('/artikli')
            ->assertSee('Dodaj artikal')
            ->assertSee('Izmeni');

        $this->get('/artikli/novi')
            ->assertOk()
            ->assertSee('Novi artikal')
            ->assertSee('name="sifra"', false);
    }
}
