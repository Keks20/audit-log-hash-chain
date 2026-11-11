@extends('layouts.app')

@php
    // Ista forma za novi artikal i za izmenu postojeceg
    $izmena = $artikal->exists;

    // Ista pravila kao na serveru (ArtikalRequest)
    $obavezno = fn (string $polje) => \App\Http\Requests\ArtikalRequest::jeObavezno($artikal, $polje);

    // Aktivan ima tri stanja: da, ne i "?" (NULL iz starog programa)
    $aktivan = old('aktivan', $artikal->aktivan);
    $aktivan = $aktivan === null || $aktivan === '' ? '' : ($aktivan ? '1' : '0');
@endphp

@section('naslov', $izmena ? 'Izmena artikla' : 'Novi artikal')

@section('sadrzaj')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="h3 mb-3">
                @if ($izmena)
                    Izmena artikla <code>{{ $artikal->sifra }}</code>
                @else
                    Novi artikal
                @endif
            </h1>

            <form method="POST"
                  action="{{ $izmena ? route('artikli.update', $artikal) : route('artikli.store') }}"
                  class="card card-body shadow-sm">
                @csrf
                @if ($izmena)
                    @method('PUT')
                @endif

                <div class="row g-3">
                    <div class="col-sm-4">
                        <label for="sifra" class="form-label">Šifra</label>
                        @if ($izmena)
                            {{-- Sifra se posle dodavanja ne menja, zato polje nema "name" --}}
                            <input type="text" id="sifra" value="{{ $artikal->sifra }}"
                                   class="form-control" disabled>
                        @else
                            <input type="text" id="sifra" name="sifra" value="{{ old('sifra') }}"
                                   class="form-control @error('sifra') is-invalid @enderror"
                                   maxlength="6" inputmode="numeric" placeholder="npr. 001038" required autofocus>
                            @error('sifra')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        @endif
                    </div>

                    <div class="col-sm-8">
                        <label for="naziv" class="form-label">Naziv</label>
                        <input type="text" id="naziv" name="naziv" value="{{ old('naziv', $artikal->naziv) }}"
                               class="form-control @error('naziv') is-invalid @enderror"
                               maxlength="30" required>
                        @error('naziv')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-sm-4">
                        <label for="jedinica_mere" class="form-label">Jedinica mere</label>
                        <select id="jedinica_mere" name="jedinica_mere"
                                class="form-select @error('jedinica_mere') is-invalid @enderror" required>
                            @foreach (\App\Models\Artikal::JEDINICE_MERE as $jm)
                                <option value="{{ $jm }}" @selected(old('jedinica_mere', $artikal->jedinica_mere) === $jm)>{{ $jm }}</option>
                            @endforeach
                        </select>
                        @error('jedinica_mere')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-sm-4">
                        <label for="kolicina" class="form-label">Količina</label>
                        <input type="text" id="kolicina" name="kolicina" value="{{ old('kolicina', $artikal->kolicina) }}"
                               class="form-control text-end @error('kolicina') is-invalid @enderror"
                               inputmode="decimal" placeholder="npr. 12,5" @required($obavezno('kolicina'))>
                        @error('kolicina')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-sm-4">
                        <label for="cena" class="form-label">Cena</label>
                        <input type="text" id="cena" name="cena" value="{{ old('cena', $artikal->cena) }}"
                               class="form-control text-end @error('cena') is-invalid @enderror"
                               inputmode="decimal" placeholder="npr. 120,50" @required($obavezno('cena'))>
                        @error('cena')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-sm-6">
                        <label for="datum_nabavke" class="form-label">Datum nabavke</label>
                        <input type="date" id="datum_nabavke" name="datum_nabavke"
                               value="{{ old('datum_nabavke', $artikal->datum_nabavke?->format('Y-m-d')) }}"
                               class="form-control @error('datum_nabavke') is-invalid @enderror"
                               @required($obavezno('datum_nabavke'))>
                        @error('datum_nabavke')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-sm-6">
                        <label for="aktivan" class="form-label">Aktivan</label>
                        <select id="aktivan" name="aktivan" class="form-select @error('aktivan') is-invalid @enderror"
                                @required($obavezno('aktivan'))>
                            <option value="1" @selected($aktivan === '1')>da</option>
                            <option value="0" @selected($aktivan === '0')>ne</option>
                            {{-- "?" samo za artikal koji je takav dosao iz starog programa --}}
                            @unless ($obavezno('aktivan'))
                                <option value="" @selected($aktivan === '')>? (nepoznato)</option>
                            @endunless
                        </select>
                        @error('aktivan')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label for="napomena" class="form-label">Napomena <span class="text-muted small">(nije obavezna)</span></label>
                        {{-- Sadrzaj u istom redu kao <textarea>, da ne dobije prazan red na pocetku --}}
                        <textarea id="napomena" name="napomena" rows="3" maxlength="1000"
                                  class="form-control @error('napomena') is-invalid @enderror">{{ old('napomena', $artikal->napomena) }}</textarea>
                        @error('napomena')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">{{ $izmena ? 'Sačuvaj izmene' : 'Dodaj artikal' }}</button>
                    <a href="{{ route('artikli.index') }}" class="btn btn-outline-secondary">Odustani</a>
                </div>
            </form>
        </div>
    </div>
@endsection
