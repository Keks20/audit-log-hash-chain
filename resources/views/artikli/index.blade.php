@extends('layouts.app')

@section('naslov', 'Artikli')

@section('sadrzaj')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Artikli</h1>
        <div class="d-flex align-items-center gap-3">
            <form method="GET" action="{{ route('artikli.index') }}" class="d-flex gap-2" role="search">
                <input type="search" name="q" value="{{ $q }}" maxlength="50" class="form-control form-control-sm"
                       placeholder="Šifra ili naziv" aria-label="Pretraga po šifri ili nazivu">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Traži</button>
                @if ($q !== '')
                    <a href="{{ route('artikli.index') }}" class="btn btn-outline-secondary btn-sm">Sve</a>
                @endif
            </form>
            <span class="text-muted text-nowrap">{{ $q !== '' ? 'Nađeno' : 'Ukupno' }}: {{ $artikli->count() }}</span>
            @auth
                <a href="{{ route('artikli.create') }}" class="btn btn-primary btn-sm">Dodaj artikal</a>
            @endauth
        </div>
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
                    @auth
                        <th></th>
                    @endauth
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
                        @auth
                            <td class="text-end text-nowrap">
                                <a href="{{ route('audit-log.index', ['artikal' => $a->id]) }}" class="btn btn-outline-secondary btn-sm">Istorija</a>
                                <a href="{{ route('artikli.edit', $a) }}" class="btn btn-outline-primary btn-sm">Izmeni</a>
                                @can('sef')
                                    {{-- Js::from bezbedno ubacuje tekst u JavaScript (naziv moze imati navodnike) --}}
                                    <form method="POST" action="{{ route('artikli.destroy', $a) }}" class="d-inline"
                                          onsubmit="return confirm({{ Js::from('Obrisati artikal ' . $a->sifra . ' (' . $a->naziv . ')?') }})">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Obriši</button>
                                    </form>
                                @endcan
                            </td>
                        @endauth
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ auth()->check() ? 9 : 8 }}" class="text-center text-muted py-4">
                            @if ($q !== '')
                                Nijedan artikal ne odgovara pretrazi „{{ $q }}“.
                            @else
                                Nema artikala. Učitaj ih iz <code>database/data/artikli.json</code>:
                                <code>php artisan db:seed</code>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection