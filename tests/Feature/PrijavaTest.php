<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrijavaTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'magacioner@magacin.test';

    private const LOZINKA = 'lozinka-za-test';

    private function korisnik(): User
    {
        return User::factory()->create([
            'name'     => 'Marko Marković',
            'email'    => self::EMAIL,
            'password' => self::LOZINKA,
        ]);
    }

    public function test_gost_vidi_formu_za_prijavu(): void
    {
        $this->get('/prijava')
            ->assertOk()
            ->assertSee('Prijavi se')
            ->assertSee('Lozinka');
    }

    public function test_ispravna_prijava_vodi_na_listu_artikala(): void
    {
        $korisnik = $this->korisnik();

        $this->post('/prijava', ['email' => self::EMAIL, 'password' => self::LOZINKA])
            ->assertRedirect('/artikli')
            ->assertSessionHas('poruka', 'Uspešna prijava: Marko Marković.');

        $this->assertAuthenticatedAs($korisnik);

        $this->get('/artikli')
            ->assertSee('Marko Marković')
            ->assertSee('Odjava');
    }

    public function test_pogresna_lozinka_vraca_srpsku_poruku_bez_lozinke_u_formi(): void
    {
        $this->korisnik();

        $this->from('/prijava')
            ->post('/prijava', ['email' => self::EMAIL, 'password' => 'pogresna'])
            ->assertRedirect('/prijava')
            ->assertSessionHasErrors(['email' => 'Pogrešan email ili lozinka.'])
            ->assertSessionHasInput('email', self::EMAIL);

        $this->assertFalse(session()->hasOldInput('password'));
        $this->assertGuest();
    }

    public function test_prazna_forma_vraca_srpske_poruke(): void
    {
        $this->from('/prijava')
            ->post('/prijava', [])
            ->assertSessionHasErrors([
                'email'    => 'Polje email je obavezno.',
                'password' => 'Polje lozinka je obavezno.',
            ]);
    }

    public function test_posle_pet_neuspesnih_pokusaja_prijava_je_blokirana(): void
    {
        $this->korisnik();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/prijava', ['email' => self::EMAIL, 'password' => 'pogresna']);
        }

        // I ispravna lozinka se odbija dok traje blokada
        $this->post('/prijava', ['email' => self::EMAIL, 'password' => self::LOZINKA])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Previše pokušaja prijave', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_prijavljen_korisnik_ne_vidi_formu_za_prijavu(): void
    {
        $this->actingAs($this->korisnik())
            ->get('/prijava')
            ->assertRedirect('/artikli');
    }

    public function test_odjava_vodi_na_prijavu(): void
    {
        $this->actingAs($this->korisnik())
            ->post('/odjava')
            ->assertRedirect('/prijava')
            ->assertSessionHas('poruka', 'Uspešna odjava.');

        $this->assertGuest();

        $this->get('/prijava')
            ->assertOk()
            ->assertSee('Uspešna odjava.');
    }

    public function test_gost_ne_moze_na_odjavu(): void
    {
        $this->post('/odjava')->assertRedirect('/prijava');
    }

    public function test_lista_artikala_je_javna_i_nudi_prijavu(): void
    {
        $this->get('/artikli')
            ->assertOk()
            ->assertSee('Prijava');
    }
}
