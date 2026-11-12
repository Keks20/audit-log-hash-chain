<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Zahtev sa tekstom koji nije ispravan UTF-8 (npr. iz alata koji salje
 * cp1250) odbija se sa 400 pre sesije i baze: nista se ne upisuje, a sesija
 * (i prijava) ostaje kakva je bila.
 */
class NeispravanUtf8Test extends TestCase
{
    use RefreshDatabase;

    // "Šećer" i "žuto" u cp1250: bajtovi 0x8A, 0xE6 i 0x9E nisu ispravan UTF-8
    private const NAZIV_CP1250 = "\x8Ae\xE6er";

    private const NAPOMENA_CP1250 = "\x9Euto pakovanje";

    private const PORUKA = 'Neispravan zahtev: tekst nije u UTF-8 kodiranju.';

    private const LOZINKA = 'lozinka-za-test';

    private function artikal(): Artikal
    {
        return Artikal::create([
            'sifra'         => '001001',
            'naziv'         => 'Šećer kristal',
            'jedinica_mere' => 'kg',
            'kolicina'      => '10.000',
            'cena'          => '100.00',
            'datum_nabavke' => '1996-11-23',
            'aktivan'       => true,
            'napomena'      => null,
        ]);
    }

    private function noviArtikal(array $izmene = []): array
    {
        return array_merge([
            'sifra'         => '009999',
            'naziv'         => self::NAZIV_CP1250,
            'jedinica_mere' => 'kg',
            'kolicina'      => '1',
            'cena'          => '1',
            'datum_nabavke' => '2026-01-01',
            'aktivan'       => '1',
            'napomena'      => self::NAPOMENA_CP1250,
        ], $izmene);
    }

    public function test_nov_artikal_sa_neispravnim_utf8_vraca_400(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/artikli', $this->noviArtikal())
            ->assertStatus(400)
            ->assertSeeText(self::PORUKA);

        $this->assertSame(0, Artikal::count());
        $this->assertSame(0, AuditZapis::count());
    }

    public function test_izmena_sa_neispravnim_utf8_vraca_400(): void
    {
        $a = $this->artikal();

        $this->actingAs(User::factory()->create())
            ->put("/artikli/{$a->id}", [
                'naziv'         => self::NAZIV_CP1250,
                'jedinica_mere' => 'kg',
                'kolicina'      => '10.000',
                'cena'          => '100.00',
                'datum_nabavke' => '1996-11-23',
                'aktivan'       => '1',
                'napomena'      => self::NAPOMENA_CP1250,
            ])
            ->assertStatus(400);

        $this->assertSame('Šećer kristal', $a->fresh()->naziv);
        $this->assertNull($a->fresh()->napomena);
        $this->assertSame(0, AuditZapis::count());
    }

    public function test_prijava_sa_neispravnim_utf8_u_email_vraca_400(): void
    {
        User::factory()->create(['email' => 'marko@magacin.test', 'password' => self::LOZINKA]);

        $this->post('/prijava', ['email' => "marko\xE6@magacin.test", 'password' => self::LOZINKA])
            ->assertStatus(400);

        $this->assertGuest();
    }

    public function test_prijavljen_korisnik_ostaje_prijavljen_posle_odbijenog_zahteva(): void
    {
        $korisnik = User::factory()->create(['password' => self::LOZINKA]);

        $this->post('/prijava', ['email' => $korisnik->email, 'password' => self::LOZINKA])
            ->assertRedirect('/artikli');

        $id = session()->getId();

        // Kolacic kao iz pregledaca, pa zahtev ide u istu sesiju. Cena je namerno
        // neispravna: bez middleware-a bi validacija pala, neispravan naziv bi otisao
        // u old(), json_encode bi vratio false i sesija bi se upisala prazna.
        $this->withCookie(config('session.cookie'), $id)
            ->post('/artikli', $this->noviArtikal(['cena' => 'nije-broj']))
            ->assertStatus(400);

        // Sesija se u testu drzi i u memoriji, pa se proverava ono sto je stvarno
        // upisano: sledeci zahtev iz pregledaca bi procitao bas ovo.
        $sacuvano = json_decode(app('session.store')->getHandler()->read($id), true);

        $this->assertIsArray($sacuvano);
        $this->assertSame($korisnik->id, $sacuvano[Auth::guard()->getName()] ?? null);
    }

    public function test_get_sa_neispravnim_utf8_u_adresi_vraca_400(): void
    {
        $this->get('/artikli?x=%8A')->assertStatus(400);
    }

    public function test_neispravan_utf8_u_kljucu_ili_putanji_vraca_400(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/artikli', ["na\x8Aziv" => 'Šećer'])
            ->assertStatus(400);

        $this->get('/artikli/%8A/izmena')->assertStatus(400);

        $this->assertSame(0, Artikal::count());
    }

    public function test_nasa_slova_i_dalje_prolaze(): void
    {
        $this->get('/artikli?x=' . rawurlencode('Šećer žuti'))->assertOk();

        $this->actingAs(User::factory()->create())
            ->post('/artikli', $this->noviArtikal([
                'naziv'    => 'Šećer žuti ČĆĐ',
                'napomena' => 'Dobavljač đak, ćevap, žir.',
            ]))
            ->assertRedirect('/artikli')
            ->assertSessionHasNoErrors();

        $this->assertSame('Šećer žuti ČĆĐ', Artikal::where('sifra', '009999')->value('naziv'));
        $this->assertSame(1, AuditZapis::count());
    }
}
