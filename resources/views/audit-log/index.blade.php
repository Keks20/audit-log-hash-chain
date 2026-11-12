@extends('layouts.app')

@php
    $naziviPolja = [
        'sifra'         => 'Šifra',
        'naziv'         => 'Naziv',
        'jedinica_mere' => 'JM',
        'kolicina'      => 'Količina',
        'cena'          => 'Cena',
        'datum_nabavke' => 'Datum nabavke',
        'aktivan'       => 'Aktivan',
        'napomena'      => 'Napomena',
    ];

    $vrednost = fn ($v) => match (true) {
        $v === null  => '—',
        $v === true  => 'da',
        $v === false => 'ne',
        default      => $v,
    };

    $boje = ['dodat' => 'success', 'izmenjen' => 'primary', 'obrisan' => 'danger', 'pocetno' => 'secondary'];

    $provera = session('provera');

    // Filteri koji su zadati, za linkove koji ih zadrzavaju (izvoz)
    $zadati = array_filter($filteri, fn ($v) => $v !== null);
    $imaFiltera = array_diff_key($zadati, ['artikal' => 1]) !== [];

    $beograd = fn (string $utc) => \Illuminate\Support\Carbon::parse($utc, 'UTC')
        ->timezone(config('magacin.zona_prikaza'))->format('d.m.Y. H:i:s');
@endphp

@section('naslov', 'Audit log')

