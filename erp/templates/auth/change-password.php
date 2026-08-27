<?php
/**
 * Changement de mot de passe, volontaire ou impose apres un mot de passe
 * provisoire jamais renouvele.
 *
 * @var \Scholaris\View\View $this
 * @var bool $forced
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Changer de mot de passe';
?>
<div class="page-header">
    <div>
        <h1>Changer de mot de passe</h1>
        <p class="subtitle">
            <?php if ($forced) : ?>
                Votre mot de passe est provisoire : changez-le avant de continuer.
                Aucune autre page n est accessible tant que ce n est pas fait.
            <?php else : ?>
                Choisissez un nouveau mot de passe pour votre compte.
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="card" style="max-width:480px">
    <form method="post" action="/mot-de-passe/changer">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <div class="field">
            <label for="current_password">Mot de passe actuel</label>
            <input class="inp" id="current_password" name="current_password" type="password" required
                   autocomplete="current-password">
        </div>

        <div class="field">
            <label for="password">Nouveau mot de passe</label>
            <input class="inp" id="password" name="password" type="password" required minlength="8"
                   autocomplete="new-password" placeholder="8 caracteres au moins">
        </div>

        <div class="field">
            <label for="password_confirmation">Confirmation</label>
            <input class="inp" id="password_confirmation" name="password_confirmation" type="password" required
                   minlength="8" autocomplete="new-password">
        </div>

        <button type="submit" class="button button--primary">Changer le mot de passe</button>
    </form>
</div>
