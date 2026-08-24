<?php
/**
 * Fiche d un etablissement, modifiable par le Super Admin.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $tenant
 * @var array<string, string> $types
 * @var array<string, string> $regions
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Modifier '.$tenant['name'];
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($tenant['name']) ?></h1>
        <p class="subtitle">Code <?= $this->e($tenant['code']) ?> &middot; fiche etablissement</p>
    </div>
    <a class="button button--secondary" href="/admin/parc">Retour au parc</a>
</div>

<form method="post" action="/admin/parc/<?= $this->e($tenant['id']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <div class="card">
        <h2>Identite</h2>
        <div class="grid-2">
            <div class="field">
                <label for="name">Nom *</label>
                <input id="name" name="name" required value="<?= $this->e($tenant['name']) ?>">
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <?php foreach ($types as $code => $label) : ?>
                        <option value="<?= $this->e($code) ?>" <?= (string) $tenant['type'] === $code ? 'selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">Statut</label>
                <select id="status" name="status">
                    <option value="PRIVE" <?= (string) $tenant['status'] === 'PRIVE' ? 'selected' : '' ?>>Prive</option>
                    <option value="PUBLIC" <?= (string) $tenant['status'] === 'PUBLIC' ? 'selected' : '' ?>>Public</option>
                </select>
            </div>
            <div class="field">
                <label for="region">Region</label>
                <select id="region" name="region">
                    <option value="">Non renseignee</option>
                    <?php foreach ($regions as $code => $label) : ?>
                        <option value="<?= $this->e($code) ?>" <?= (string) ($tenant['region'] ?? '') === $code ? 'selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="city">Ville</label>
                <input id="city" name="city" value="<?= $this->e($tenant['city'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="phone">Telephone</label>
                <input id="phone" name="phone" value="<?= $this->e($tenant['phone'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="<?= $this->e($tenant['email'] ?? '') ?>">
            </div>
        </div>
        <div class="field">
            <label for="address">Adresse</label>
            <input id="address" name="address" value="<?= $this->e($tenant['address'] ?? '') ?>">
        </div>

        <p class="muted">
            Changer le type change les modules ouverts et le vocabulaire, mais
            ne touche pas aux niveaux deja crees : supprimer des classes qui
            portent des eleves ne se decide pas depuis un formulaire.
        </p>
    </div>

    <div class="card">
        <h2>Pre-inscription en ligne</h2>
        <label class="inline-form" style="gap:0.6rem">
            <input type="checkbox" name="public_enrollment_enabled" value="1"
                   <?= (int) $tenant['public_enrollment_enabled'] === 1 ? 'checked' : '' ?>>
            <span>Les familles peuvent deposer un dossier depuis le site public</span>
        </label>
    </div>

    <button type="submit" class="button">Enregistrer</button>
</form>
