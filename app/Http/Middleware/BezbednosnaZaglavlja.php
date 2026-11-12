<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zaglavlja koja pregledacu zabranjuju ono sto aplikaciji ne treba:
 * prikaz u tudjem okviru (clickjacking), pogadjanje tipa sadrzaja i
 * slanje pune adrese drugim sajtovima.
 *
 * Content-Security-Policy namerno nije ovde: brisanje koristi onsubmit u
 * samom HTML-u, a politika koja to dozvoljava ('unsafe-inline') ne bi stitila.
 */
class BezbednosnaZaglavlja
{
    public function handle(Request $request, Closure $next): Response
    {
        $odgovor = $next($request);

        $odgovor->headers->set('X-Frame-Options', 'DENY');
        $odgovor->headers->set('X-Content-Type-Options', 'nosniff');
        $odgovor->headers->set('Referrer-Policy', 'same-origin');

        return $odgovor;
    }
}
