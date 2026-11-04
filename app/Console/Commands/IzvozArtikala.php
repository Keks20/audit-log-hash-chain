<?php

namespace App\Console\Commands;

use App\Models\Artikal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Izvozi artikle iz baze u database/data/artikli.json.
 * Taj fajl ide u repo, pa ArtikliSeeder radi i posle kloniranja
 * (bez .dbf-a i bez tabele magacin).
 *
 * Pokretanje: php artisan artikli:izvoz
 */
class IzvozArtikala extends Command
{
    protected $signature = 'artikli:izvoz';

    protected $description = 'Izvozi artikle u database/data/artikli.json (za ArtikliSeeder)';

    public function handle(): int
    {
        // Pravimo niz rucno (ne toArray()), da datum bude "2024-03-05",
        // a ne "2024-03-05T00:00:00.000000Z", i da redosled polja bude uvek isti.
        // Kolicina i cena ostaju stringovi ("120.50"), da se ne izgube decimale.
        $artikli = Artikal::orderBy('sifra')->get()->map(fn (Artikal $a) => [
            'sifra'         => $a->sifra,
            'naziv'         => $a->naziv,
            'jedinica_mere' => $a->jedinica_mere,
            'kolicina'      => $a->kolicina,
            'cena'          => $a->cena,
            'datum_nabavke' => $a->datum_nabavke?->format('Y-m-d'),
            'aktivan'       => $a->aktivan,
            'napomena'      => $a->napomena,
        ]);

        if ($artikli->isEmpty()) {
            $this->error('Tabela artikli je prazna, nema sta da se izveze.');
            return self::FAILURE;
        }

        $putanja = database_path('data/artikli.json');
        File::ensureDirectoryExists(dirname($putanja));

        // UNESCAPED_UNICODE: "Šećer" ostaje citljivo, a ne "\u0160e\u0107er"
        $json = json_encode(
            $artikli,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        File::put($putanja, $json . "\n");

        $this->info('Izvezeno artikala: ' . $artikli->count() . ' -> database/data/artikli.json');

        return self::SUCCESS;
    }
}