<?php
/**
 * Parametres : fonctionnalites de l'etablissement.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $tenant
 * @var \Scholaris\Tenant\Features $features
 * @var array<string, array{name: string, enabled: bool}> $optional
 * @var list<string> $alwaysOn
 * @var array<string, string> $types
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Parametres';
?>
<h1>Parametres</h1>
<p class="subtitle">
    <?= $this->e($tenant['name'] ?? '') ?>
    &middot; <span class="badge"><?= $this->e($features->typeLabel()) ?></span>
</p>

<div class="card">
    <h2>Type d etablissement</h2>
    <p class="muted" style="margin-top:-0.5rem">
        Le type determine ce qui existe dans votre interface. Les fonctionnalites
        qui ne concernent pas un <?= $this->e(strtolower($features->typeLabel())) ?>
        ne sont ni affichees ni activables : elles n existent pas ici.
    </p>

    <dl class="details">
        <dt>Type</dt><dd><strong><?= $this->e($features->typeLabel()) ?></strong></dd>
        <dt>Code etablissement</dt><dd class="font-mono"><?= $this->e($tenant['code'] ?? '-') ?></dd>
        <dt>Terme pour les eleves</dt><dd><?= $this->e($features->label('students', 'Eleves')) ?></dd>
        <dt>Terme pour les classes</dt><dd><?= $this->e($features->label('classrooms', 'Classes')) ?></dd>
    </dl>
</div>

<form method="post" action="/parametres" class="card">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
    <h2>Fonctionnalites optionnelles</h2>

    <?php if ($optional === []) : ?>
        <p class="muted">
            Aucune fonctionnalite optionnelle pour ce type d etablissement :
            tout ce qui vous concerne est deja actif.
        </p>
    <?php else : ?>
        <p class="muted" style="margin-top:-0.5rem">
            Ces modules sont disponibles pour votre type d etablissement mais
            masques par defaut. Activez ceux dont vous avez l usage.
        </p>

        <?php foreach ($optional as $key => $entry) : ?>
            <div class="checkbox">
                <input type="checkbox" id="f_<?= $this->e(str_replace('.', '_', $key)) ?>"
                       name="features[<?= $this->e($key) ?>]" value="1"
                       <?= $entry['enabled'] ? 'checked' : '' ?>>
                <label for="f_<?= $this->e(str_replace('.', '_', $key)) ?>">
                    <?= $this->e($entry['name']) ?>
                    <span class="font-mono muted" style="font-size:.7rem"><?= $this->e($key) ?></span>
                </label>
            </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="button">Enregistrer</button>
        </div>
    <?php endif; ?>
</form>

<div class="card">
    <h2>Actives d office</h2>
    <p class="muted" style="margin-top:-0.5rem">
        Ces fonctionnalites sont propres a votre type d etablissement et ne se
        desactivent pas.
    </p>

    <div class="guide__validate">
        <?php foreach ($alwaysOn as $name) : ?>
            <span class="guide__check"
                  class="badge badge--success">
                ✓ <?= $this->e($name) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
