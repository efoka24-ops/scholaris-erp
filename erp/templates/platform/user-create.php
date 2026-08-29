<?php
/**
 * Nomination d un administrateur de la plateforme.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $old
 * @var array<string, string> $regions
 * @var list<string> $departments
 * @var array<string, string> $ministries
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Nommer un administrateur';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<div class="page-header">
    <div>
        <h1>Nommer un administrateur de la plateforme</h1>
        <p class="subtitle">
            Ce compte n appartiendra a aucun etablissement et pourra instruire
            les demandes d ouverture, comme le votre.
        </p>
    </div>
    <a class="button button--secondary" href="/admin/comptes">Retour aux comptes</a>
</div>

<div class="card">
    <div class="alert alert--info">
        Un seul compte d administration nationale est un point de defaillance :
        s il se perd, la plateforme n a plus d arbitre pour les demandes
        d ouverture. En nommer un second est une precaution, pas un confort.
    </div>

    <form method="post" action="/admin/comptes">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <div class="grid-2">
            <div class="field">
                <label for="last_name">Nom *</label>
                <input id="last_name" name="last_name" required value="<?= $this->e($value('last_name')) ?>">
            </div>
            <div class="field">
                <label for="first_name">Prenom *</label>
                <input id="first_name" name="first_name" required value="<?= $this->e($value('first_name')) ?>">
            </div>
            <div class="field">
                <label for="email">Email *</label>
                <input id="email" name="email" type="email" required value="<?= $this->e($value('email')) ?>">
            </div>
        </div>

        <h2 style="margin-top:1.5rem">Perimetre</h2>
        <p class="muted" style="margin-top:-0.5rem">
            Ce que ce compte pourra voir. Le perimetre se choisit ici, et non
            apres coup : creer un compte national puis le restreindre laisse
            entre les deux une fenetre ou il voit tout.
        </p>

        <div class="grid-2">
            <div class="field">
                <label for="scope_type">Etendue</label>
                <select id="scope_type" name="scope_type">
                    <option value="PLATFORM">Tout le territoire — administrateur de la plateforme</option>
                    <option value="MINISTRY" <?= $value('scope_type') === 'MINISTRY' ? 'selected' : '' ?>>
                        Une tutelle — administrateur de ministere
                    </option>
                    <option value="REGION" <?= $value('scope_type') === 'REGION' ? 'selected' : '' ?>>
                        Une region — delegue regional
                    </option>
                    <option value="DEPARTMENT" <?= $value('scope_type') === 'DEPARTMENT' ? 'selected' : '' ?>>
                        Un departement — delegue departemental
                    </option>
                </select>
            </div>
            <div class="field">
                <label for="scope_value">Tutelle, region ou departement</label>
                <input id="scope_value" name="scope_value" list="scope-values"
                       placeholder="Laisser vide pour le territoire entier"
                       value="<?= $this->e($value('scope_value')) ?>">
                <datalist id="scope-values">
                    <?php foreach ($ministries as $code => $label) : ?>
                        <option value="<?= $this->e($code) ?>"><?= $this->e($label) ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($regions as $code => $label) : ?>
                        <option value="<?= $this->e($code) ?>">Region <?= $this->e($label) ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($departments as $department) : ?>
                        <option value="<?= $this->e($department) ?>">Departement <?= $this->e($department) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <p class="muted">
            Un delegue consulte son territoire ; il n administre pas la
            plateforme et ne cree pas d etablissement.
        </p>

        <button type="submit" class="button">Creer le compte</button>
    </form>
</div>
