<?php

namespace App\Http\Controllers;

use App\Models\AuditZapis;
use App\Services\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Zapisi loga, najnoviji prvi. ?artikal=ID prikazuje istoriju jednog artikla
     * (radi i za obrisan artikal, jer log cuva artikal_id i sifru).
     */
    public function index(Request $request): View
    {
        $artikalId = $request->integer('artikal') ?: null;

        $zapisi = AuditZapis::query()
            ->when($artikalId, fn ($upit) => $upit->where('artikal_id', $artikalId))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('audit-log.index', compact('zapisi', 'artikalId'));
    }

    /**
     * Proverava ceo lanac i vraca rezultat na listu.
     * Samo cita, zato je GET.
     */
    public function provera(Request $request, AuditLog $log): RedirectResponse
    {
        return redirect()->route('audit-log.index', $request->only('artikal'))
            ->with('provera', $log->proveri()->toArray());
    }
}
