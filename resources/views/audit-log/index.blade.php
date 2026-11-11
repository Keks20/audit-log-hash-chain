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

    $boje = ['dodat' => 'success', 'izmenjen' => 'primary', 'obrisan' => 'danger'];

    $provera = session('provera');
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
            <a href="{{ route('audit-log.provera', $artikalId ? ['artikal' => $artikalId] : []) }}"
               class="btn btn-dark btn-sm">Proveri lanac</a>
        </div>
    </div>

    @if ($provera)
        @if ($provera['ispravan'])
            <div class="alert alert-success">
                <strong>Lanac je ispravan.</strong> Zapisa: {{ $provera['brojZapisa'] }}.
                @if ($provera['poslednjiHes'])
                    <div class="small mt-1">
                        Sidro (sačuvaj van baze): zapis #{{ $provera['poslednjiId'] }},
                        heš <code class="text-break">{{ $provera['poslednjiHes'] }}</code>
                    </div>
                @endif
            </div>
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
                                    @elseif ($z->akcija === 'dodat')
                                        {{ $vrednost($novo) }}
                                    @else
                                        {{ $vrednost($staro) }}
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td>
                            <code class="small" title="{{ $z->hes }}">{{ substr($z->hes, 0, 10) }}…</code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            Nema zapisa. Log se puni dodavanjem, izmenom i brisanjem artikala.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $zapisi->links() }}
@endsection
