<?php
/**
 * Mot de passe attribue a un compte, affiche une seule fois.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $user
 * @var string $password
 */
$this->extends('layouts.app');
$title = 'Mot de passe attribue';
?>
<h1>Mot de passe attribue</h1>
<p class="subtitle">
    <?= $this->e(trim($user['first_name'].' '.$user['last_name'])) ?>
    <?php if (($user['tenant_name'] ?? null) !== null) : ?>
        &middot; <?= $this->e($user['tenant_name']) ?>
    <?php else : ?>
        &middot; administration de la plateforme
    <?php endif; ?>
</p>

<div class="card">
    <dl class="details">
        <dt>Identifiant</dt><dd><strong><?= $this->e($user['email']) ?></strong></dd>
        <dt>Mot de passe provisoire</dt><dd><strong><?= $this->e($password) ?></strong></dd>
    </dl>

    <div class="alert alert--success" style="margin-top:1rem">
        Un courrier vient d'etre adresse a <?= $this->e($user['email']) ?>.
        Ce mot de passe n'est conserve que hache : il ne pourra plus etre
        affiché. Notez-le si vous devez le dicter.
    </div>

    <p class="muted">
        Demandez a son titulaire de le changer des sa prochaine connexion : il
        a circule par courrier electronique, il ne doit pas rester en service.
    </p>
</div>

<p>
    <a class="button button--secondary" href="/admin/comptes">Retour aux comptes</a>
    <a class="button" href="/admin/courriers">Verifier l'envoi</a>
</p>
