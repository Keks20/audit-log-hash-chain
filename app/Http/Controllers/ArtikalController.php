<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtikalRequest;
use App\Models\Artikal;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ArtikalController extends Controller
{
    /**
     * Lista svih artikala, sortirano po sifri.
     */
    public function index(): View
    {
        $artikli = Artikal::orderBy('sifra')->get();

        return view('artikli.index', compact('artikli'));
    }

    /**
     * Forma za novi artikal (ista forma kao za izmenu).
     */
    public function create(): View
    {
        $artikal = new Artikal(['jedinica_mere' => 'kom', 'aktivan' => true]);

        return view('artikli.forma', compact('artikal'));
    }

    public function store(ArtikalRequest $request): RedirectResponse
    {
        $artikal = Artikal::create($request->validated());

        return redirect()->route('artikli.index')
            ->with('poruka', "Dodat artikal {$artikal->sifra} ({$artikal->naziv}).");
    }

    public function edit(Artikal $artikal): View
    {
        return view('artikli.forma', compact('artikal'));
    }

    public function update(ArtikalRequest $request, Artikal $artikal): RedirectResponse
    {
        $artikal->fill($request->validated());

        // Cuvanje bez stvarne promene se ne upisuje.
        // U fazi 2 to znaci i: nema praznog zapisa u audit logu.
        if (! $artikal->isDirty()) {
            return redirect()->route('artikli.index')
                ->with('poruka', "Artikal {$artikal->sifra}: nema izmena.");
        }

        $artikal->save();

        return redirect()->route('artikli.index')
            ->with('poruka', "Sačuvan artikal {$artikal->sifra} ({$artikal->naziv}).");
    }

    /**
     * Pravo brisanje (ne SoftDeletes). Poslednje stanje artikla
     * u fazi 2 ostaje zapisano u audit logu.
     */
    public function destroy(Artikal $artikal): RedirectResponse
    {
        $artikal->delete();

        return redirect()->route('artikli.index')
            ->with('poruka', "Obrisan artikal {$artikal->sifra} ({$artikal->naziv}).");
    }
}
