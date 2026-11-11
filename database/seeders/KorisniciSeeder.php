<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dva test naloga za prijavu: magacioner i sef.
 * Lozinka je DEMO_LOZINKA iz .env (preko config/magacin.php).
 *
 * Moze da se pokrene vise puta: postojeci nalog (isti email) se preskace,
 * pa se lozinka promenjena kasnije nikad ne vraca na pocetnu.
 */
class KorisniciSeeder extends Seeder
{
    private const NALOZI = [
        ['name' => 'Marko Marković', 'email' => 'magacioner@magacin.test'],
        ['name' => 'Jelena Jovanović', 'email' => 'sef@magacin.test'],
    ];

    public function run(): void
    {
        $lozinka = config('magacin.demo_lozinka');

        // Bez lozinke ne pravimo naloge, ali ni ne prekidamo start kontejnera
        if (blank($lozinka)) {
            $this->command->error('Nema DEMO_LOZINKA u .env, test nalozi nisu napravljeni.');
            return;
        }

        $novih = 0;

        foreach (self::NALOZI as $nalog) {
            // "password" se hesira sam, zbog casta "hashed" u modelu User
            $korisnik = User::firstOrCreate(
                ['email' => $nalog['email']],
                ['name' => $nalog['name'], 'password' => $lozinka]
            );

            if ($korisnik->wasRecentlyCreated) {
                $novih++;
            }
        }

        $this->command->info('Test nalozi: novih ' . $novih . ', vec postoji ' . (count(self::NALOZI) - $novih) . '.');
    }
}
