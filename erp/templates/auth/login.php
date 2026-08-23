<?php
/**
 * @var \Scholaris\View\View $this
 * @var string|null $error
 * @var array<string, mixed> $old
 * @var string $csrfToken
 */
$this->extends('layouts.guest');
$title = 'Connexion';
?>
<div class="card card--narrow">
    <h1 class="auth__title">Connexion</h1>
    <p class="auth__hint">Acces reserve au personnel et aux familles de l etablissement.</p>

    <?php if ($error !== null) : ?>
        <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <div class="field">
            <label for="email">Adresse email</label>
            <input id="email" name="email" type="email" required autofocus autocomplete="username"
                   value="<?= $this->e($old['email'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <div class="field">
            <label for="tenant_code">Code etablissement <span class="muted">(si demande)</span></label>
            <input id="tenant_code" name="tenant_code" type="text" autocomplete="organization"
                   value="<?= $this->e($old['tenant_code'] ?? '') ?>">
        </div>

        <button type="submit" class="button">Se connecter</button>
    </form>
</div>
