<?php
/**
 * @var \Scholaris\View\View $this
 * @var string $name, $code, $email
 */
$this->extends('layouts.guest');
$title = 'Demande transmise';
?>
<div class="card card--narrow">
    <h1 class="auth__title">Demande transmise</h1>
    <p>
        La demande d ouverture de <strong><?= $this->e($name) ?></strong>
        (code <strong><?= $this->e($code) ?></strong>) a ete enregistree.
    </p>
    <p class="muted">
        Apres validation par l administration de la plateforme, vos identifiants
        seront transmis a <strong><?= $this->e($email) ?></strong>.
    </p>
    <p><a href="/login">Retour a la connexion</a></p>
</div>
