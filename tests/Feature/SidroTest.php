<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\SkladisteSidra;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Cuvanje sidra u fajl van baze. DatabaseMigrations zbog testova u kojima
 * "napadac" skracuje lanac (DROP TRIGGER zatvara transakciju).
 */
class SidroTest extends TestCase
{
    use DatabaseMigrations;

    private AuditLog $log;

    private SkladisteSidra $sidra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = app(AuditLog::class);
        $this->sidra = app(SkladisteSidra::class);

        // Fajl iz phpunit.xml, nikad pravi fajl sidara
        $this->assertStringEndsWith('sidra-test.jsonl', $this->sidra->putanja());
        File::delete($this->sidra->putanja());
    }

    protected function tearDown(): void
    {
        File::delete($this->sidra->putanja());

        parent::tearDown();
    }

    private function dodaj(string $sifra): AuditZapis
    {
        return DB::transaction(function () use ($sifra) {
            $a = Artikal::create([
                'sifra' => $sifra, 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
                'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
            ]);

            return $this->log->zapisi(AuditZapis::DODAT, $a, null, $a->kanonskiPodaci(), null);
        });
    }

    private function skratiLanac(AuditZapis $poslednji, AuditZapis $novaGlava): void
    {
        foreach (['audit_log_bez_brisanja', 'audit_log_glava_samo_napred'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
        }

        DB::table('audit_log')->where('id', $poslednji->id)->delete();
        DB::table('artikli')->where('id', $poslednji->artikal_id)->delete();
        DB::table('audit_log_glava')->where('id', 1)->update([
            'poslednji_id' => $novaGlava->id, 'poslednji_hes' => $novaGlava->hes,
        ]);
    }

    public function test_sacuvaj_dopisuje_sidro_a_isto_sidro_ne_ponavlja(): void
    {
        $this->assertNull($this->sidra->poslednje());
        $prvi = $this->dodaj('001001');

        $this->artisan('audit:provera', ['--sacuvaj' => true])
            ->expectsOutputToContain('Sidro je dopisano u')
            ->assertSuccessful();

        $this->assertSame(['id' => $prvi->id, 'hes' => $prvi->hes], collect($this->sidra->poslednje())->only('id', 'hes')->all());

        $this->artisan('audit:provera', ['--sacuvaj' => true])
            ->expectsOutputToContain('Isto sidro je već poslednje u fajlu')
            ->assertSuccessful();
        $this->assertCount(1, $this->sidra->sva());

        $drugi = $this->dodaj('001002');
        $this->artisan('audit:provera', ['--sacuvaj' => true])->assertSuccessful();

        $this->assertCount(2, $this->sidra->sva());
        $this->assertSame($drugi->hes, $this->sidra->poslednje()['hes']);
    }

    public function test_fajl_sidara_je_jedan_json_po_redu(): void
    {
        $zapis = $this->dodaj('001001');
        $this->travelTo('2026-07-01 10:00:00');
        $this->sidra->sacuvaj($zapis->id, $zapis->hes);

        $this->assertSame(
            '{"vreme":"2026-07-01 10:00:00","id":' . $zapis->id . ',"hes":"' . $zapis->hes . "\"}\n",
            File::get($this->sidra->putanja())
        );
    }

    public function test_pokvaren_red_u_fajlu_se_preskace(): void
    {
        $zapis = $this->dodaj('001001');
        $this->sidra->sacuvaj($zapis->id, $zapis->hes);
        File::append($this->sidra->putanja(), "nije json\n{\"hes\":\"kratko\"}\n\n");

        $this->assertCount(1, $this->sidra->sva());
        $this->assertSame($zapis->hes, $this->sidra->poslednje()['hes']);
    }

    public function test_sidro_poslednje_otkriva_skracen_lanac(): void
    {
        $prvi = $this->dodaj('001001');
        $drugi = $this->dodaj('001002');
        $this->artisan('audit:provera', ['--sacuvaj' => true])->assertSuccessful();

        $this->artisan('audit:provera', ['--sidro' => 'poslednje'])
            ->expectsOutputToContain('Sidro je pronađeno.')
            ->assertSuccessful();

        $this->skratiLanac($drugi, $prvi);

        // Bez sidra skracen lanac izgleda ispravno
        $this->artisan('audit:provera')->assertSuccessful();

        $this->artisan('audit:provera', ['--sidro' => 'poslednje'])
            ->expectsOutputToContain('Sidro nije pronađeno u lancu')
            ->assertFailed();
    }

    public function test_sidro_poslednje_bez_fajla_javlja_gresku(): void
    {
        $this->dodaj('001001');

        $this->artisan('audit:provera', ['--sidro' => 'poslednje'])
            ->expectsOutputToContain('Nema sačuvanog sidra')
            ->assertFailed();
    }

    public function test_sidro_se_ne_cuva_kad_lanac_nije_ispravan_ili_se_artikli_ne_slazu(): void
    {
        $zapis = $this->dodaj('001001');

        // Artikal izmenjen mimo aplikacije: lanac ispravan, stanje ne
        DB::table('artikli')->where('id', $zapis->artikal_id)->update(['naziv' => 'Podmetnut']);
        $this->artisan('audit:provera', ['--sacuvaj' => true])
            ->expectsOutputToContain('Sidro nije sačuvano.')
            ->assertFailed();
        $this->assertNull($this->sidra->poslednje());

        // Pokvaren lanac
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_bez_izmene');
        DB::table('audit_log')->where('id', $zapis->id)->update(['sifra' => '999999']);
        $this->artisan('audit:provera', ['--sacuvaj' => true])
            ->expectsOutputToContain('Sidro nije sačuvano.')
            ->assertFailed();
        $this->assertNull($this->sidra->poslednje());
    }

    public function test_prazan_log_ne_cuva_sidro(): void
    {
        $this->artisan('audit:provera', ['--sacuvaj' => true])
            ->expectsOutputToContain('Audit log je prazan.')
            ->assertSuccessful();

        $this->assertNull($this->sidra->poslednje());
    }

    public function test_stranica_sama_koristi_sacuvano_sidro(): void
    {
        $korisnik = User::factory()->create();
        $prvi = $this->dodaj('001001');
        $drugi = $this->dodaj('001002');
        $this->travelTo('2026-07-01 10:00:00');
        $this->sidra->sacuvaj($drugi->id, $drugi->hes);
        $this->travelBack();

        $this->actingAs($korisnik)->followingRedirects()->get('/audit-log/provera')
            ->assertSee('Lanac je ispravan.')
            ->assertSee("Sačuvano sidro je pronađeno u lancu: zapis #{$drugi->id}")
            ->assertSee('01.07.2026. 12:00:00');

        $this->skratiLanac($drugi, $prvi);

        $this->actingAs($korisnik)->followingRedirects()->get('/audit-log/provera')
            ->assertSee('Lanac NIJE ispravan.')
            ->assertSee('Sidro nije pronađeno u lancu');
    }
}
