<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Puni tabelu artikli iz database/data/artikli.json.
 * Radi samo nad PRAZNOM tabelom, da nikad ne pregazi izmene iz aplikacije.
 * (Pokrece se pri svakom startu kontejnera preko docker/start.sh.)
 *
 * Fajl artikli.json se pravi komandom: php artisan artikli:izvoz
 */
class ArtikliSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('artikli')->exists()) {
            $this->command->info('Tabela artikli vec ima podatke, seeder se preskace.');
            return;
        }

        $putanja = database_path('data/artikli.json');
        if (! File::exists($putanja)) {
            $this->command->error('Nema fajla database/data/artikli.json.');
            return;
        }

        $artikli = json_decode(File::get($putanja), true, 512, JSON_THROW_ON_ERROR);

        $sada = now();
        $redovi = array_map(fn (array $a) => [
            'sifra'         => $a['sifra'],
            'naziv'         => $a['naziv'],
            'jedinica_mere' => $a['jedinica_mere'],
            'kolicina'      => $a['kolicina'],
            'cena'          => $a['cena'],
            'datum_nabavke' => $a['datum_nabavke'],
            'aktivan'       => $a['aktivan'],
            'napomena'      => $a['napomena'],
            'created_at'    => $sada,
            'updated_at'    => $sada,
        ], $artikli);

        // Jedan INSERT za sve redove: ili udju svi, ili nijedan
        DB::table('artikli')->insert($redovi);

        $this->command->info('Uneto artikala iz JSON-a: ' . count($redovi));
    }
}