@section('sadrzaj')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">
            Audit log
            @if ($artikalId)
                <small class="text-muted">: istorija artikla
                    @if ($zapisi->first())
                        <code>{{ $zapisi->first()->sifra }}</code>
                    @endif
                </small>
            @endif
        </h1>
        <div class="d-flex align-items-center gap-2">
            @if ($artikalId)
                <a href="{{ route('audit-log.index') }}" class="btn btn-outline-secondary btn-sm">Ceo log</a>
            @endif
            <a href="{{ route('audit-log.izvoz', $zadati) }}" class="btn btn-outline-secondary btn-sm">Izvezi CSV</a>
            <a href="{{ route('audit-log.provera', $artikalId ? ['artikal' => $artikalId] : []) }}"
               class="btn btn-dark btn-sm">Proveri lanac</a>
        </div>
    </div>

    @if ($nadzor)
        <p class="small mb-2 {{ $nadzor['uRedu'] ? 'text-muted' : 'text-danger fw-bold' }}">
            Poslednja automatska provera: {{ $beograd($nadzor['vreme']) }},
            @if ($nadzor['uRedu'])
                sve u redu (zapisa: {{ $nadzor['brojZapisa'] }}).
            @else
                NIJE prošla. {{ $nadzor['opis'] }}
            @endif
        </p>
    @endif

    <form method="GET" action="{{ route('audit-log.index') }}" class="row g-2 align-items-end mb-3">
        @if ($artikalId)
            <input type="hidden" name="artikal" value="{{ $artikalId }}">
        @endif
        <div class="col-6 col-md-2">
            <label for="f-akcija" class="form-label small mb-0">Akcija</label>
            <select id="f-akcija" name="akcija" class="form-select form-select-sm">
                <option value="">sve</option>
                @foreach ($akcije as $a)
                    <option value="{{ $a }}" @selected($filteri['akcija'] === $a)>{{ $a }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="f-korisnik" class="form-label small mb-0">Korisnik</label>
            <select id="f-korisnik" name="korisnik" class="form-select form-select-sm">
                <option value="">svi</option>
                @foreach ($korisnici as $email)
                    <option value="{{ $email }}" @selected($filteri['korisnik'] === $email)>{{ $email }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-4 col-md-2">
            <label for="f-sifra" class="form-label small mb-0">Šifra počinje sa</label>
            <input id="f-sifra" type="text" name="sifra" value="{{ $filteri['sifra'] }}" maxlength="6" inputmode="numeric"
                   class="form-control form-control-sm">
        </div>
        <div class="col-4 col-md-2">
            <label for="f-od" class="form-label small mb-0">Od dana</label>
            <input id="f-od" type="date" name="od" value="{{ $filteri['od'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-4 col-md-2">
            <label for="f-do" class="form-label small mb-0">Do dana</label>
            <input id="f-do" type="date" name="do" value="{{ $filteri['do'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-outline-primary btn-sm">Filtriraj</button>
        </div>
        @if ($imaFiltera)
            <div class="col-12 small">
                Nađeno zapisa: {{ $zapisi->total() }}.
                <a href="{{ route('audit-log.index', $artikalId ? ['artikal' => $artikalId] : []) }}">Poništi filtere</a>
            </div>
        @endif
    </form>

    @if ($provera)
        @if ($provera['ispravan'])
            <div class="alert alert-success">
                <strong>Lanac je ispravan.</strong> Zapisa: {{ $provera['brojZapisa'] }}.
                @if ($provera['brojZapisa'] > 0)
                    Potpisano: {{ $provera['brojZapisa'] - $provera['nepotpisanih'] }}@if ($provera['nepotpisanih'] > 0),
                        bez potpisa sa početka lanca (pre uvođenja potpisa): {{ $provera['nepotpisanih'] }}@endif.
                @endif
                @if (! empty($provera['sidro']))
                    <div class="small mt-1">
                        Sačuvano sidro je pronađeno u lancu: zapis #{{ $provera['sidro']['id'] }},
                        sačuvano {{ $beograd($provera['sidro']['vreme']) }}.
                    </div>
                @endif
                @if ($provera['poslednjiHes'])
                    <div class="small mt-1">
                        Sidro (sačuvaj van baze): zapis #{{ $provera['poslednjiId'] }},
                        heš <code class="text-break">{{ $provera['poslednjiHes'] }}</code>
                    </div>
                @endif
            </div>

            @if (! empty($provera['neslaganja']))
                <div class="alert alert-warning">
                    <strong>Artikli se ne slažu sa logom</strong> ({{ count($provera['neslaganja']) }}):
                    <ul class="small mb-0 mt-1">
                        @foreach (array_slice($provera['neslaganja'], 0, 10) as $n)
                            <li>{{ $n }}</li>
                        @endforeach
                        @if (count($provera['neslaganja']) > 10)
                            <li>… i još {{ count($provera['neslaganja']) - 10 }} (ceo spisak: <code>php artisan audit:provera</code>)</li>
                        @endif
                    </ul>
                </div>
            @elseif ($provera['brojZapisa'] > 0)
                <div class="alert alert-success py-2">Stanje svih artikala se slaže sa logom.</div>
            @endif
        @else
            <div class="alert alert-danger">
                <strong>Lanac NIJE ispravan.</strong>
                @if ($provera['losZapisId'])
                    Zapis #{{ $provera['losZapisId'] }}:
                @endif
                {{ $provera['poruka'] }}
                @if (! empty($provera['tragovi']))
                    <div class="small mt-2">Šta se ne slaže:</div>
                    <ul class="small mb-0">
                        @foreach ($provera['tragovi'] as $trag)
                            <li>{{ $trag }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    @endif

    <div class="table-responsive">
        <table class="table table-sm table-hover bg-white align-middle">
            <thead class="table-dark">
                <tr>
                    <th class="text-end">#</th>
                    <th>Vreme</th>
                    <th>Korisnik</th>
                    <th>Akcija</th>
                    <th>Šifra</th>
                    <th>Izmene</th>
                    <th>Heš</th>
                    @can('sef')
                        <th></th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($zapisi as $z)
                    <tr @class(['table-danger' => $provera && ! $provera['ispravan'] && $provera['losZapisId'] === $z->id])>
                        <td class="text-end text-muted">{{ $z->id }}</td>
                        <td class="text-nowrap">{{ $z->vremePrikaz() }}</td>
                        <td class="small">{{ $z->korisnik_email ?? '—' }}</td>
                        <td>
                            <span class="badge text-bg-{{ $boje[$z->akcija] ?? 'secondary' }}">{{ $z->akcija }}</span>
                        </td>
                        <td>
                            <a href="{{ route('audit-log.index', ['artikal' => $z->artikal_id]) }}"><code>{{ $z->sifra }}</code></a>
                        </td>
                        <td class="small">
                            @foreach ($z->izmene() as $polje => [$staro, $novo])
                                <div>
                                    <span class="text-muted">{{ $naziviPolja[$polje] ?? $polje }}:</span>
                                    @if ($z->akcija === 'izmenjen')
                                        <del class="text-danger">{{ $vrednost($staro) }}</del>
                                        → <span class="text-success">{{ $vrednost($novo) }}</span>
                                    @elseif (in_array($z->akcija, ['dodat', 'pocetno'], true))
                                        {{ $vrednost($novo) }}
                                    @else
                                        {{ $vrednost($staro) }}
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td>
                            <code class="small" title="{{ $z->hes }}">{{ substr($z->hes, 0, 10) }}…</code>
                            @if ($z->potpis === null)
                                <span class="badge text-bg-light border" title="Zapis iz vremena pre uvođenja potpisa">bez potpisa</span>
                            @endif
                        </td>
                        @can('sef')
                            <td class="text-end">
                                <form method="POST" action="{{ route('audit-log.vrati', $z) }}" class="d-inline"
                                      onsubmit="return confirm({{ Js::from('Vratiti artikal ' . $z->sifra . ' na stanje iz zapisa #' . $z->id . '?') }})">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Vrati</button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            @if ($imaFiltera)
                                Nijedan zapis ne odgovara filterima.
                            @else
                                Nema zapisa. Log se puni dodavanjem, izmenom i brisanjem artikala.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $zapisi->links() }}
@endsection
