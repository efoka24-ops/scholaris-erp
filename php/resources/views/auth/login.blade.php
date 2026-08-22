@extends('layouts.guest')

@section('title', 'Connexion')

@section('body')
    <div class="auth__card">
        <h1 class="auth__title">Connexion</h1>
        <p class="auth__hint">Acces reserve au personnel et aux familles de l'etablissement.</p>

        @if ($errors->any())
            <div class="alert" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="email">Adresse email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>

            <div class="field">
                <label for="password">Mot de passe</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>

            {{-- Demande uniquement quand le meme email existe dans plusieurs
                 etablissements : le champ reste facultatif le reste du temps. --}}
            <div class="field">
                <label for="tenant_code">Code etablissement (facultatif)</label>
                <input id="tenant_code" name="tenant_code" type="text" value="{{ old('tenant_code') }}" autocomplete="organization">
            </div>

            <div class="checkbox">
                <input id="remember" name="remember" type="checkbox" value="1">
                <label for="remember">Rester connecte</label>
            </div>

            <button type="submit" class="button">Se connecter</button>
        </form>
    </div>
@endsection
