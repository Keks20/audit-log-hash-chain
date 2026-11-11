<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtikalRequest;
use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Services\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Svaka izmena artikla i njen zapis u audit logu idu u istoj transakciji:
 * ili se upisu oba, ili nijedno.
 */
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

    public function store(ArtikalRequest $request, AuditLog $log): RedirectResponse
    {
        $artikal = DB::transaction(function () use ($request, $log) {
            $artikal = Artikal::create($request->validated());

            // U log ide ono sto je stvarno u bazi, ne ono sto je stiglo iz forme
            $artikal->refresh();

            $log->zapisi(AuditZapis::DODAT, $artikal, null, $artikal->kanonskiPodaci(), $request->user());

            return $artikal;
        });

        return redirect()->route('artikli.index')
            ->with('poruka', "Dodat artikal {$artikal->sifra} ({$artikal->naziv}).");
    }

    public function edit(Artikal $artikal): View
    {
        return view('artikli.forma', compact('artikal'));
    }

    public function update(ArtikalRequest $request, Artikal $artikal, AuditLog $log): RedirectResponse
    {
        $sacuvan = DB::transaction(function () use ($request, $artikal, $log) {
            // Ponovo citamo artikal pod kljucem, da "pre" bude stvarno stanje
            // i kad dvoje istovremeno menja isti artikal
            $zakljucan = Artikal::lockForUpdate()->findOrFail($artikal->id);
            $pre = $zakljucan->kanonskiPodaci();

            $zakljucan->fill($request->validated());

            // Cuvanje bez stvarne promene se ne upisuje, pa nema ni praznog zapisa u logu
            if (! $zakljucan->isDirty()) {
                return null;
            }

            $zakljucan->save();
            $zakljucan->refresh();

            $log->zapisi(AuditZapis::IZMENJEN, $zakljucan, $pre, $zakljucan->kanonskiPodaci(), $request->user());

            return $zakljucan;
        });

        if ($sacuvan === null) {
            return redirect()->route('artikli.index')
                ->with('poruka', "Artikal {$artikal->sifra}: nema izmena.");
        }

        return redirect()->route('artikli.index')
            ->with('poruka', "Sačuvan artikal {$sacuvan->sifra} ({$sacuvan->naziv}).");
    }

    /**
     * Pravo brisanje (ne SoftDeletes). Poslednje stanje artikla
     * ostaje zapisano u audit logu ("pre").
     */
    public function destroy(Request $request, Artikal $artikal, AuditLog $log): RedirectResponse
    {
        DB::transaction(function () use ($request, $artikal, $log) {
            $zakljucan = Artikal::lockForUpdate()->findOrFail($artikal->id);
            $pre = $zakljucan->kanonskiPodaci();

            $zakljucan->delete();

            $log->zapisi(AuditZapis::OBRISAN, $zakljucan, $pre, null, $request->user());
        });

        return redirect()->route('artikli.index')
            ->with('poruka', "Obrisan artikal {$artikal->sifra} ({$artikal->naziv}).");
    }
}
