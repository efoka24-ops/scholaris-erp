@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('body')
    <div class="container">
        <h1>Tableau de bord</h1>
        <p class="subtitle">
            {{ $tenant->name }}
            @if ($academicYear)
                - annee academique {{ $academicYear->label }}
            @else
                - aucune annee academique active
            @endif
        </p>

        <div class="stats">
            <div class="stat">
                <div class="stat__label">Eleves actifs</div>
                <div class="stat__value">{{ number_format($stats['students'], 0, ',', ' ') }}</div>
            </div>
            <div class="stat">
                <div class="stat__label">Classes</div>
                <div class="stat__value">{{ number_format($stats['classrooms'], 0, ',', ' ') }}</div>
            </div>
            <div class="stat">
                <div class="stat__label">Inscriptions actives</div>
                <div class="stat__value">{{ number_format($stats['enrollments'], 0, ',', ' ') }}</div>
            </div>
            <div class="stat">
                <div class="stat__label">Total facture</div>
                <div class="stat__value stat__value--money">{{ number_format($stats['billed'], 0, ',', ' ') }} FCFA</div>
            </div>
            <div class="stat">
                <div class="stat__label">Encaisse</div>
                <div class="stat__value stat__value--money">{{ number_format($stats['collected'], 0, ',', ' ') }} FCFA</div>
            </div>
            <div class="stat">
                <div class="stat__label">Reste a recouvrer</div>
                <div class="stat__value stat__value--money">{{ number_format($stats['outstanding'], 0, ',', ' ') }} FCFA</div>
            </div>
        </div>
    </div>
@endsection
