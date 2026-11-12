<?php

namespace Tests\Feature;

use App\Console\Commands\NadzorAuditLoga;
use App\Models\Artikal;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\SkladisteSidra;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Automatska provera (audit:nadzor). Kvari se samo tabela artikli, pa je
 * dovoljan RefreshDatabase.
 */
class NadzorTest extends TestCase
{
    use RefreshDatabase;

    private SkladisteSidra $sidra;

    private Artikal $artikal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sidra = app(SkladisteSidra::class);
        File::delete($this->sidra->putanja());

        $this->artikal = Artikal::create([
            'sifra' => '001001', 'naziv' => 'Šećer', 'jedinica_mere' => 'kg',
            'kolicina' => '10', 'cena' => '100', 'datum_nabavke' => '2026-01-02', 'aktivan' => true,
        ]);
        app(AuditLog::class)->pocetniSnimak();
    }

    protected function tearDown(): void
    {
        File::delete($this->sidra->putanja());

        parent::tearDown();
    }

    public function test_uredna_provera_pamti_ishod_i_cuva_sidro(): void
    {
        Log::spy();
        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

        $this->artisan('audit:nadzor')
            ->expectsOutputToContain('Provera je prošla. Zapisa: 1.')
            ->assertSuccessful();

        $this->assertSame(
            ['vreme' => '2026-07-01 10:00:00', 'uRedu' => true, 'brojZapisa' => 1, 'opis' => null],
            Cache::get(NadzorAuditLoga::KLJUC_KESA)
        );
        $this->assertSame(DB::table('audit_log')->value('hes'), $this->sidra->poslednje()['hes']);
        Log::shouldNotHaveReceived('error');

        // Drugo pokretanje koristi sacuvano sidro i ne dopisuje isto ponovo
        $this->artisan('audit:nadzor')->assertSuccessful();
        $this->assertCount(1, $this->sidra->sva());
    }

    public function test_neslaganje_se_pamti_upisuje_u_log_i_ne_cuva_sidro(): void
    {
        Log::spy();
        DB::table('artikli')->where('id', $this->artikal->id)->update(['cena' => '1.00']);

        $this->artisan('audit:nadzor')
            ->expectsOutputToContain('Provera NIJE prošla. Artikli se ne slažu sa logom (1).')
            ->assertFailed();

        $ishod = Cache::get(NadzorAuditLoga::KLJUC_KESA);
        $this->assertFalse($ishod['uRedu']);
        $this->assertSame('Artikli se ne slažu sa logom (1).', $ishod['opis']);
        $this->assertNull($this->sidra->poslednje());

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $poruka, array $kontekst) => str_contains($poruka, 'automatska provera NIJE prošla')
                && str_contains($kontekst['neslaganja'][0], 'polje „Cena“ u tabeli je „1.00“')
        );
    }

    public function test_stranica_prikazuje_ishod_automatske_provere(): void
    {
        Log::spy();
        $this->actingAs(User::factory()->create());

        $this->get('/audit-log')->assertDontSee('Poslednja automatska provera');

        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
        $this->artisan('audit:nadzor')->assertSuccessful();
        $this->travelBack();

        $this->get('/audit-log')
            ->assertSee('Poslednja automatska provera: 01.07.2026. 12:00:00')
            ->assertSee('sve u redu (zapisa: 1).');

        DB::table('artikli')->where('id', $this->artikal->id)->delete();
        $this->artisan('audit:nadzor')->assertFailed();

        $this->get('/audit-log')
            ->assertSee('NIJE prošla.')
            ->assertSee('Artikli se ne slažu sa logom (1).')
            ->assertDontSee('sve u redu');
    }

    public function test_nadzor_je_u_rasporedu_svakog_sata(): void
    {
        $dogadjaji = collect(app(Schedule::class)->events())
            ->filter(fn ($d) => str_contains($d->command, 'audit:nadzor'));

        $this->assertCount(1, $dogadjaji);
        $this->assertSame('0 * * * *', $dogadjaji->first()->expression);
    }
}
