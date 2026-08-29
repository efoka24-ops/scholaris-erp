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
<h1>Paramètres</h1>
<p class="subtitle">
    <?= $this->e($tenant['name'] ?? '') ?>
    &middot; <span class="badge"><?= $this->e($features->typeLabel()) ?></span>
</p>

<div class="card">
    <h2>Type d'établissement</h2>
    <p class="muted" style="margin-top:-0.5rem">
        Le type determine ce qui existe dans votre interface. Les fonctionnalités
        qui ne concernent pas un <?= $this->e(strtolower($features->typeLabel())) ?>
        ne sont ni affichees ni activables : elles n'existent pas ici.
    </p>

    <dl class="details">
        <dt>Type</dt><dd><strong><?= $this->e($features->typeLabel()) ?></strong></dd>
        <dt>Code établissement</dt><dd class="font-mono"><?= $this->e($tenant['code'] ?? '-') ?></dd>
        <dt>Terme pour les élèves</dt><dd><?= $this->e($features->label('students', 'Eleves')) ?></dd>
        <dt>Terme pour les classes</dt><dd><?= $this->e($features->label('classrooms', 'Classes')) ?></dd>
    </dl>
</div>

<form method="post" action="/parametres" class="card">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
    <h2>Fonctionnalités optionnelles</h2>

    <?php if ($optional === []) : ?>
        <p class="muted">
            Aucune fonctionnalité optionnelle pour ce type d'établissement :
            tout ce qui vous concerne est déjà actif.
        </p>
    <?php else : ?>
        <p class="muted" style="margin-top:-0.5rem">
            Ces modules sont disponibles pour votre type d'établissement mais
            masques par défaut. Activez ceux dont vous avez l'usage.
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

    <?php endif; ?>

    <h2 style="margin-top:2rem">Règles de calcul</h2>
    <p class="muted" style="margin-top:-0.5rem">
        Ces réglages changent les moyennes déjà calculees des le prochain
        calcul. Ils ne modifient pas les bulletins déjà publiés.
    </p>

    <div class="grid-2">
        <div class="field">
            <label for="scale">Barème</label>
            <input id="scale" name="scale" type="number" min="1" max="100" step="1"
                   value="<?= $this->e((string) (int) $rules->scale()) ?>">
            <span class="muted" style="font-size:0.8125rem">
                Les notes saisies sur un autre barème sont ramenees sur celui-ci.
            </span>
        </div>
        <div class="field">
            <label for="pass_mark">Seuil de réussite</label>
            <input id="pass_mark" name="pass_mark" type="number" min="0" step="0.5"
                   value="<?= $this->e((string) $rules->passMark()) ?>">
        </div>
        <div class="field">
            <label for="rounding">Arrondi</label>
            <select id="rounding" name="rounding">
                <option value="0" <?= $rules->rounding() === 0 ? 'selected' : '' ?>>Entier</option>
                <option value="1" <?= $rules->rounding() === 1 ? 'selected' : '' ?>>Dixième</option>
                <option value="2" <?= $rules->rounding() === 2 ? 'selected' : '' ?>>Centième</option>
            </select>
        </div>
        <div class="field">
            <label for="unjustified_absence">Absence non justifiee</label>
            <select id="unjustified_absence" name="unjustified_absence">
                <option value="ZERO" <?= $rules->countsUnjustifiedAbsenceAsZero() ? 'selected' : '' ?>>
                    Compte zero
                </option>
                <option value="IGNORED" <?= $rules->countsUnjustifiedAbsenceAsZero() ? '' : 'selected' ?>>
                    N'entre pas dans la moyenne
                </option>
            </select>
            <span class="muted" style="font-size:0.8125rem">
                Une absence justifiee n'entre jamais dans la moyenne.
            </span>
        </div>
    </div>

    <h3 style="margin-top:1.5rem">Mentions</h3>
    <p class="muted" style="margin-top:-0.25rem">
        Du seuil le plus haut au plus bas. Effacer un libelle retiré la mention.
    </p>

    <?php
    // Une ligne vide en fin de liste permet d'ajouter une mention sans autre
    // ecran ni bouton.
    $mentions = $rules->mentions();
    $mentions[] = ['threshold' => '', 'label' => ''];
    ?>
    <?php foreach ($mentions as $index => $mention) : ?>
        <div class="inline-form" style="gap:0.75rem;margin-bottom:0.5rem">
            <input name="mention[<?= (int) $index ?>][threshold]" type="number" min="0" step="0.5"
                   style="width:7rem" placeholder="Seuil"
                   value="<?= $this->e((string) $mention['threshold']) ?>">
            <input name="mention[<?= (int) $index ?>][label]" style="flex:1 1 14rem"
                   placeholder="Libelle de la mention"
                   value="<?= $this->e((string) $mention['label']) ?>">
        </div>
    <?php endforeach; ?>

    <div class="field" style="max-width:22rem;margin-top:0.75rem">
        <label for="fail_label">En dessous du dernier seuil</label>
        <input id="fail_label" name="fail_label" value="<?= $this->e($rules->failLabel()) ?>">
    </div>

    <div class="form-actions" style="margin-top:1.5rem">
        <button type="submit" class="button">Enregistrer</button>
    </div>
</form>

<div class="card">
    <h2>Actives d'office</h2>
    <p class="muted" style="margin-top:-0.5rem">
        Ces fonctionnalités sont propres a votre type d'établissement et ne se
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
