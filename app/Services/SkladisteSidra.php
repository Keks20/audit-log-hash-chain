<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Sidra sacuvana van baze: po jedan JSON red (vreme, id zapisa, hes).
 *
 * Fajl se samo dopisuje. Sidro otkriva ono sto lanac sam ne vidi: zapise
 * obrisane sa kraja uz "popravljenu" glavu i ceo lanac ponovo izracunat.
 * Fajl je na istom racunaru kao aplikacija, pa prava zastita trazi i kopiju
 * negde drugde (drugi server, mejl, papir).
 */
class SkladisteSidra
{
    public function putanja(): string
    {
        return config('magacin.fajl_sidra');
    }

    /**
     * Dopisuje sidro. Isto sidro kao poslednje se ne dopisuje ponovo.
     * Vraca true ako je dopisano.
     */
    public function sacuvaj(int $zapisId, string $hes): bool
    {
        $poslednje = $this->poslednje();

        if ($poslednje !== null && $poslednje['hes'] === $hes) {
            return false;
        }

        File::ensureDirectoryExists(dirname($this->putanja()));

        $red = json_encode([
            'vreme' => now('UTC')->format('Y-m-d H:i:s'),
            'id'    => $zapisId,
            'hes'   => $hes,
        ], JSON_THROW_ON_ERROR);

        File::append($this->putanja(), $red . "\n");

        return true;
    }

    /**
     * Poslednje sacuvano sidro: ['vreme' => ..., 'id' => ..., 'hes' => ...].
     */
    public function poslednje(): ?array
    {
        $sva = $this->sva();

        return $sva === [] ? null : $sva[array_key_last($sva)];
    }

    /**
     * Sva sidra redom. Red koji nije ispravan se preskace.
     *
     * @return array<int, array{vreme: string, id: int, hes: string}>
     */
    public function sva(): array
    {
        if (! File::exists($this->putanja())) {
            return [];
        }

        $sidra = [];

        foreach (preg_split('/\R/', File::get($this->putanja()), -1, PREG_SPLIT_NO_EMPTY) as $red) {
            $s = json_decode($red, true);

            if (is_array($s) && is_string($s['hes'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $s['hes'])) {
                $sidra[] = [
                    'vreme' => (string) ($s['vreme'] ?? ''),
                    'id'    => (int) ($s['id'] ?? 0),
                    'hes'   => $s['hes'],
                ];
            }
        }

        return $sidra;
    }
}
