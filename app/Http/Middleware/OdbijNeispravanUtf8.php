<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Odbija zahtev (400) ako putanja, parametri iz adrese ili tela, ili njihovi
 * kljucevi nisu ispravan UTF-8. Pregledac uvek salje UTF-8, pa ovo pogadja
 * samo alate koji salju npr. cp1250.
 *
 * Stoji na pocetku web grupe, pre StartSession, jer takav zahtev ne sme da
 * stigne do sesije: sesija se cuva kao JSON, json_encode na neispravnom UTF-8
 * vraca false i sesija se upise prazna (nestaje i prijava). Bez ove provere
 * tekst bi stigao i do baze, koja ga odbija greskom 500.
 *
 * Putanju vec proverava Laravelov globalni ValidatePathEncoding (isto 400),
 * pre web grupe; ovde je ista provera samo za slucaj da se on iskljuci.
 */
class OdbijNeispravanUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! mb_check_encoding($request->decodedPath(), 'UTF-8')
            || ! self::ispravno($request->query->all())
            || ! self::ispravno($request->request->all())) {
            return response('Neispravan zahtev: tekst nije u UTF-8 kodiranju.', 400)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return $next($request);
    }

    private static function ispravno(array $podaci): bool
    {
        foreach ($podaci as $kljuc => $vrednost) {
            if (is_string($kljuc) && ! mb_check_encoding($kljuc, 'UTF-8')) {
                return false;
            }

            if (is_array($vrednost) && ! self::ispravno($vrednost)) {
                return false;
            }

            if (is_string($vrednost) && ! mb_check_encoding($vrednost, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }
}
