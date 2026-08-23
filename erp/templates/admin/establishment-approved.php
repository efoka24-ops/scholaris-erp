<?php
/**
 * Recapitulatif affiche une seule fois apres approbation.
 *
 * Le mot de passe genere n est stocke nulle part en clair : c est le seul
 * moment ou il peut etre lu et transmis au responsable.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $demand
 * @var string $password
 */
$this->extends('layouts.app');
$title = 'Etablissement cree';
?>
<h1>Etablissement cree</h1>
<p class="subtitle"><?= $this->e($demand['name']) ?> (<?= $this->e($demand['code']) ?>)</p>

<div class="card">
    <h2>Identifiants du responsable</h2>
    <p class="muted">
        Transmettez-les a <?= $this->e($demand['director_email']) ?>. Ce mot de passe
        n est pas conserve en clair et ne pourra plus etre affiche.
    </p>

    <dl class="details">
        <dt>Code etablissement</dt><dd><strong><?= $this->e($demand['code']) ?></strong></dd>
        <dt>Email</dt><dd><strong><?= $this->e($demand['director_email']) ?></strong></dd>
        <dt>Mot de passe provisoire</dt><dd><strong><?= $this->e($password) ?></strong></dd>
    </dl>

    <div class="alert alert--success" style="margin-top:1rem">
        La structure pedagogique par defaut (college et lycee) a ete creee.
        Le responsable pourra l ajuster depuis son espace.
    </div>
</div>

<p><a class="button button--secondary" href="/admin/etablissements">Retour aux demandes</a></p>
