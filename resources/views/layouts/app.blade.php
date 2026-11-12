<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('naslov', 'Magacin') | Audit log</title>
    {{-- Bootstrap za sada preko CDN-a --}}
    {{-- integrity: pregledac odbija fajl ako se na CDN-u promeni --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="{{ route('artikli.index') }}">Magacin</a>
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('artikli.*') ? 'active' : '' }}"
                   href="{{ route('artikli.index') }}">Artikli</a>
            </li>
            @auth
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('audit-log.*') ? 'active' : '' }}"
                       href="{{ route('audit-log.index') }}">Audit log</a>
                </li>
            @endauth
        </ul>

        <div class="ms-auto d-flex align-items-center gap-3">
            @auth
                <span class="navbar-text">
                    {{ auth()->user()->name }}
                    <span class="badge text-bg-secondary">{{ auth()->user()->jeSef() ? 'šef' : 'magacioner' }}</span>
                </span>
                <form method="POST" action="{{ route('odjava') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-outline-light btn-sm">Odjava</button>
                </form>
            @else
                <a class="btn btn-outline-light btn-sm" href="{{ route('prijava') }}">Prijava</a>
            @endauth
        </div>
    </div>
</nav>

<main class="container">
    @if (session('poruka'))
        <div class="alert alert-success">{{ session('poruka') }}</div>
    @endif
    @if (session('greska'))
        <div class="alert alert-danger">{{ session('greska') }}</div>
    @endif

    @yield('sadrzaj')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>