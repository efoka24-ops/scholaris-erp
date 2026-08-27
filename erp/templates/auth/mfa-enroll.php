<?php
/**
 * Enrolement a la double authentification (TOTP), propose aux comptes
 * Super Admin et Directeur tant qu'ils ne l'ont pas active.
 *
 * @var \Scholaris\View\View $this
 * @var string $secret
 * @var string $provisioningUri
 * @var string|null $error
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Double authentification';
?>
<div class="page-header">
    <div>
        <h1>Activer la double authentification</h1>
        <p class="subtitle">
            Votre role donne acces a des informations sensibles : ce compte doit
            etre protege par un second facteur, en plus du mot de passe.
        </p>
    </div>
</div>

<div class="card" style="max-width:520px">
    <?php if ($error !== null) : ?>
        <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <p class="auth__hint">
        1. Ouvrez une application d'authentification (Google Authenticator, Authy,
        ou equivalente) et scannez ce lien, ou saisissez le secret manuellement.
    </p>

    <div class="field">
        <label>Lien d'enrolement</label>
        <input class="inp" type="text" readonly value="<?= $this->e($provisioningUri) ?>"
               onclick="this.select()">
    </div>

    <div class="field">
        <label>Secret (saisie manuelle)</label>
        <input class="inp" type="text" readonly value="<?= $this->e($secret) ?>" onclick="this.select()">
    </div>

    <p class="auth__hint">
        2. Saisissez ci-dessous le code a six chiffres affiche par l'application
        pour confirmer l'enrolement.
    </p>

    <form method="post" action="/mfa/enroler">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <div class="field">
            <label for="totp_code">Code de verification</label>
            <input class="inp" id="totp_code" name="totp_code" type="text" inputmode="numeric"
                   autocomplete="one-time-code" placeholder="123456" maxlength="6" required autofocus>
        </div>

        <button type="submit" class="button button--primary">Activer la double authentification</button>
    </form>

    <form method="post" action="/mfa/enroler/plus-tard" style="margin-top:0.75rem">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <button type="submit" class="button">Plus tard</button>
    </form>
</div>
