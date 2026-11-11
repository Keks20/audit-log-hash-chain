<?php

namespace Tests\Feature;

use App\Models\Artikal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtikalKanonskiTest extends TestCase
{
    use RefreshDatabase;

    private const PODACI = [
        'sifra'         => '001001',
        'naziv'         => 'Šećer',
        'jedinica_mere' => 'kg',
        'kolicina'      => '5.5',
        'cena'          => '100',
        'datum_nabavke' => '2026-01-02',
        'aktivan'       => '1',
        'napomena'      => null,
    ];

    public function test_kanonski_json_artikla_ima_stalan_oblik(): void
    {
        $this->assertSame(
            '{"aktivan":true,"cena":"100.00","datum_nabavke":"2026-01-02","jedinica_mere":"kg",'
            . '"kolicina":"5.500","napomena":null,"naziv":"Šećer","sifra":"001001"}',
            (new Artikal(self::PODACI))->kanonskiJson()
        );
    }

    public function test_artikal_iz_baze_daje_isti_json_kao_pre_upisa(): void
    {
        $nov = new Artikal(self::PODACI);
        $json = $nov->kanonskiJson();

        $nov->save();

        $this->assertSame($json, Artikal::findOrFail($nov->id)->kanonskiJson());
    }

    public function test_nepoznate_vrednosti_ostaju_null(): void
    {
        $json = (new Artikal(['kolicina' => null, 'aktivan' => null] + self::PODACI))->kanonskiJson();

        $this->assertStringContainsString('"kolicina":null', $json);
        $this->assertStringContainsString('"aktivan":null', $json);
    }

    public function test_id_i_vremena_ne_ulaze_u_kanonski_oblik(): void
    {
        $a = Artikal::create(self::PODACI);

        $this->assertSame(
            ['aktivan', 'cena', 'datum_nabavke', 'jedinica_mere', 'kolicina', 'napomena', 'naziv', 'sifra'],
            array_keys(json_decode($a->kanonskiJson(), true))
        );
    }
}
