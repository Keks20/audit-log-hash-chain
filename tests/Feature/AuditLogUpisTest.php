<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AuditLogUpisTest extends TestCase
{
    use RefreshDatabase;

    private User $korisnik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->korisnik = User::factory()->sef()->create(['email' => 'sef@magacin.test']);
        $this->actingAs($this->korisnik);
    }

    private function artikal(): Artikal
    {
        // Direktno u bazu, bez zapisa u logu (kao artikli iz seedera)
        return Artikal::create([
            'sifra'         => '001001',
            'naziv'         => 'Šećer kristal',
            'jedinica_mere' => 'kg',
            'kolicina'      => '532.572',
            'cena'          => '331.68',
            'datum_nabavke' => '1996-11-23',
            'aktivan'       => true,
            'napomena'      => "Dobavljač kasni.\r\nPozvati.",
        ]);
    }

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

    private function novi(array $izmene = []): array
    {
        return array_merge([
            'sifra'         => '001038',
            'naziv'         => 'Pirinač',
            'jedinica_mere' => 'kg',
            'kolicina'      => '25',
            'cena'          => '189,90',
            'datum_nabavke' => '2026-11-01',
            'aktivan'       => '1',
            'napomena'      => '',
        ], $izmene);
    }

    public function test_dodavanje_pravi_prvi_zapis_lanca(): void
    {
        $this->post('/artikli', $this->novi())->assertRedirect('/artikli');

        $this->assertSame(1, AuditZapis::count());
        $z = AuditZapis::firstOrFail();
        $a = Artikal::where('sifra', '001038')->firstOrFail();

        $this->assertSame(AuditZapis::DODAT, $z->akcija);
        $this->assertSame($a->id, $z->artikal_id);
        $this->assertSame('001038', $z->sifra);
        $this->assertNull($z->pre);
        $this->assertSame($a->kanonskiJson(), $z->posle);
        $this->assertSame($this->korisnik->id, $z->korisnik_id);
        $this->assertSame('sef@magacin.test', $z->korisnik_email);
        $this->assertSame(AuditZapis::POCETNI_HES, $z->prethodni_hes);
        $this->assertSame(64, strlen(AuditZapis::POCETNI_HES));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $z->hes);
        $this->assertSame(AuditLog::hes($z->getAttributes()), $z->hes);
    }

    public function test_izmena_cuva_stanje_pre_i_posle(): void
    {
        $a = $this->artikal();

        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => 'Šećer u kocki', 'cena' => '340']))
            ->assertRedirect('/artikli');

        $z = AuditZapis::sole();
        $this->assertSame(AuditZapis::IZMENJEN, $z->akcija);
        $this->assertSame('Šećer kristal', $z->preNiz()['naziv']);
        $this->assertSame('Šećer u kocki', $z->posleNiz()['naziv']);
        $this->assertSame([
            'cena'  => ['331.68', '340.00'],
            'naziv' => ['Šećer kristal', 'Šećer u kocki'],
        ], collect($z->izmene())->sortKeys()->all());

        // Napomena sa \r\n prolazi netaknuta kroz log
        $this->assertSame("Dobavljač kasni.\r\nPozvati.", $z->posleNiz()['napomena']);
    }

    public function test_brisanje_cuva_poslednje_stanje_i_zapis_ostaje(): void
    {
        $a = $this->artikal();

        $this->delete("/artikli/{$a->id}")->assertRedirect('/artikli');

        $this->assertModelMissing($a);

        $z = AuditZapis::sole();
        $this->assertSame(AuditZapis::OBRISAN, $z->akcija);
        $this->assertSame($a->id, $z->artikal_id);
        $this->assertNull($z->posle);
        $this->assertSame('Šećer kristal', $z->preNiz()['naziv']);
    }

    public function test_zapisi_su_ulancani(): void
    {
        $this->post('/artikli', $this->novi());
        $a = Artikal::where('sifra', '001038')->firstOrFail();
        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => 'Pirinač dugo zrno']));
        $this->delete("/artikli/{$a->id}");

        $zapisi = AuditZapis::orderBy('id')->get();
        $this->assertSame(['dodat', 'izmenjen', 'obrisan'], $zapisi->pluck('akcija')->all());
        $this->assertSame(AuditZapis::POCETNI_HES, $zapisi[0]->prethodni_hes);
        $this->assertSame($zapisi[0]->hes, $zapisi[1]->prethodni_hes);
        $this->assertSame($zapisi[1]->hes, $zapisi[2]->prethodni_hes);

        $this->assertTrue(app(AuditLog::class)->proveri()->ispravan);
    }

    public function test_cuvanje_bez_promene_ne_pravi_zapis(): void
    {
        $a = $this->artikal();

        $this->put("/artikli/{$a->id}", $this->forma($a))
            ->assertSessionHas('poruka', 'Artikal 001001: nema izmena.');

        $this->assertSame(0, AuditZapis::count());
    }

    public function test_neuspela_validacija_ne_pravi_zapis(): void
    {
        $a = $this->artikal();

        $this->post('/artikli', $this->novi(['sifra' => 'abc']))->assertSessionHasErrors('sifra');
        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => '']))->assertSessionHasErrors('naziv');

        $this->assertSame(0, AuditZapis::count());
    }

    public function test_ako_upis_u_log_ne_uspe_artikal_se_ne_menja(): void
    {
        $a = $this->artikal();

        // Namerna greska: bez debug stranice i bez logovanja (brze i cistiji izlaz)
        config(['app.debug' => false, 'logging.default' => 'null']);

        $this->mock(AuditLog::class, function (MockInterface $mock) {
            $mock->shouldReceive('zapisi')->andThrow(new RuntimeException('Log nije dostupan'));
        });

        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => 'Bez loga']))->assertServerError();
        $this->post('/artikli', $this->novi())->assertServerError();
        $this->delete("/artikli/{$a->id}")->assertServerError();

        // Sve tri izmene su ponistene zajedno sa neuspelim upisom u log
        $this->assertSame('Šećer kristal', $a->fresh()->naziv);
        $this->assertNull(Artikal::where('sifra', '001038')->first());
        $this->assertSame(0, AuditZapis::count());
    }

    public function test_zapis_loga_ne_moze_da_se_menja_ni_brise_kroz_model(): void
    {
        $this->post('/artikli', $this->novi());
        $z = AuditZapis::sole();

        try {
            $z->update(['sifra' => '999999']);
            $this->fail('Izmena zapisa je morala da bude odbijena.');
        } catch (LogicException) {
        }

        try {
            $z->delete();
            $this->fail('Brisanje zapisa je moralo da bude odbijeno.');
        } catch (LogicException) {
        }

        $this->assertSame('001038', AuditZapis::sole()->sifra);
    }

    public function test_dva_zapisa_ne_mogu_imati_istog_prethodnika(): void
    {
        $this->post('/artikli', $this->novi());
        $prvi = AuditZapis::sole();

        // Kao da je drugi upis istovremeno procitao isti kraj lanca
        $this->expectException(QueryException::class);

        AuditZapis::create(array_merge($prvi->getAttributes(), [
            'id'  => null,
            'hes' => str_repeat('a', 64),
        ]));
    }

    // --- trigeri u bazi (i direktan SQL, mimo modela) ---

    public function test_baza_odbija_izmenu_zapisa(): void
    {
        $this->post('/artikli', $this->novi());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_log: zapis se ne menja');

        DB::table('audit_log')->update(['korisnik_email' => 'neko@magacin.test']);
    }

    public function test_baza_odbija_brisanje_zapisa(): void
    {
        $this->post('/artikli', $this->novi());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_log: zapis se ne brise');

        DB::table('audit_log')->delete();
    }

    public function test_baza_odbija_brisanje_glave(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_log_glava: glava se ne brise');

        DB::table('audit_log_glava')->delete();
    }

    public function test_glava_ne_moze_unazad_ni_na_proizvoljan_hes(): void
    {
        $this->post('/artikli', $this->novi());
        $a = Artikal::where('sifra', '001038')->firstOrFail();
        $this->put("/artikli/{$a->id}", $this->forma($a, ['naziv' => 'Pirinač dugo zrno']));
        $prvi = AuditZapis::orderBy('id')->firstOrFail();

        foreach ([
            ['poslednji_id' => $prvi->id, 'poslednji_hes' => $prvi->hes],   // unazad
            ['poslednji_id' => 999, 'poslednji_hes' => str_repeat('b', 64)], // izmisljeno
        ] as $pokusaj) {
            try {
                DB::table('audit_log_glava')->where('id', 1)->update($pokusaj);
                $this->fail('Baza je morala da odbije pomeranje glave.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('glava se pomera samo na sledeci zapis lanca', $e->getMessage());
            }
        }

        $this->assertTrue(app(AuditLog::class)->proveri()->ispravan);
    }

    public function test_brisanje_korisnika_ne_menja_log(): void
    {
        $this->post('/artikli', $this->novi());

        $this->korisnik->delete();

        $z = AuditZapis::sole();
        $this->assertSame('sef@magacin.test', $z->korisnik_email);
        $this->assertTrue(app(AuditLog::class)->proveri()->ispravan);
    }
}
