<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogFilteriIzvozTest extends TestCase
{
    use RefreshDatabase;

    private User $magacioner;

    private User $sef;

    /**
     * Cetiri zapisa:
     *  1. 30.06. 23:30 po Beogradu (21:30 UTC), magacioner dodaje 001001 "Šećer"
     *  2. 01.07. 12:00 po Beogradu, magacioner menja 001001 u "Šećer kristal"
     *  3. 01.07. 12:05, sef dodaje 002001 "=SUM(A1)"
     *  4. 02.07. 09:00, sef brise 001001
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->magacioner = User::factory()->create(['email' => 'magacioner@magacin.test']);
        $this->sef = User::factory()->sef()->create(['email' => 'sef@magacin.test']);

        $polja = fn (string $sifra, string $naziv) => [
            'sifra' => $sifra, 'naziv' => $naziv, 'jedinica_mere' => 'kg', 'kolicina' => '10', 'cena' => '100',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ];

        $this->travelTo(Carbon::parse('2026-06-30 21:30:00', 'UTC'));
        $this->actingAs($this->magacioner)->post('/artikli', $polja('001001', 'Šećer'))->assertSessionHasNoErrors();
        $id = Artikal::where('sifra', '001001')->value('id');

        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
        $this->put("/artikli/{$id}", $polja('001001', 'Šećer kristal'))->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2026-07-01 10:05:00', 'UTC'));
        $this->actingAs($this->sef)->post('/artikli', $polja('002001', '=SUM(A1)'))->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2026-07-02 07:00:00', 'UTC'));
        $this->delete("/artikli/{$id}")->assertRedirect();

        $this->travelBack();
        $this->assertSame(4, AuditZapis::count());
    }

    /** @return int[] id-jevi zapisa na stranici, redom prikaza */
    private function prikazani(string $upit): array
    {
        return $this->get('/audit-log' . $upit)->assertOk()->viewData('zapisi')->pluck('id')->all();
    }

    private function id(int $redni): int
    {
        return AuditZapis::orderBy('id')->skip($redni - 1)->value('id');
    }

    // --- filteri ---

    public function test_bez_filtera_svi_zapisi_najnoviji_prvi(): void
    {
        $this->assertSame([$this->id(4), $this->id(3), $this->id(2), $this->id(1)], $this->prikazani(''));
    }

    public function test_filter_po_akciji(): void
    {
        $this->assertSame([$this->id(3), $this->id(1)], $this->prikazani('?akcija=dodat'));
        $this->assertSame([$this->id(4)], $this->prikazani('?akcija=obrisan'));
        $this->assertSame([], $this->prikazani('?akcija=pocetno'));
    }

    public function test_filter_po_korisniku(): void
    {
        $this->assertSame([$this->id(2), $this->id(1)], $this->prikazani('?korisnik=magacioner@magacin.test'));
        $this->assertSame([], $this->prikazani('?korisnik=niko@magacin.test'));
    }

    public function test_filter_po_pocetku_sifre(): void
    {
        $this->assertSame([$this->id(3)], $this->prikazani('?sifra=002'));
        $this->assertSame([$this->id(4), $this->id(2), $this->id(1)], $this->prikazani('?sifra=001001'));
    }

    public function test_filter_po_danu_racuna_dan_po_beogradu(): void
    {
        // Zapis 1 je nastao 30.06. u 23:30 po Beogradu, iako je u UTC jos 30.06. 21:30
        $this->assertSame([$this->id(1)], $this->prikazani('?do=2026-06-30'));
        $this->assertSame([$this->id(3), $this->id(2)], $this->prikazani('?od=2026-07-01&do=2026-07-01'));
        $this->assertSame([$this->id(4)], $this->prikazani('?od=2026-07-02'));
    }

    public function test_dan_prelaska_na_letnje_vreme(): void
    {
        // 29.03.2026. u Beogradu traje 23 sata: od 28.03. 23:00 UTC do 29.03. 21:59:59 UTC
        $a = Artikal::create([
            'sifra' => '003001', 'naziv' => 'Brašno', 'jedinica_mere' => 'kg',
            'kolicina' => '1', 'cena' => '1', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
        $log = app(AuditLog::class);

        foreach (['2026-03-28 22:59:59', '2026-03-28 23:00:00', '2026-03-29 21:59:59', '2026-03-29 22:00:00'] as $utc) {
            $this->travelTo(Carbon::parse($utc, 'UTC'));
            DB::transaction(fn () => $log->zapisi(AuditZapis::IZMENJEN, $a, $a->kanonskiPodaci(), $a->kanonskiPodaci(), null));
        }
        $this->travelBack();

        $vremena = $this->get('/audit-log?od=2026-03-29&do=2026-03-29')->viewData('zapisi')
            ->map(fn ($z) => $z->vreme->format('Y-m-d H:i:s'))->all();

        $this->assertSame(['2026-03-29 21:59:59', '2026-03-28 23:00:00'], $vremena);
    }

    public function test_filteri_se_kombinuju_i_sa_istorijom_artikla(): void
    {
        $artikalId = AuditZapis::orderBy('id')->value('artikal_id');

        $this->assertSame([$this->id(2)], $this->prikazani("?artikal={$artikalId}&akcija=izmenjen&korisnik=magacioner@magacin.test"));
        $this->assertSame([], $this->prikazani("?artikal={$artikalId}&sifra=002"));
    }

    public function test_neispravan_filter_se_ignorise(): void
    {
        $svi = [$this->id(4), $this->id(3), $this->id(2), $this->id(1)];

        $this->assertSame($svi, $this->prikazani('?akcija=nepostojeca'));
        $this->assertSame($svi, $this->prikazani('?od=juce&do=2026-13-45'));
        $this->assertSame($svi, $this->prikazani('?od=2026-02-30'));
        $this->assertSame($svi, $this->prikazani("?sifra=1'%20OR%201=1"));
        $this->assertSame($svi, $this->prikazani('?akcija[]=dodat&korisnik[]=x&od[]=1'));
    }

    public function test_stranica_prikazuje_filtere_i_broj_nadjenih(): void
    {
        $this->get('/audit-log?akcija=dodat')
            ->assertOk()
            ->assertSee('Nađeno zapisa: 2.')
            ->assertSee('Poništi filtere')
            ->assertSee('magacioner@magacin.test')
            ->assertSee('akcija=dodat', false);

        $this->get('/audit-log?akcija=pocetno')->assertSee('Nijedan zapis ne odgovara filterima.');
        $this->get('/audit-log')->assertDontSee('Poništi filtere');
    }

    // --- izvoz ---

    /** @return array<int, string[]> redovi CSV-a bez zaglavlja */
    private function csv(string $upit = ''): array
    {
        $odgovor = $this->get('/audit-log/izvoz' . $upit)->assertOk();
        $sadrzaj = $odgovor->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $sadrzaj);

        $redovi = array_map(
            fn (string $red) => str_getcsv($red, ';', '"', ''),
            preg_split('/\n/', trim(substr($sadrzaj, 3)))
        );

        $this->assertSame(['id', 'vreme_utc', 'vreme_beograd', 'korisnik', 'akcija', 'sifra', 'artikal_id', 'izmene',
            'pre', 'posle', 'prethodni_hes', 'hes', 'potpis'], array_shift($redovi));

        return $redovi;
    }

    public function test_gost_ne_moze_da_izveze(): void
    {
        $this->post('/odjava');

        $this->get('/audit-log/izvoz')->assertRedirect('/prijava');
    }

    public function test_izvoz_sadrzi_sve_zapise_redom_sa_hesevima_i_potpisima(): void
    {
        $odgovor = $this->get('/audit-log/izvoz');
        $odgovor->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertMatchesRegularExpression('/attachment; filename=audit-log-\d{8}-\d{6}\.csv/', $odgovor->headers->get('Content-Disposition'));

        $redovi = $this->csv();
        $this->assertCount(4, $redovi);

        $zapisi = AuditZapis::orderBy('id')->get();
        foreach ($redovi as $i => $red) {
            $z = $zapisi[$i];
            $this->assertSame((string) $z->id, $red[0]);
            $this->assertSame($z->pre ?? '', $red[8]);
            $this->assertSame($z->posle ?? '', $red[9]);
            $this->assertSame($z->prethodni_hes, $red[10]);
            $this->assertSame($z->hes, $red[11]);
            $this->assertSame($z->potpis, $red[12]);
        }

        // Iz izvezenih kolona hes moze da se izracuna i van aplikacije
        $red = $redovi[1];
        $this->assertSame($red[11], AuditLog::hes([
            'akcija' => $red[4], 'artikal_id' => $red[6], 'korisnik_email' => $red[3],
            'korisnik_id' => $this->magacioner->id, 'posle' => $red[9], 'pre' => $red[8],
            'prethodni_hes' => $red[10], 'sifra' => $red[5], 'vreme' => $red[1],
        ]));
    }

    public function test_izvoz_ima_oba_vremena_i_citljive_izmene(): void
    {
        $red = $this->csv()[1];

        $this->assertSame('2026-07-01 10:00:00', $red[1]);
        $this->assertSame('2026-07-01 12:00:00', $red[2]);
        $this->assertSame('magacioner@magacin.test', $red[3]);
        $this->assertSame('izmenjen', $red[4]);
        $this->assertSame('001001', $red[5]);
        $this->assertSame('Naziv: Šećer → Šećer kristal', $red[7]);
    }

    public function test_izvoz_postuje_filtere(): void
    {
        $this->assertSame([(string) $this->id(3), (string) $this->id(4)], array_column($this->csv('?korisnik=sef@magacin.test'), 0));
        $this->assertSame([(string) $this->id(4)], array_column($this->csv('?akcija=obrisan'), 0));
    }

    public function test_izvoz_bez_zapisa_ima_samo_zaglavlje(): void
    {
        $this->assertSame([], $this->csv('?akcija=pocetno'));
    }

    public function test_tekst_koji_lici_na_formulu_dobija_apostrof(): void
    {
        // Korisnik ciji email pocinje znakom koji Excel tumaci kao formulu
        $lukavi = User::factory()->create(['email' => '=cmd@magacin.test']);
        $id = Artikal::where('sifra', '002001')->value('id');
        $this->actingAs($lukavi)->put("/artikli/{$id}", [
            'naziv' => '=SUM(A1)', 'jedinica_mere' => 'kg', 'kolicina' => '11', 'cena' => '100',
            'datum_nabavke' => '2026-01-02', 'aktivan' => '1',
        ])->assertSessionHasNoErrors();

        $red = $this->csv('?akcija=izmenjen&sifra=002')[0];

        $this->assertSame("'=cmd@magacin.test", $red[3]);
        // Kolona "izmene" pocinje nazivom polja, a JSON zagradom, pa nisu formule
        $this->assertSame('Količina: 10.000 → 11.000', $red[7]);
        $this->assertStringStartsWith('{', $red[9]);
    }

    public function test_link_za_izvoz_nosi_filtere_sa_stranice(): void
    {
        $this->get('/audit-log?akcija=dodat&od=2026-07-01')
            ->assertSee('audit-log/izvoz?akcija=dodat&amp;od=2026-07-01', false);
    }
}
