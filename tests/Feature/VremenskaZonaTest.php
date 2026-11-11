<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VremenskaZonaTest extends TestCase
{
    use RefreshDatabase;

    private function novi(string $datum): array
    {
        return [
            'sifra' => '001038', 'naziv' => 'Pirinač', 'jedinica_mere' => 'kg', 'kolicina' => '1',
            'cena' => '1', 'datum_nabavke' => $datum, 'aktivan' => '1',
        ];
    }

    public function test_aplikacija_cuva_vreme_u_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Europe/Belgrade', config('magacin.zona_prikaza'));
    }

    public function test_danasnji_datum_po_beogradu_prolazi_i_posle_ponoci(): void
    {
        $this->actingAs(User::factory()->create());

        // 23:30 UTC 11. novembra = 00:30 u Beogradu 12. novembra
        $this->travelTo(Carbon::parse('2026-11-11 23:30:00', 'UTC'));

        $this->post('/artikli', $this->novi('2026-11-13'))
            ->assertSessionHasErrors(['datum_nabavke' => 'Datum nabavke ne sme biti u budućnosti.']);

        $this->post('/artikli', $this->novi('2026-11-12'))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/artikli');
    }
}
