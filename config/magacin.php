<?php

return [

    /*
     * Lozinka za dva test naloga iz KorisniciSeeder-a.
     * Samo za lokalni razvoj, kao i lozinka baze u .env.example.
     * Seeder je cita odavde, a ne direktno iz env(), da bi radilo
     * i kad je konfiguracija kesirana (config:cache).
     */
    'demo_lozinka' => env('DEMO_LOZINKA'),

];
