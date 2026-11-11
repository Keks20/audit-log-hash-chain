<?php

namespace Tests\Feature;

use App\Models\Artikal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\KorisniciSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KorisniciSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ne oslanjamo se na lokalni .env
        config(['magacin.demo_lozinka' => 'lozinka-za-test']);
    }

    public function test_pravi_dva_naloga_sa_demo_lozinkom(): void
    {
        $this->seed(KorisniciSeeder::class);

        $this->assertSame(2, User::count());

        foreach (['magacioner@magacin.test', 'sef@magacin.test'] as $email) {
            $korisnik = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check('lozinka-za-test', $korisnik->password));
        }
    }

    public function test_drugo_pokretanje_ne_pravi_duplikate_i_ne_vraca_lozinku(): void
    {
        $this->seed(KorisniciSeeder::class);

        User::where('email', 'magacioner@magacin.test')
            ->update(['password' => Hash::make('promenjena-lozinka')]);

        $this->seed(KorisniciSeeder::class);

        $this->assertSame(2, User::count());

        $magacioner = User::where('email', 'magacioner@magacin.test')->firstOrFail();
        $this->assertTrue(Hash::check('promenjena-lozinka', $magacioner->password));
    }

    public function test_bez_demo_lozinke_ne_pravi_naloge(): void
    {
        config(['magacin.demo_lozinka' => null]);

        $this->seed(KorisniciSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_database_seeder_sme_da_se_pokrene_vise_puta(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, User::count());
        $this->assertSame(37, Artikal::count());
    }
}
