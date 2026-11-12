<?php

namespace Tests\Feature;

use App\Services\PotpisZapisa;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Komanda audit:kljuc. Radi nad privremenim .env fajlom, nikad nad pravim.
 */
class KljucPotpisaTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/env-' . uniqid());
        File::ensureDirectoryExists($this->folder);
        $this->app->useEnvironmentPath($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    private function env(?string $sadrzaj = null): string
    {
        if ($sadrzaj !== null) {
            File::put($this->folder . '/.env', $sadrzaj);
        }

        return File::get($this->folder . '/.env');
    }

    private function kljucIzEnv(): ?string
    {
        return preg_match('/^AUDIT_KLJUC_POTPISA=(.*)$/m', $this->env(), $m) ? $m[1] : null;
    }

    public function test_postojeci_kljuc_se_ne_menja(): void
    {
        $pre = $this->env("APP_NAME=Test\nAUDIT_KLJUC_POTPISA=nesto\n");

        $this->artisan('audit:kljuc')
            ->expectsOutputToContain('Ključ već postoji, nije menjan.')
            ->expectsOutputToContain((new PotpisZapisa())->javniKljuc())
            ->assertSuccessful();

        $this->assertSame($pre, $this->env());
    }

    public function test_pravi_kljuc_u_praznom_redu_i_ne_ispisuje_tajni(): void
    {
        config(['magacin.kljuc_potpisa' => null]);
        $this->env("APP_NAME=Test\nAUDIT_KLJUC_POTPISA=\nDRUGO=1\n");

        $this->artisan('audit:kljuc')
            ->expectsOutputToContain('Ključ za potpis je upisan u .env.')
            ->assertSuccessful();

        $kljuc = $this->kljucIzEnv();
        $this->assertSame(64, strlen(base64_decode($kljuc, true)));
        $this->assertSame("APP_NAME=Test\nAUDIT_KLJUC_POTPISA={$kljuc}\nDRUGO=1\n", $this->env());

        // Isti proces odmah potpisuje novim kljucem
        $this->assertSame($kljuc, config('magacin.kljuc_potpisa'));
        $potpis = new PotpisZapisa();
        $hes = str_repeat('ab', 32);
        $this->assertTrue($potpis->odgovara($hes, $potpis->potpisi($hes), $potpis->javniKljuc()));
    }

    public function test_dopisuje_red_kad_ga_nema(): void
    {
        config(['magacin.kljuc_potpisa' => null]);
        $this->env("APP_NAME=Test\n");

        $this->artisan('audit:kljuc')->assertSuccessful();

        $this->assertSame("APP_NAME=Test\n\nAUDIT_KLJUC_POTPISA=" . $this->kljucIzEnv() . "\n", $this->env());
    }

    public function test_zameni_pravi_nov_kljuc(): void
    {
        $stari = config('magacin.kljuc_potpisa');
        $this->env("AUDIT_KLJUC_POTPISA={$stari}\n");

        $this->artisan('audit:kljuc', ['--zameni' => true])->assertSuccessful();

        $this->assertNotSame($stari, $this->kljucIzEnv());
        $this->assertSame(64, strlen(base64_decode($this->kljucIzEnv(), true)));
    }

    public function test_javni_ispisuje_samo_javni_kljuc(): void
    {
        $this->artisan('audit:kljuc', ['--javni' => true])
            ->expectsOutput((new PotpisZapisa())->javniKljuc())
            ->assertSuccessful();

        config(['magacin.kljuc_potpisa' => null]);

        $this->artisan('audit:kljuc', ['--javni' => true])
            ->expectsOutputToContain('Nema ključa')
            ->assertFailed();
    }

    public function test_bez_env_fajla_javlja_gresku(): void
    {
        config(['magacin.kljuc_potpisa' => null]);

        $this->artisan('audit:kljuc')
            ->expectsOutputToContain('Nema fajla')
            ->assertFailed();
    }
}
