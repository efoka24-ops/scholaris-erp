<?php
/**
 * Suivi d un dossier d ouverture, sans compte.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $demand
 * @var bool $searched
 * @var string|null $error
 * @var string $reference
 * @var string $email
 * @var string $csrfToken
 */
$this->extends('layouts.guest');
$title = 'Suivi de ma demande';

$states = [
    'PENDING' => ['En cours d\'instruction', 'Votre dossier a ete reçu et attend la validation de l\'administration de la plateforme.'],
    'APPROVED' => ['Acceptee', 'Votre établissement est ouvert. Vos identifiants ont ete adresses a votre adresse email.'],
    'REJECTED' => ['Non retenue', 'Votre dossier n\'a pas ete retenu.'],
];
?>
<div class="card card--narrow">
    <h1 class="auth__title">Suivi de ma demande</h1>
    <p class="auth__hint">
        Saisissez la référence reçue lors du depot et l'adresse email du
        responsable. Les deux sont demandees : une référence seule pourrait
        etre devinee.
    </p>

    <?php if ($error !== null) : ?>
        <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/demande-etablissement/suivi">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <div class="field">
            <label for="reference">Référence du dossier</label>
            <input id="reference" name="reference" placeholder="ET-XXXXXX" required
                   value="<?= $this->e($reference) ?>">
        </div>
        <div class="field">
            <label for="email">Email du responsable</label>
            <input id="email" name="email" type="email" required value="<?= $this->e($email) ?>">
        </div>
        <button type="submit" class="button">Consulter</button>
    </form>

    <?php if ($demand !== null) : ?>
        <?php
        $status = (string) $demand['request_status'];
        [$label, $message] = $states[$status] ?? ['Etat inconnu', ''];
        $tone = $status === 'APPROVED' ? 'success' : ($status === 'REJECTED' ? 'error' : 'info');
        ?>
        <hr style="margin:1.5rem 0;border:none;border-top:1px solid rgba(255,255,255,0.12)">

        <h2 style="margin-bottom:0.25rem"><?= $this->e($demand['name']) ?></h2>
        <p class="muted" style="margin-top:0">
            Code <?= $this->e($demand['code']) ?> &middot; déposé le
            <?= $this->e(substr((string) $demand['created_at'], 0, 10)) ?>
        </p>

        <div class="alert alert--<?= $this->e($tone) ?>">
            <strong><?= $this->e($label) ?></strong><br>
            <?= $this->e($message) ?>
        </div>

        <?php if ($status === 'REJECTED' && ($demand['rejection_reason'] ?? null) !== null) : ?>
            <p><strong>Motif :</strong> <?= $this->e($demand['rejection_reason']) ?></p>
            <p><a class="button button--secondary" href="/demande-etablissement">Deposer une nouvelle demande</a></p>
        <?php endif; ?>

        <?php if ($status === 'APPROVED') : ?>
            <p><a class="button" href="/login">Me connecter</a></p>
        <?php endif; ?>
    <?php endif; ?>

    <p style="margin-top:1.5rem"><a href="/login">Retour a la connexion</a></p>
</div>
