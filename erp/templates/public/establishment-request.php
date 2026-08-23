<?php
/**
 * Demande d ouverture d un etablissement sur la plateforme.
 *
 * @var \Scholaris\View\View $this
 * @var list<string> $types
 * @var list<string> $statuses
 * @var array<string, mixed> $old
 * @var string|null $error
 * @var string $csrfToken
 */
$this->extends('layouts.guest');
$title = 'Demande de creation d etablissement';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
$labels = [
    'PRIMAIRE' => 'Ecole primaire',
    'SECONDAIRE' => 'Etablissement secondaire',
    'SUPERIEUR' => 'Enseignement superieur',
    'TECHNIQUE' => 'Enseignement technique',
    'FORMATION_PRO' => 'Centre de formation professionnelle',
];
?>
<div class="card" style="max-width:640px;width:100%">
    <h1 class="auth__title">Ouvrir mon etablissement</h1>
    <p class="auth__hint">
        Renseignez votre etablissement et vos coordonnees. Apres validation, vous
        recevrez vos identifiants d administrateur.
    </p>

    <?php if ($error !== null) : ?>
        <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/demande-etablissement">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <h2 style="margin-top:0.5rem">L etablissement</h2>
        <div class="grid-2">
            <div class="field">
                <label for="name">Nom *</label>
                <input id="name" name="name" required value="<?= $this->e($value('name')) ?>">
            </div>
            <div class="field">
                <label for="code">Code court *</label>
                <input id="code" name="code" required maxlength="20" placeholder="LBG"
                       value="<?= $this->e($value('code')) ?>">
            </div>
            <div class="field">
                <label for="type">Type *</label>
                <select id="type" name="type" required>
                    <?php foreach ($types as $type) : ?>
                        <option value="<?= $this->e($type) ?>" <?= $value('type') === $type ? 'selected' : '' ?>>
                            <?= $this->e($labels[$type] ?? $type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">Statut *</label>
                <select id="status" name="status" required>
                    <?php foreach ($statuses as $status) : ?>
                        <option value="<?= $this->e($status) ?>" <?= $value('status') === $status ? 'selected' : '' ?>>
                            <?= $this->e($status === 'PUBLIC' ? 'Public' : 'Prive') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="phone">Telephone</label>
                <input id="phone" name="phone" value="<?= $this->e($value('phone')) ?>">
            </div>
            <div class="field">
                <label for="email">Email de l etablissement</label>
                <input id="email" name="email" type="email" value="<?= $this->e($value('email')) ?>">
            </div>
        </div>

        <div class="field">
            <label for="address">Adresse</label>
            <input id="address" name="address" value="<?= $this->e($value('address')) ?>">
        </div>

        <h2 style="margin-top:1rem">Le responsable</h2>
        <p class="muted" style="margin-top:-0.5rem">
            Ce compte recevra les droits d administrateur de l etablissement.
        </p>
        <div class="grid-2">
            <div class="field">
                <label for="director_last_name">Nom *</label>
                <input id="director_last_name" name="director_last_name" required
                       value="<?= $this->e($value('director_last_name')) ?>">
            </div>
            <div class="field">
                <label for="director_first_name">Prenom *</label>
                <input id="director_first_name" name="director_first_name" required
                       value="<?= $this->e($value('director_first_name')) ?>">
            </div>
            <div class="field">
                <label for="director_email">Email *</label>
                <input id="director_email" name="director_email" type="email" required
                       value="<?= $this->e($value('director_email')) ?>">
            </div>
            <div class="field">
                <label for="director_phone">Telephone</label>
                <input id="director_phone" name="director_phone"
                       value="<?= $this->e($value('director_phone')) ?>">
            </div>
        </div>

        <button type="submit" class="button">Envoyer la demande</button>
    </form>

    <p class="muted" style="margin-top:1.25rem">
        Vous avez deja un compte ? <a href="/login">Connectez-vous</a>.
    </p>
</div>
