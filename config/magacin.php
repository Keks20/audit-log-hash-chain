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

    /*
     * Tajni kljuc (Ed25519, base64) kojim se potpisuje svaki zapis audit loga.
     * Pravi ga: php artisan audit:kljuc
     * Stoji samo u .env, van baze: ko ima pristup samo bazi ne moze da dopise
     * ni da ponovo izracuna zapis sa ispravnim potpisom.
     */
    'kljuc_potpisa' => env('AUDIT_KLJUC_POTPISA'),

    /*
     * Fajl u koji "audit:provera --sacuvaj" dopisuje sidra (poslednji hes).
     * Namerno van baze. Jos bolje je da se kopija cuva i van ovog racunara.
     */
    'fajl_sidra' => env('AUDIT_FAJL_SIDRA', storage_path('app/private/sidra.jsonl')),

];
