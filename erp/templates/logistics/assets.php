<?php
/**
 * Module 17 : patrimoine.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $assets
 * @var array<int, array<string, mixed>> $maintenances
 * @var list<string> $categories
 * @var list<string> $statuses
 * @var string $category
 * @var float $totalValue
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Patrimoine';
$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Patrimoine</h1>
<p class="subtitle">
    <?= $this->number(count($assets)) ?> bien(s) &middot;
    valeur d acquisition cumulee <?= $this->money($totalValue) ?>
</p>

<form method="get" action="/patrimoine" class="filters">
    <select name="category">
        <option value="">Toutes les categories</option>
        <?php foreach ($categories as $item) : ?>
            <option value="<?= $this->e($item) ?>" <?= $category === $item ? 'selected' : '' ?>>
                <?= $this->e($item) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button--secondary">Filtrer</button>
</form>

<?php if ($assets === []) : ?>
    <div class="card"><p class="muted">Aucun bien a l inventaire.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr>
            <th>Designation</th><th>Categorie</th><th>Etat</th>
            <th>Acquis le</th><th>Valeur</th><th>Emplacement</th><th>Maintenance</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($assets as $asset) : ?>
            <tr>
                <td><?= $this->e($asset['name']) ?></td>
                <td><span class="badge"><?= $this->e($asset['category']) ?></span></td>
                <td><?= $this->e($asset['status']) ?></td>
                <td class="font-mono"><?= $this->date($asset['acquisition_date']) ?></td>
                <td><?= $asset['acquisition_value'] !== null ? $this->money($asset['acquisition_value']) : '-' ?></td>
                <td class="muted"><?= $this->e($asset['location'] ?: '-') ?></td>
                <td>
                    <?php if ($rbac->allows('assets:update')) : ?>
                        <form method="post" action="/patrimoine/<?= $this->e($asset['id']) ?>/maintenance"
                              style="display:flex;gap:.4rem;flex-wrap:wrap">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <input type="date" name="date" value="<?= date('Y-m-d') ?>" style="width:150px" required>
                            <input type="text" name="description" placeholder="Intervention" required style="width:160px">
                            <input type="text" name="cost" placeholder="Cout" style="width:90px">
                            <button type="submit" class="link-button">Ajouter</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($maintenances !== []) : ?>
    <div class="card">
        <h2>Dernieres interventions</h2>
        <table class="table">
            <thead><tr><th>Date</th><th>Bien</th><th>Intervention</th><th>Technicien</th><th>Cout</th></tr></thead>
            <tbody>
            <?php foreach ($maintenances as $maintenance) : ?>
                <tr>
                    <td class="font-mono"><?= $this->date($maintenance['date']) ?></td>
                    <td><?= $this->e($maintenance['asset_name']) ?></td>
                    <td><?= $this->e($maintenance['description']) ?></td>
                    <td class="muted"><?= $this->e($maintenance['technician'] ?: '-') ?></td>
                    <td><?= $maintenance['cost'] !== null ? $this->money($maintenance['cost']) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($rbac->allows('assets:create')) : ?>
    <form method="post" action="/patrimoine" class="card" style="margin-top:1.5rem">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Inscrire un bien</h2>

        <div class="grid-2">
            <div class="field">
                <label for="name">Designation *</label>
                <input id="name" name="name" required value="<?= $this->e($value('name')) ?>">
            </div>
            <div class="field">
                <label for="a_category">Categorie *</label>
                <select id="a_category" name="category" required>
                    <?php foreach ($categories as $item) : ?>
                        <option value="<?= $this->e($item) ?>"><?= $this->e($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">Etat *</label>
                <select id="status" name="status" required>
                    <?php foreach ($statuses as $item) : ?>
                        <option value="<?= $this->e($item) ?>"><?= $this->e($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="acquisition_date">Date d acquisition</label>
                <input id="acquisition_date" name="acquisition_date" type="date">
            </div>
            <div class="field">
                <label for="acquisition_value">Valeur (FCFA)</label>
                <input id="acquisition_value" name="acquisition_value" inputmode="numeric">
            </div>
            <div class="field">
                <label for="location">Emplacement</label>
                <input id="location" name="location">
            </div>
        </div>

        <button type="submit" class="button">Inscrire a l inventaire</button>
    </form>
<?php endif; ?>
