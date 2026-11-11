<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Isti podaci uvek daju isti JSON tekst, bajt po bajt. Na tome stoji hes lanac.
 *
 * - kljucevi objekata su sortirani (rekurzivno), pa redosled unosa nije bitan;
 * - redosled elemenata liste se cuva;
 * - dozvoljeni su samo string, int, bool, null i nizovi. Float se odbija,
 *   jer 0.1 + 0.2 nema stabilan zapis; decimale idu kao stringovi ("120.50");
 * - tekst se ne normalizuje (ni razmaci, ni \r\n, ni Unicode), jer bi
 *   svaka normalizacija sakrila stvarnu promenu.
 */
final class KanonskiJson
{
    public static function encode(array $podaci): string
    {
        return json_encode(
            self::sredi($podaci),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private static function sredi(mixed $vrednost, string $putanja = '$'): mixed
    {
        if (is_array($vrednost)) {
            if (! array_is_list($vrednost)) {
                ksort($vrednost, SORT_STRING);
            }

            $sredjeno = [];
            foreach ($vrednost as $kljuc => $v) {
                $sredjeno[$kljuc] = self::sredi($v, $putanja . '.' . $kljuc);
            }

            return $sredjeno;
        }

        if (is_string($vrednost) || is_int($vrednost) || is_bool($vrednost) || $vrednost === null) {
            return $vrednost;
        }

        throw new InvalidArgumentException(
            "Kanonski JSON ne prima tip " . get_debug_type($vrednost) . " ({$putanja}); decimale treba proslediti kao string."
        );
    }
}
