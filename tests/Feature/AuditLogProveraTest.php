<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\PotpisZapisa;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Testovi u kojima neko mimo aplikacije kvari log.
 *
 * DatabaseMigrations umesto RefreshDatabase: "napadac" prvo brise trigere
 * (DROP TRIGGER), a DDL u MariaDB zatvara transakciju, pa test ne sme da
 * radi unutar transakcije. Baza se pravi iznova za svaki test.
 */
class AuditLogProveraTest extends TestCase
{
    use DatabaseMigrations;

    private AuditLog $log;

    private User $korisnik;

    /** @var int[] id-jevi zapisa redom */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = app(AuditLog::class);
    }

    // Lanac od 4 zapisa za isti artikal: dodat, dve izmene, obrisan
    private function napraviLanac(): void
    {
        $this->korisnik = User::factory()->create(['email' => 'magacioner@magacin.test']);
        $a = Artikal::create([
            'sifra' => '001001', 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);

        DB::transaction(function () use ($a) {
            $this->log->zapisi(AuditZapis::DODAT, $a, null, $a->kanonskiPodaci(), $this->korisnik);

            foreach (['Šećer kristal', 'Šećer u kocki'] as $naziv) {
                $pre = $a->kanonskiPodaci();
                $a->update(['naziv' => $naziv]);
                $this->log->zapisi(AuditZapis::IZMENJEN, $a, $pre, $a->kanonskiPodaci(), $this->korisnik);
            }

            $pre = $a->kanonskiPodaci();
            $a->delete();
            $this->log->zapisi(AuditZapis::OBRISAN, $a, $pre, null, $this->korisnik);
        });

        $this->id = AuditZapis::orderBy('id')->pluck('id')->all();
    }

    // Napadac sa pravima nad semom prvo uklanja trigere
    private function ukloniTrigere(): void
    {
        foreach (['audit_log_bez_izmene', 'audit_log_bez_brisanja', 'audit_log_glava_bez_brisanja', 'audit_log_glava_samo_napred'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
        }
    }

    private function izmeniUBazi(int $id, array $kolone): void
    {
        DB::table('audit_log')->where('id', $id)->update($kolone);
    }

    private function postaviGlavu(int $id): void
    {
        DB::table('audit_log_glava')->where('id', 1)->update([
            'poslednji_id'  => $id,
            'poslednji_hes' => DB::table('audit_log')->where('id', $id)->value('hes'),
        ]);
    }

    private function zameniUPosle(int $id, string $staro, string $novo): void
    {
        $posle = DB::table('audit_log')->where('id', $id)->value('posle');
        $this->izmeniUBazi($id, ['posle' => str_replace($staro, $novo, $posle)]);
    }

    // --- osnovno ---

    public function test_prazan_log_je_ispravan(): void
    {
        $r = $this->log->proveri();

        $this->assertTrue($r->ispravan);
        $this->assertSame(0, $r->brojZapisa);
        $this->assertNull($r->poslednjiHes);
    }

    public function test_netaknut_lanac_je_ispravan(): void
    {
        $this->napraviLanac();

        $r = $this->log->proveri();

        $this->assertTrue($r->ispravan);
        $this->assertSame(4, $r->brojZapisa);
        $this->assertSame($this->id[3], $r->poslednjiId);
        $this->assertSame(AuditZapis::findOrFail($this->id[3])->hes, $r->poslednjiHes);
        $this->assertSame([], $r->tragovi);
    }

    public function test_glava_prati_poslednji_zapis(): void
    {
        $this->napraviLanac();

        $glava = DB::table('audit_log_glava')->first();
        $this->assertSame($this->id[3], (int) $glava->poslednji_id);
        $this->assertSame(AuditZapis::findOrFail($this->id[3])->hes, $glava->poslednji_hes);
    }

    // --- izmenjen sadrzaj, sa tragovima ---

    public function test_izmenjeno_stanje_pokazuje_polje_i_vrednosti(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->zameniUPosle($this->id[1], 'Šećer kristal', 'Šećer lažni');

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($this->id[1], $r->losZapisId);
        $this->assertStringContainsString('Sadržaj zapisa je izmenjen', $r->poruka);
        $this->assertContains(
            "Polje hes je originalno (potvrđuje ga sledeći zapis #{$this->id[2]}), dakle promenjeni su podaci zapisa.",
            $r->tragovi
        );
        $this->assertContains(
            "Stanje posle: polje „Naziv“ je „Šećer lažni“, a sledeći zapis #{$this->id[2]} za isti artikal kaže „Šećer kristal“.",
            $r->tragovi
        );
    }

    public function test_izmenjeno_vreme_se_otkriva(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->izmeniUBazi($this->id[2], ['vreme' => '2020-01-01 00:00:00']);

        $r = $this->log->proveri();
        $this->assertSame($this->id[2], $r->losZapisId);
        $this->assertStringContainsString("je pre vremena prethodnog zapisa #{$this->id[1]}", implode("\n", $r->tragovi));
    }

    public function test_izmenjen_korisnik_se_otkriva(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->izmeniUBazi($this->id[0], ['korisnik_email' => 'sef@magacin.test']);

        $r = $this->log->proveri();
        $this->assertSame($this->id[0], $r->losZapisId);
        $this->assertContains(
            "Korisnik #{$this->korisnik->id} ima email „magacioner@magacin.test“, a u zapisu piše „sef@magacin.test“.",
            $r->tragovi
        );
    }

    public function test_izmenjena_sifra_se_otkriva(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->izmeniUBazi($this->id[2], ['sifra' => '999999']);

        $this->assertContains(
            'Polje sifra je „999999“, a u stanju artikla piše „001001“.',
            $this->log->proveri()->tragovi
        );
    }

    public function test_podmetnut_hes_a_netaknuti_podaci(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->izmeniUBazi($this->id[2], ['hes' => str_repeat('a', 64)]);

        $r = $this->log->proveri();
        $this->assertSame($this->id[2], $r->losZapisId);
        $this->assertStringContainsString('Podaci zapisa su netaknuti: promenjeno je samo polje hes', $r->tragovi[0]);
    }

    public function test_bez_unakrsne_veze_kaze_da_ne_zna_polje(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->izmeniUBazi($this->id[1], ['artikal_id' => 999]);

        $tragovi = implode("\n", $this->log->proveri()->tragovi);
        $this->assertStringContainsString('Nijedna unakrsna provera ne pokazuje koje polje je promenjeno', $tragovi);
    }

    // --- obrisani i premesteni zapisi ---

    public function test_obrisan_zapis_iz_sredine_pokazuje_sta_je_nestalo(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        DB::table('audit_log')->where('id', $this->id[1])->delete();

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($this->id[2], $r->losZapisId);
        $this->assertStringContainsString('Veza sa prethodnim zapisom je prekinuta', $r->poruka);

        $tragovi = implode("\n", $r->tragovi);
        $this->assertStringContainsString('ne postoji: obrisan je.', $tragovi);
        $this->assertStringContainsString("Između zapisa #{$this->id[0]} i #{$this->id[2]} nedostaje 1 id", $tragovi);
        $this->assertStringContainsString('„Naziv“ „Šećer“ → „Šećer kristal“ (verovatno sadržaj obrisanog zapisa)', $tragovi);
    }

    public function test_obrisan_prvi_zapis_se_otkriva(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        DB::table('audit_log')->where('id', $this->id[0])->delete();

        $r = $this->log->proveri();
        $this->assertSame($this->id[1], $r->losZapisId);
        $this->assertStringContainsString('obrisan je sa početka lanca', $r->tragovi[0]);
    }

    public function test_obrisan_poslednji_zapis_otkriva_glava(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        DB::table('audit_log')->where('id', $this->id[3])->delete();

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertStringContainsString('Glava lanca se ne slaže', $r->poruka);
        $this->assertSame(
            ["Glava pokazuje na zapis #{$this->id[3]}, a poslednji postojeći je #{$this->id[2]}: zapisi posle #{$this->id[2]} su obrisani."],
            $r->tragovi
        );
    }

    // --- ono sto otkriva tek sidro ---

    public function test_skracen_lanac_sa_popravljenom_glavom_otkriva_tek_sidro(): void
    {
        $this->napraviLanac();
        $sidro = $this->log->proveri()->poslednjiHes;
        $this->ukloniTrigere();

        DB::table('audit_log')->where('id', $this->id[3])->delete();
        $this->postaviGlavu($this->id[2]);

        // Ostatak je i dalje ispravan lanac: bez sidra se ne vidi
        $this->assertTrue($this->log->proveri()->ispravan);

        $r = $this->log->proveri($sidro);
        $this->assertFalse($r->ispravan);
        $this->assertStringContainsString('Sidro nije pronađeno', $r->poruka);
    }

    // Napadac menja zapis i ponovo racuna heseve svih zapisa posle njega.
    // Sa tajnim kljucem (pristup i serveru, ne samo bazi) ih i ponovo potpisuje.
    private function ponovoIzracunajLanac(bool $saKljucem): void
    {
        $this->zameniUPosle($this->id[1], 'Šećer kristal', 'Šećer lažni');

        $prethodni = DB::table('audit_log')->where('id', $this->id[0])->value('hes');
        foreach (array_slice($this->id, 1) as $id) {
            $red = (array) DB::table('audit_log')->where('id', $id)->first();
            $red['prethodni_hes'] = $prethodni;
            $red['hes'] = AuditLog::hes($red);

            $kolone = ['prethodni_hes' => $red['prethodni_hes'], 'hes' => $red['hes']];
            if ($saKljucem) {
                $kolone['potpis'] = (new PotpisZapisa())->potpisi($red['hes']);
            }

            $this->izmeniUBazi($id, $kolone);
            $prethodni = $red['hes'];
        }
        $this->postaviGlavu($this->id[3]);
    }

    public function test_ponovo_izracunat_lanac_bez_kljuca_otkriva_potpis(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        $this->ponovoIzracunajLanac(saKljucem: false);

        // Hesevi i glava se slazu, ali stari potpis ne odgovara novom hesu
        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame($this->id[1], $r->losZapisId);
        $this->assertStringContainsString('Potpis zapisa ne odgovara ključu', $r->poruka);
    }

    public function test_ponovo_izracunat_i_potpisan_lanac_otkriva_tek_sidro(): void
    {
        $this->napraviLanac();
        $sidro = $this->log->proveri()->poslednjiHes;
        $this->ukloniTrigere();

        $this->ponovoIzracunajLanac(saKljucem: true);

        // Ogranicenje: ko ima i bazu i kljuc pravi lanac koji izgleda ispravno
        $this->assertTrue($this->log->proveri()->ispravan);

        // Sidro sacuvano ranije to otkriva
        $this->assertFalse($this->log->proveri($sidro)->ispravan);
    }

    public function test_sidro_iz_sredine_ispravnog_lanca_prolazi(): void
    {
        $this->napraviLanac();

        $sidro = AuditZapis::findOrFail($this->id[1])->hes;

        $this->assertTrue($this->log->proveri($sidro)->ispravan);
    }

    public function test_bez_glave_nema_upisa_ni_ispravne_provere(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();

        DB::table('audit_log_glava')->delete();

        $r = $this->log->proveri();
        $this->assertFalse($r->ispravan);
        $this->assertSame(['Red glave (audit_log_glava, id 1) ne postoji.'], $r->tragovi);

        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->log->zapisi(
            AuditZapis::DODAT, new Artikal(['sifra' => '001099']), null, [], null
        ));
    }

    // --- komanda i stranica ---

    public function test_komanda_za_ispravan_lanac(): void
    {
        $this->napraviLanac();
        $sidro = $this->log->proveri()->poslednjiHes;

        $this->artisan('audit:provera')
            ->expectsOutputToContain('Lanac je ispravan. Zapisa: 4.')
            ->expectsOutputToContain($sidro)
            ->assertSuccessful();

        $this->artisan('audit:provera', ['--sidro' => strtoupper($sidro)])
            ->expectsOutputToContain('Sidro je pronađeno.')
            ->assertSuccessful();
    }

    public function test_komanda_za_pokvaren_lanac_ispisuje_tragove(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();
        $this->izmeniUBazi($this->id[2], ['sifra' => '999999']);

        $this->artisan('audit:provera')
            ->expectsOutputToContain("Lanac NIJE ispravan. Zapis #{$this->id[2]}.")
            ->expectsOutputToContain('Tragovi:')
            ->expectsOutputToContain('Polje sifra je „999999“')
            ->assertFailed();
    }

    public function test_komanda_za_prazan_log(): void
    {
        $this->artisan('audit:provera')
            ->expectsOutputToContain('Audit log je prazan.')
            ->assertSuccessful();
    }

    public function test_stranica_oznacava_los_zapis_i_prikazuje_tragove(): void
    {
        $this->napraviLanac();
        $this->ukloniTrigere();
        $this->izmeniUBazi($this->id[0], ['korisnik_email' => 'sef@magacin.test']);

        $this->actingAs($this->korisnik)
            ->followingRedirects()
            ->get('/audit-log/provera')
            ->assertSee('Lanac NIJE ispravan.')
            ->assertSee("Zapis #{$this->id[0]}:")
            ->assertSee('Šta se ne slaže:')
            ->assertSee('a u zapisu piše „sef@magacin.test“', false)
            ->assertSee('table-danger');
    }
}
