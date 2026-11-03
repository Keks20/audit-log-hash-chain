<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prebacuje artikle iz sirove tabele "magacin" (uvezene iz magacin.dbf)
 * u cistu tabelu "artikli". Zapisi obrisani u starom programu se preskacu.
 *
 * Pokretanje: php artisan db:seed --class=ArtikliIzMagacinaSeeder
 * Moze da se pokrene vise puta: postojeci artikal (ista sifra) se azurira.
 */
class ArtikliIzMagacinaSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('magacin')) {
            $this->command->error('Tabela magacin ne postoji. Prvo uvezi magacin.dbf (dbf_u_mysql.py).');
            return;
        }

        $redovi = DB::table('magacin')
            ->where('_obrisan', 0)
            ->orderBy('_red')
            ->get();

        // Sve ili nista: ako jedan red pukne, nijedan se ne upisuje
        DB::transaction(function () use ($redovi) {
            foreach ($redovi as $r) {
                DB::table('artikli')->updateOrInsert(
                    ['sifra' => trim($r->SIFRA)],
                    [
                        'naziv'         => trim($r->NAZIV),
                        'jedinica_mere' => trim($r->JM),
                        'kolicina'      => $r->KOLICINA,
                        'cena'          => $r->CENA,
                        'datum_nabavke' => $r->DAT_NAB,
                        'aktivan'       => $r->AKTIVAN,
                        'napomena'      => $r->NAPOMENA,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]
                );
            }
        });

        $this->command->info('Prebaceno artikala: ' . $redovi->count());
    }
}