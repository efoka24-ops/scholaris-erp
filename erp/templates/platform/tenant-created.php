<?php
/**
 * Recapitulatif affiche une seule fois apres creation.
 *
 * @var \Scholaris\View\View $this
 * @var string $name, $code, $email, $password
 * @var int $levels
 */
$this->extends('layouts.app');
$title = 'Établissement créé';
?>
<h1>Établissement créé</h1>
<p class="subtitle"><?= $this->e($name) ?> (<?= $this->e($code) ?>)</p>

<div class="card">
    <h2>Identifiants du responsable</h2>
    <p class="muted">
        Un courrier vient d'etre adresse a <?= $this->e($email) ?>. Ce mot de
        passe n'est pas conserve en clair et ne pourra plus etre affiché :
        notez-le si vous devez le dicter.
    </p>

    <dl class="details">
        <dt>Code établissement</dt><dd><strong><?= $this->e($code) ?></strong></dd>
        <dt>Email</dt><dd><strong><?= $this->e($email) ?></strong></dd>
        <dt>Mot de passe provisoire</dt><dd><strong><?= $this->e($password) ?></strong></dd>
    </dl>

    <div class="alert alert--success" style="margin-top:1rem">
        <?= $this->number($levels) ?> niveaux ont ete créés, ainsi que l'année
        scolaire en cours et sa première séquence : l'établissement est
        utilisable immediatement.
    </div>
</div>

<p>
    <a class="button button--secondary" href="/admin/parc">Retour au parc</a>
    <a class="button" href="/admin/courriers">Voir les courriers envoyés</a>
</p>
