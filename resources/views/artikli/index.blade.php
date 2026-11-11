@extends('layouts.app')

@section('naslov', 'Artikli')

@section('sadrzaj')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Artikli</h1>
        <span class="text-muted">Ukupno: {{ $artikli->count() }}</span>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover table-sm bg-white align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Šifra</th>
                    <th>Naziv</th>
                    <th>JM</th>
                    <th class="text-end">Količina</th>
                    <th class="text-end">Cena</th>
                    <th>Datum nabavke</th>
                    <th>Aktivan</th>
                    <th>Napomena</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($artikli as $a)
                    <tr>
                        <td><code>{{ $a->sifra }}</code></td>
                        <td>{{ $a->naziv }}</td>
                        <td>{{ $a->jedinica_mere }}</td>
                        <td class="text-end">
                            {{ $a->kolicina !== null ? number_format($a->kolicina, 3, ',', '.') : '—' }}
                        </td>
                        <td class="text-end">
                            {{ $a->cena !== null ? number_format($a->cena, 2, ',', '.') : '—' }}
                        </td>
                        <td>{{ $a->datum_nabavke?->format('d.m.Y.') ?? '—' }}</td>
                        <td>
                            @if ($a->aktivan === null)
                                <span class="badge text-bg-warning">?</span>
                            @elseif ($a->aktivan)
                                <span class="badge text-bg-success">da</span>
                            @else
                                <span class="badge text-bg-secondary">ne</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $a->napomena }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            Nema artikala. Učitaj ih iz <code>database/data/artikli.json</code>:
                            <code>php artisan db:seed</code>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection