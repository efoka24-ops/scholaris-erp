<?php
/**
 * Pre-inscription en ligne, ouverte aux familles sans compte.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $tenants
 * @var array<string, mixed> $old
 * @var string|null $error
 * @var string $csrfToken
 */
$this->extends('layouts.guest');
$title = 'Pre-inscription en ligne';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<div class="card" style="max-width:640px;width:100%">
    <h1 class="auth__title">Pre-inscription en ligne</h1>
    <p class="auth__hint">
        Deposez le dossier de votre enfant. L etablissement vous recontactera
        pour finaliser l inscription. Aucun compte n est necessaire.
    </p>

    <?php if ($error !== null) : ?>
        <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
    <?php endif; ?>

    <?php if ($tenants === []) : ?>
        <div class="alert alert--error">
            Aucun etablissement n a ouvert la pre-inscription en ligne pour le moment.
        </div>
    <?php else : ?>
        <form method="post" action="/pre-inscription">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

            <div class="field">
                <label for="tenant_code">Etablissement souhaite *</label>
                <select id="tenant_code" name="tenant_code" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($tenants as $tenant) : ?>
                        <option value="<?= $this->e($tenant['code']) ?>"
                            <?= $value('tenant_code') === $tenant['code'] ? 'selected' : '' ?>>
                            <?= $this->e($tenant['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <h2 style="margin-top:1.5rem">L eleve</h2>
            <div class="grid-2">
                <div class="field">
                    <label for="applicant_last_name">Nom *</label>
                    <input id="applicant_last_name" name="applicant_last_name" required
                           value="<?= $this->e($value('applicant_last_name')) ?>">
                </div>
                <div class="field">
                    <label for="applicant_first_name">Prenom *</label>
                    <input id="applicant_first_name" name="applicant_first_name" required
                           value="<?= $this->e($value('applicant_first_name')) ?>">
                </div>
                <div class="field">
                    <label for="date_of_birth">Date de naissance *</label>
                    <input id="date_of_birth" name="date_of_birth" type="date" required
                           value="<?= $this->e($value('date_of_birth')) ?>">
                </div>
                <div class="field">
                    <label for="gender">Sexe *</label>
                    <select id="gender" name="gender" required>
                        <option value="MALE" <?= $value('gender') === 'MALE' ? 'selected' : '' ?>>Masculin</option>
                        <option value="FEMALE" <?= $value('gender') === 'FEMALE' ? 'selected' : '' ?>>Feminin</option>
                    </select>
                </div>
                <div class="field">
                    <label for="level_wanted">Classe demandee</label>
                    <input id="level_wanted" name="level_wanted" placeholder="6eme, Form 1, 2nde..."
                           value="<?= $this->e($value('level_wanted')) ?>">
                </div>
                <div class="field">
                    <label for="previous_school">Etablissement precedent</label>
                    <input id="previous_school" name="previous_school"
                           value="<?= $this->e($value('previous_school')) ?>">
                </div>
            </div>

            <h2 style="margin-top:1rem">Le parent ou tuteur</h2>
            <div class="grid-2">
                <div class="field">
                    <label for="parent_name">Nom complet *</label>
                    <input id="parent_name" name="parent_name" required
                           value="<?= $this->e($value('parent_name')) ?>">
                </div>
                <div class="field">
                    <label for="parent_phone">Telephone *</label>
                    <input id="parent_phone" name="parent_phone" required placeholder="+237 6.. .. .. .."
                           value="<?= $this->e($value('parent_phone')) ?>">
                </div>
                <div class="field">
                    <label for="parent_email">Email</label>
                    <input id="parent_email" name="parent_email" type="email"
                           value="<?= $this->e($value('parent_email')) ?>">
                </div>
            </div>

            <button type="submit" class="button">Envoyer la demande</button>
        </form>
    <?php endif; ?>

    <p class="muted" style="margin-top:1.25rem">
        Vous dirigez un etablissement ? <a href="/demande-etablissement">Demandez son ouverture</a>.
    </p>
</div>
