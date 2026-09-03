<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SCHOLARIS') - SCHOLARIS</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    @auth
        <header class="topbar">
            <div>
                <span class="topbar__brand">SCHOLARIS</span>
                <span class="topbar__tenant">{{ auth()->user()->tenant->name }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="button button--link">Deconnexion</button>
            </form>
        </header>
    @endauth

    @yield('body')
</body>
</html>
