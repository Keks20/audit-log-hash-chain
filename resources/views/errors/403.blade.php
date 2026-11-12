@extends('layouts.app')

@section('naslov', 'Nedozvoljeno')

@section('sadrzaj')
    <div class="alert alert-warning">
        <strong>Nemaš pravo na ovu radnju.</strong>
        Brisanje artikla i vraćanje stanja iz loga može samo šef.
    </div>
    <a href="{{ route('artikli.index') }}" class="btn btn-outline-secondary btn-sm">Nazad na artikle</a>
@endsection
