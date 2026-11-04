<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Pokrece se sa: php artisan db:seed
     * Svaki seeder ovde mora smeti da se pokrene vise puta.
     *
     * ArtikliIzMagacinaSeeder NIJE ovde: on treba tabelu magacin (uvoz iz .dbf-a),
     * koje nema posle kloniranja. On ostaje kao zapis kako su podaci nastali.
     */
    public function run(): void
    {
        $this->call(ArtikliSeeder::class);
    }
}