<?php
/**
 * Module 16 : cantine.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $menus
 * @var string $from
 * @var list<string> $meals
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Cantine';

$labels = [
    'PETIT_DEJEUNER' => 'Petit-dejeuner',
    'DEJEUNER' => 'Dejeuner',
    'GOUTER' => 'Gouter',
    'DINER' => 'Diner',
];

$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Cantine</h1>
<p class="subtitle">Menus a partir du <?= $this->date($from) ?></p>

<form method="get" action="/catering" class="filters">
    <input type="date" name="from" value="<?= $this->e($from) ?>">
    <button type="submit" class="button--secondary">Afficher</button>
</form>

<?php if ($menus === []) : ?>
    <div class="card"><p class="muted">Aucun menu enregistre a partir de cette date.</p></div>
<?php else : ?>
    <table class="table">
        <thead><tr><th>Date</th><th>Service</th><th>Composition</th></tr></thead>
        <tbody>
        <?php foreach ($menus as $menu) : ?>
            <tr>
                <td class="font-mono"><?= $this->date($menu['date']) ?></td>
                <td><span class="badge"><?= $this->e($labels[$menu['meal']] ?? $menu['meal']) ?></span></td>
                <td><?= $this->e($menu['items'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($rbac->allows('catering:create')) : ?>
    <form method="post" action="/catering" class="card" style="margin-top:1.5rem">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Enregistrer un menu</h2>
        <p class="muted" style="margin-top:-0.5rem">
            Un seul menu par date et par service : reenregistrer remplace le precedent.
        </p>

        <div class="grid-2">
            <div class="field">
                <label for="date">Date *</label>
                <input id="date" name="date" type="date" required
                       value="<?= $this->e($value('date') ?: date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label for="meal">Service *</label>
                <select id="meal" name="meal" required>
                    <?php foreach ($meals as $meal) : ?>
                        <option value="<?= $this->e($meal) ?>" <?= $value('meal') === $meal ? 'selected' : '' ?>>
                            <?= $this->e($labels[$meal] ?? $meal) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="items">Composition *</label>
            <textarea id="items" name="items" rows="2" required
                      placeholder="Riz sauce arachide, banane"><?= $this->e($value('items')) ?></textarea>
        </div>

        <button type="submit" class="button">Enregistrer</button>
    </form>
<?php endif; ?>
