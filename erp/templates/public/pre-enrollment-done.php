<?php
/**
 * @var \Scholaris\View\View $this
 * @var string $reference
 * @var string $tenantName
 */
$this->extends('layouts.guest');
$title = 'Demande enregistrée';
?>
<div class="card card--narrow">
    <h1 class="auth__title">Demande enregistrée</h1>
    <p>
        Votre demande de pre-inscription a <strong><?= $this->e($tenantName) ?></strong>
        a bien ete transmise.
    </p>
    <div class="alert alert--success">
        Référence du dossier : <strong><?= $this->e($reference) ?></strong>
    </div>
    <p class="muted">
        Conservez cette référence. L'établissement vous contactera au numéro
        indique pour la suite de la procedure.
    </p>
    <p><a href="/pre-inscription">Deposer une autre demande</a></p>
</div>
