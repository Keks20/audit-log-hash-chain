<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BezbednosnaZaglavljaTest extends TestCase
{
    use RefreshDatabase;

    private function proveri($odgovor): void
    {
        $odgovor->assertHeader('X-Frame-Options', 'DENY');
        $odgovor->assertHeader('X-Content-Type-Options', 'nosniff');
        $odgovor->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_zaglavlja_na_javnoj_strani_preusmerenju_i_gresci(): void
    {
        $this->proveri($this->get('/artikli')->assertOk());
        $this->proveri($this->get('/audit-log')->assertRedirect('/prijava'));
        $this->proveri($this->get('/prijava')->assertOk());
        $this->proveri($this->get('/nema-te-strane')->assertNotFound());
        $this->proveri($this->get('/artikli?x=%8A')->assertStatus(400));
    }

    public function test_zaglavlja_za_prijavljenog_i_na_izvozu(): void
    {
        $this->actingAs(User::factory()->create());

        $this->proveri($this->get('/audit-log')->assertOk());
        $this->proveri($this->get('/audit-log/izvoz')->assertOk());
        $this->proveri($this->delete('/artikli/999')->assertNotFound());

        $a = Artikal::create([
            'sifra' => '001001', 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
        $this->proveri($this->delete("/artikli/{$a->id}")->assertForbidden());
    }

    public function test_bootstrap_sa_cdn_a_ima_integrity(): void
    {
        $html = $this->get('/artikli')->getContent();

        $this->assertSame(2, preg_match_all('/integrity="sha384-[A-Za-z0-9+\/]{64}" crossorigin="anonymous"/', $html));
    }
}
