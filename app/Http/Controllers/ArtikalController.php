<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtikalRequest;
use App\Models\Artikal;
use App\Models\AuditZapis;
use App\Services\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Svaka izmena artikla i njen zapis u audit logu idu u istoj transakciji:
 * ili se upisu oba, ili nijedno.
 */
class ArtikalController extends Controller
{
    /**
     * Lista artikala, sortirano po sifri. ?q= trazi po sifri ili nazivu.
     */
    public function index(Request $request): View
    {
        // ?q[]=... je niz, a ne tekst: tretira se kao da pretrage nema
        $q = is_string($v = $request->query('q')) ? trim($v) : '';

        // % i _ iz pretrage su obican tekst, ne dzokeri
        $uzorak = '%' . addcslashes($q, '%_\\') . '%';

        $artikli = Artikal::query()
            ->when($q !== '', fn ($upit) => $upit->where(fn ($u) => $u
                ->where('sifra', 'like', $uzorak)
                ->orWhere('naziv', 'like', $uzorak)))
            ->orderBy('sifra')
            ->get();

        return view('artikli.index', compact('artikli', 'q'));
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

    /**
     * Vraca artikal na stanje iz zapisa loga ("posle", a kod zapisa o brisanju "pre").
     *
     * Ide istim putem kao svaka druga izmena, pa i vracanje ostavlja zapis:
     * postojeci artikal se menja ("izmenjen"), a obrisan se dodaje ponovo
     * ("dodat", sa novim id-jem). Log se pri tome samo dopisuje.
     */
    public function vrati(Request $request, AuditZapis $zapis, AuditLog $log): RedirectResponse
    {
        $nazad = redirect()->route('audit-log.index', ['artikal' => $zapis->artikal_id]);

        // Stanje se cita iz loga, pa zapis prvo mora da ima ispravan hes i potpis.
        // Ovo je provera jednog zapisa; ceo lanac proverava "Proveri lanac".
        if (! $log->zapisJeNetaknut($zapis->id)) {
            return $nazad->with('greska', "Zapis #{$zapis->id} ne prolazi proveru (heš ili potpis), stanje iz njega se ne vraća.");
        }

        $stanje = $zapis->posleNiz() ?? $zapis->preNiz();

        [$ishod, $artikal] = DB::transaction(function () use ($request, $zapis, $stanje, $log) {
            $postojeci = Artikal::lockForUpdate()->find($zapis->artikal_id);

            if ($postojeci !== null) {
                $pre = $postojeci->kanonskiPodaci();
                $postojeci->fill(Arr::except($stanje, 'sifra'));

                if (! $postojeci->isDirty()) {
                    return ['isto', $postojeci];
                }

                $postojeci->save();
                $postojeci->refresh();

                $log->zapisi(AuditZapis::IZMENJEN, $postojeci, $pre, $postojeci->kanonskiPodaci(), $request->user());

                return ['vracen', $postojeci];
            }

            // Artikal je obrisan. Sifru je u medjuvremenu mogao da dobije drugi artikal.
            if (Artikal::where('sifra', $stanje['sifra'])->exists()) {
                return ['zauzeta', null];
            }

            $novi = Artikal::create($stanje);
            $novi->refresh();

            $log->zapisi(AuditZapis::DODAT, $novi, null, $novi->kanonskiPodaci(), $request->user());

            return ['dodat', $novi];
        });

        return match ($ishod) {
            'isto'    => $nazad->with('poruka', "Artikal {$artikal->sifra} je već u stanju iz zapisa #{$zapis->id}: nema izmena."),
            'vracen'  => $nazad->with('poruka', "Artikal {$artikal->sifra} je vraćen na stanje iz zapisa #{$zapis->id}."),
            'zauzeta' => $nazad->with('greska', "Šifru {$zapis->sifra} sada ima drugi artikal, pa obrisani artikal ne može da se vrati."),
            'dodat'   => redirect()->route('audit-log.index', ['artikal' => $artikal->id])
                ->with('poruka', "Obrisani artikal {$artikal->sifra} je ponovo dodat sa stanjem iz zapisa #{$zapis->id}."),
        };
    }
}
