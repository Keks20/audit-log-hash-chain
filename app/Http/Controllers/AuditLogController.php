<?php

namespace App\Http\Controllers;

use App\Console\Commands\NadzorAuditLoga;
use App\Models\AuditZapis;
use App\Services\AuditLog;
use App\Services\SkladisteSidra;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    private const AKCIJE = [AuditZapis::POCETNO, AuditZapis::DODAT, AuditZapis::IZMENJEN, AuditZapis::OBRISAN];

    private const NAZIVI_POLJA = [
        'sifra'         => 'Šifra',
        'naziv'         => 'Naziv',
        'jedinica_mere' => 'JM',
        'kolicina'      => 'Količina',
        'cena'          => 'Cena',
        'datum_nabavke' => 'Datum nabavke',
        'aktivan'       => 'Aktivan',
        'napomena'      => 'Napomena',
    ];

    /**
     * Zapisi loga, najnoviji prvi. ?artikal=ID prikazuje istoriju jednog artikla
     * (radi i za obrisan artikal, jer log cuva artikal_id i sifru).
     * Ostali filteri: akcija, korisnik (email), sifra, od i do (dan po Beogradu).
     */
    public function index(Request $request): View
    {
        $filteri = $this->filteri($request);

        $zapisi = $this->upit($filteri)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('audit-log.index', [
            'zapisi'    => $zapisi,
            'filteri'   => $filteri,
            'artikalId' => $filteri['artikal'],
            'akcije'    => self::AKCIJE,
            'korisnici' => DB::table('audit_log')->whereNotNull('korisnik_email')->distinct()->orderBy('korisnik_email')->pluck('korisnik_email'),
            'nadzor'    => Cache::get(NadzorAuditLoga::KLJUC_KESA),
        ]);
    }

    /**
     * Proverava ceo lanac i vraca rezultat na listu.
     * Samo cita, zato je GET.
     *
     * Ako postoji sidro sacuvano u fajlu (audit:provera --sacuvaj), provera
     * trazi i njega, pa otkriva i skracen ili ponovo izracunat lanac.
     */
    public function provera(Request $request, AuditLog $log, SkladisteSidra $sidra): RedirectResponse
    {
        $sidro = $sidra->poslednje();

        return redirect()->route('audit-log.index', $request->only('artikal'))
            ->with('provera', $log->proveri($sidro['hes'] ?? null)->toArray() + ['sidro' => $sidro]);
    }

    /**
     * Isti zapisi kao na listi (isti filteri), kao CSV za Excel: redom kojim su
     * nastali, sa hesevima i potpisima, da revizor moze da ih proveri i van aplikacije.
     */
    public function izvoz(Request $request): StreamedResponse
    {
        $upit = $this->upit($this->filteri($request));
        $zona = config('magacin.zona_prikaza');

        return response()->streamDownload(function () use ($upit, $zona) {
            $izlaz = fopen('php://output', 'w');

            // BOM: bez njega Excel nasa slova cita pogresno
            fwrite($izlaz, "\xEF\xBB\xBF");

            $red = fn (array $polja) => fputcsv($izlaz, $polja, ';', '"', '');

            $red(['id', 'vreme_utc', 'vreme_beograd', 'korisnik', 'akcija', 'sifra', 'artikal_id', 'izmene',
                'pre', 'posle', 'prethodni_hes', 'hes', 'potpis']);

            foreach ($upit->lazyById(500) as $z) {
                $red([
                    $z->id,
                    $z->vreme->format('Y-m-d H:i:s'),
                    $z->vreme->copy()->timezone($zona)->format('Y-m-d H:i:s'),
                    self::bezFormule($z->korisnik_email),
                    $z->akcija,
                    $z->sifra,
                    $z->artikal_id,
                    self::bezFormule($this->izmeneTekst($z)),
                    $z->pre,
                    $z->posle,
                    $z->prethodni_hes,
                    $z->hes,
                    $z->potpis,
                ]);
            }

            fclose($izlaz);
        }, 'audit-log-' . now($zona)->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Filteri iz adrese. Neispravna vrednost se ignorise (kao da nije zadata),
     * jer su ovo linkovi koje korisnik moze i rucno da izmeni.
     */
    private function filteri(Request $request): array
    {
        $tekst = fn (string $ime) => is_string($v = $request->query($ime)) && trim($v) !== '' ? trim($v) : null;

        $akcija = $tekst('akcija');
        $sifra = $tekst('sifra');

        return [
            'artikal'  => $request->integer('artikal') ?: null,
            'akcija'   => in_array($akcija, self::AKCIJE, true) ? $akcija : null,
            'korisnik' => $tekst('korisnik'),
            'sifra'    => $sifra !== null && preg_match('/^\d{1,6}$/', $sifra) ? $sifra : null,
            'od'       => self::dan($tekst('od')),
            'do'       => self::dan($tekst('do')),
        ];
    }

    private function upit(array $f): Builder
    {
        $zona = config('magacin.zona_prikaza');

        // Dan je po Beogradu, a vreme u bazi je UTC
        $granica = fn (string $dan, bool $kraj) => ($kraj
            ? Carbon::parse($dan, $zona)->endOfDay()
            : Carbon::parse($dan, $zona)->startOfDay())->utc()->format('Y-m-d H:i:s');

        return AuditZapis::query()
            ->when($f['artikal'], fn ($u, $id) => $u->where('artikal_id', $id))
            ->when($f['akcija'], fn ($u, $a) => $u->where('akcija', $a))
            ->when($f['korisnik'], fn ($u, $email) => $u->where('korisnik_email', $email))
            ->when($f['sifra'], fn ($u, $s) => $u->where('sifra', 'like', $s . '%'))
            ->when($f['od'], fn ($u, $dan) => $u->where('vreme', '>=', $granica($dan, false)))
            ->when($f['do'], fn ($u, $dan) => $u->where('vreme', '<=', $granica($dan, true)));
    }

    /**
     * "2026-11-12" ili null ako nije ispravan datum u tom obliku.
     */
    private static function dan(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        try {
            $d = Carbon::createFromFormat('!Y-m-d', $v);
        } catch (InvalidFormatException) {
            return null;
        }

        return $d !== null && $d->format('Y-m-d') === $v ? $v : null;
    }

    private function izmeneTekst(AuditZapis $z): string
    {
        $v = fn ($x) => match (true) {
            $x === null  => '—',
            $x === true  => 'da',
            $x === false => 'ne',
            default      => (string) $x,
        };

        $delovi = [];
        foreach ($z->izmene() as $polje => [$staro, $novo]) {
            $naziv = self::NAZIVI_POLJA[$polje] ?? $polje;

            $delovi[] = match ($z->akcija) {
                AuditZapis::IZMENJEN => "{$naziv}: {$v($staro)} → {$v($novo)}",
                AuditZapis::OBRISAN  => "{$naziv}: {$v($staro)}",
                default              => "{$naziv}: {$v($novo)}",
            };
        }

        return implode(' | ', $delovi);
    }

    /**
     * Excel bi tekst koji pocinje sa = + - @ izvrsio kao formulu.
     * Apostrof ispred ga cini obicnim tekstom.
     */
    private static function bezFormule(?string $v): ?string
    {
        return $v !== null && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
    }
}
