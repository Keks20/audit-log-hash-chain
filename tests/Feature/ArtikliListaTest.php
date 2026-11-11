<?php

namespace Tests\Feature;

use Database\Seeders\ArtikliSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtikliListaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pocetna_strana_vodi_na_listu_artikala(): void
    {
        $this->get('/')->assertRedirect('/artikli');
    }

    public function test_lista_prikazuje_sve_artikle_iz_seedera(): void
    {
        $this->seed(ArtikliSeeder::class);

        $this->get('/artikli')
            ->assertOk()
            ->assertSee('Ukupno: 37')
            ->assertSee('Šećer kristal');
    }

    public function test_prazna_tabela_prikazuje_uputstvo(): void
    {
        $this->get('/artikli')
            ->assertOk()
            ->assertSee('Ukupno: 0')
            ->assertSee('php artisan db:seed');
    }
}
