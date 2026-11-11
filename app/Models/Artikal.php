<?php

namespace App\Models;

use App\Support\KanonskiJson;
use Illuminate\Database\Eloquent\Model;

/**
 * Artikal iz ciste tabele "artikli".
 * Sirovi podaci iz starog programa ostaju u tabeli "magacin".
 */
class Artikal extends Model
{
    // Laravel bi sam trazio "artikals", zato navodimo tacno ime
    protected $table = 'artikli';

    // Jedinice mere iz starog programa (samo ove postoje u podacima)
    public const JEDINICE_MERE = ['kg', 'l', 'kom'];

    protected $fillable = [
        'sifra',
        'naziv',
        'jedinica_mere',
        'kolicina',
        'cena',
        'datum_nabavke',
        'aktivan',
        'napomena',
    ];

    /**
     * Vrednosti uvek u istom obliku (npr. cena uvek "120.50", ne "120.5").
     * To je osnova za kanonski JSON u fazi 2 (hes lanac).
     * NULL ostaje NULL: nepoznata kolicina ili "?" za aktivan.
     */
    protected function casts(): array
    {
        return [
            'kolicina'      => 'decimal:3',
            'cena'          => 'decimal:2',
            'datum_nabavke' => 'date',
            'aktivan'       => 'boolean',
        ];
    }

    /**
     * Stanje artikla koje ide u audit log: samo poslovna polja, bez id-a
     * i vremena (created_at/updated_at se menjaju i bez stvarne izmene).
     * Tipovi su stalni: decimale kao string, datum "Y-m-d", aktivan bool|null.
     */
    public function kanonskiPodaci(): array
    {
        return [
            'sifra'         => $this->sifra,
            'naziv'         => $this->naziv,
            'jedinica_mere' => $this->jedinica_mere,
            'kolicina'      => $this->kolicina,
            'cena'          => $this->cena,
            'datum_nabavke' => $this->datum_nabavke?->format('Y-m-d'),
            'aktivan'       => $this->aktivan,
            'napomena'      => $this->napomena,
        ];
    }

    public function kanonskiJson(): string
    {
        return KanonskiJson::encode($this->kanonskiPodaci());
    }
}