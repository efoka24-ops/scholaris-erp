<?php
/**
 * @var \Scholaris\View\View $this
 * @var string $name
 * @var string $code
 * @var string $email
 * @var string $reference
 */
$this->extends('layouts.guest');
$title = 'Demande transmise';
?>
<div class="card card--narrow">
    <h1 class="auth__title">Demande transmise</h1>
    <p>
        La demande d'ouverture de <strong><?= $this->e($name) ?></strong>
        (code <strong><?= $this->e($code) ?></strong>) a ete enregistrée.
    </p>

    <div class="alert alert--success" style="margin:1.25rem 0">
        Référence du dossier
        <div style="font-size:1.6rem;font-weight:700;letter-spacing:0.08em;margin-top:0.35rem">
            <?= $this->e($reference) ?>
        </div>
    </div>

    <p class="muted">
        Notez cette référence. Un accuse de reception vient d'etre adresse a
        <strong><?= $this->e($email) ?></strong>, et c'est a cette meme adresse
        que vos identifiants seront transmis après validation.
    </p>

    <p>
        <a class="button" href="/demande-etablissement/suivi">Suivre mon dossier</a>
    </p>
    <p><a href="/login">Retour a la connexion</a></p>
</div>
