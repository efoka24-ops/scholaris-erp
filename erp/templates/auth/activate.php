<?php
/**
 * Activation de compte via un lien a duree limitee.
 *
 * @var \Scholaris\View\View $this
 * @var string $token
 * @var bool $valid
 * @var string|null $error
 * @var string $csrfToken
 */
$this->extends('layouts.guest');
$title = 'Activation du compte';
?>
<div class="card" style="max-width:480px;width:100%">
    <h1 class="auth__title">Activation du compte</h1>

    <?php if (! $valid) : ?>
        <p class="auth__hint">
            Ce lien est invalide ou a expire — il n'est valable que 72 heures
            après son envoi. Demandez a votre administration de vous en
            renvoyer un, ou de réinitialiser votre mot de passe.
        </p>
        <p><a href="/login">Retour a la connexion</a></p>
    <?php else : ?>
        <p class="auth__hint">
            Choisissez le mot de passe de votre compte. Il ne sera plus jamais
            affiché ni transmis par courrier.
        </p>

        <?php if ($error !== null) : ?>
            <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/activation/<?= $this->e($token) ?>">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

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

            <button type="submit" class="btn-volt button--block">Activer mon compte →</button>
        </form>
    <?php endif; ?>
</div>
