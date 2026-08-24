<?php
/**
 * Ouverture directe d un etablissement par le Super Admin.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, string> $types
 * @var array<string, string> $regions
 * @var array<string, mixed> $old
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Ouvrir un etablissement';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<div class="page-header">
    <div>
        <h1>Ouvrir un etablissement</h1>
        <p class="subtitle">
            L etablissement, le compte de son responsable, sa structure
            pedagogique et son annee scolaire sont crees ensemble. Il est
            utilisable immediatement.
        </p>
    </div>
    <a class="button button--secondary" href="/admin/parc">Retour au parc</a>
</div>

<form method="post" action="/admin/parc">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <div class="card">
        <h2>L etablissement</h2>
        <div class="grid-2">
            <div class="field">
                <label for="name">Nom *</label>
                <input id="name" name="name" required value="<?= $this->e($value('name')) ?>">
            </div>
            <div class="field">
                <label for="code">Code court *</label>
                <input id="code" name="code" required maxlength="20" value="<?= $this->e($value('code')) ?>">
            </div>
            <div class="field">
                <label for="type">Type *</label>
                <select id="type" name="type" required>
                    <?php foreach ($types as $code => $label) : ?>
                        <option value="<?= $this->e($code) ?>" <?= $value('type') === $code ? 'selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">Statut</label>
                <select id="status" name="status">
                    <option value="PRIVE" <?= $value('status') === 'PRIVE' ? 'selected' : '' ?>>Prive</option>
                    <option value="PUBLIC" <?= $value('status') === 'PUBLIC' ? 'selected' : '' ?>>Public</option>
                </select>
            </div>
            <div class="field">
                <label for="region">Region</label>
                <select id="region" name="region">
                    <option value="">Choisir...</option>
                    <?php foreach ($regions as $code => $label) : ?>
                        <option value="<?= $this->e($code) ?>" <?= $value('region') === $code ? 'selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="city">Ville</label>
                <input id="city" name="city" value="<?= $this->e($value('city')) ?>">
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
    </div>

    <div class="card">
        <h2>Le responsable</h2>
        <p class="muted" style="margin-top:-0.5rem">
            Ce compte recoit les droits d administration de cet etablissement,
            et d aucun autre. Un mot de passe provisoire est genere et lui est
            adresse par courrier.
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
        </div>
    </div>

    <button type="submit" class="button">Creer l etablissement</button>
</form>
