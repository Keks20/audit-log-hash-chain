<?php

namespace Tests\Feature;

use App\Models\Artikal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtikliPretragaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['001001', 'Šećer kristal'], ['001002', 'Brašno T-400'], ['002001', 'Ulje 100% suncokret'], ['002002', 'Sok_jabuka']] as [$sifra, $naziv]) {
            Artikal::create([
                'sifra' => $sifra, 'naziv' => $naziv, 'jedinica_mere' => 'kg',
                'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
            ]);
        }
    }

    /** @return string[] sifre prikazanih artikala */
    private function nadjeni(string $q): array
    {
        return $this->get('/artikli?q=' . urlencode($q))->assertOk()->viewData('artikli')->pluck('sifra')->all();
    }

    public function test_bez_pretrage_svi_artikli(): void
    {
        $this->get('/artikli')->assertSee('Ukupno: 4')->assertDontSee('Nađeno');
        $this->assertSame(['001001', '001002', '002001', '002002'], $this->nadjeni(''));
        $this->assertSame(['001001', '001002', '002001', '002002'], $this->nadjeni('   '));
    }

    public function test_pretraga_po_delu_naziva_i_sifre(): void
    {
        $this->assertSame(['001002'], $this->nadjeni('brašno'));
        $this->assertSame(['001001'], $this->nadjeni('ŠEĆER'));
        // Trazi se deo sifre bilo gde, ne samo pocetak
        $this->assertSame(['001002', '002001', '002002'], $this->nadjeni('002'));
        $this->assertSame(['001001', '001002', '002001'], $this->nadjeni('  001  '));
        $this->assertSame(['002002'], $this->nadjeni('2002'));
    }

    public function test_procenat_i_donja_crta_su_obican_tekst(): void
    {
        $this->assertSame(['002001'], $this->nadjeni('100%'));
        $this->assertSame([], $this->nadjeni('%'  . 'x'));
        $this->assertSame(['002002'], $this->nadjeni('_'));
        $this->assertSame(['002001'], $this->nadjeni('%'));
    }

    public function test_pokusaj_sql_injekcije_je_samo_tekst(): void
    {
        $this->assertSame([], $this->nadjeni("' OR '1'='1"));
        $this->assertSame(4, Artikal::count());
    }

    public function test_stranica_prikazuje_broj_nadjenih_i_poruku_kad_nema(): void
    {
        $this->get('/artikli?q=ulje')
            ->assertSee('Nađeno: 1')
            ->assertSee('value="ulje"', false)
            ->assertSee('Ulje 100% suncokret')
            ->assertDontSee('Šećer kristal');

        $this->get('/artikli?q=' . urlencode('<script>alert(1)</script>'))
            ->assertOk()
            ->assertSee('Nijedan artikal ne odgovara pretrazi')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('php artisan db:seed');
    }

    public function test_niz_umesto_teksta_ne_obara_stranicu(): void
    {
        $this->get('/artikli?q[]=a')->assertOk()->assertSee('Ukupno: 4');
    }
}
