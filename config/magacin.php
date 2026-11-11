<?php

return [

    /*
     * Lozinka za dva test naloga iz KorisniciSeeder-a.
     * Samo za lokalni razvoj, kao i lozinka baze u .env.example.
     * Seeder je cita odavde, a ne direktno iz env(), da bi radilo
     * i kad je konfiguracija kesirana (config:cache).
     */
    'demo_lozinka' => env('DEMO_LOZINKA'),

    /*
     * Sve se cuva u UTC (app.timezone), jer vreme ulazi u hes audit loga.
     * Ova zona se koristi samo za prikaz i za "danas" pri unosu datuma.
     */
    'zona_prikaza' => 'Europe/Belgrade',

];
