<?php
/**
 * Page servie quand le reseau manque et que la page demandee n a jamais ete
 * consultee sur cet appareil.
 *
 * @var \Scholaris\View\View $this
 */
$this->extends('layouts.guest');
$title = 'Hors ligne';
?>
<div class="card" style="max-width:32rem;width:100%;text-align:center">
    <h1 class="auth__title">Pas de reseau</h1>
    <p class="auth__hint">
        Cette page n a pas encore ete ouverte sur cet appareil, elle ne peut
        donc pas etre affichee hors connexion.
    </p>
    <p class="auth__hint">
        Les pages deja consultees restent accessibles, et les saisies faites
        sans reseau — appel, notes, discipline — sont conservees ici puis
        transmises des le retour de la connexion. Rien n est perdu.
    </p>
    <p style="margin-top:1.5rem">
        <a class="button" href="/dashboard">Reessayer</a>
    </p>
</div>
