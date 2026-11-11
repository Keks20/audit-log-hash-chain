<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Prijava i odjava korisnika.
 * Rucno preko Auth::attempt(), bez starter kita (oni traze npm i Vite).
 */
class PrijavaController extends Controller
{
    // Posle ovoliko neuspesnih pokusaja prijava se blokira na minut
    private const MAKS_POKUSAJA = 5;

    private const BLOKADA_SEKUNDI = 60;

    public function create(): View
    {
        return view('prijava');
    }

    public function store(Request $request): RedirectResponse
    {
        // Polje se zove "password" (kao kolona u users): Laravel ga zato
        // nikad ne vraca u formu preko sesije posle greske
        $podaci = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Pokusaje brojimo po email-u i IP adresi
        $kljuc = Str::transliterate(Str::lower($podaci['email'])) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($kljuc, self::MAKS_POKUSAJA)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($kljuc)]),
            ]);
        }

        if (! Auth::attempt($podaci)) {
            RateLimiter::hit($kljuc, self::BLOKADA_SEKUNDI);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($kljuc);

        // Nova sesija posle prijave (zastita od fiksiranja sesije)
        $request->session()->regenerate();

        // intended: nazad na stranicu koju je korisnik hteo pre prijave
        return redirect()->intended(route('artikli.index'))
            ->with('poruka', 'Uspešna prijava: ' . Auth::user()->name . '.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('prijava')
            ->with('poruka', 'Uspešna odjava.');
    }
}
