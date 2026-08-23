<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $structures
 * @var array<int, array<string, mixed>> $levels
 * @var string|null $academicYearId
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Grilles tarifaires';
$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Grilles tarifaires</h1>
<p class="subtitle">
    Montant de la scolarite par niveau. Une grille sans niveau s applique par
    defaut a tous les niveaux qui n en ont pas de dediee.
</p>

<?php if ($academicYearId === null) : ?>
    <div class="alert alert--error">
        Aucune annee academique active : les grilles ne peuvent pas etre creees.
    </div>
<?php endif; ?>

<?php if ($structures === []) : ?>
    <div class="card">
        <p class="muted">
            Aucune grille definie. Sans grille, les inscriptions se font mais
            aucune facture n est generee.
        </p>
    </div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Libelle</th><th>Niveau</th><th>Annee</th><th>Montant</th><th>Tranches</th></tr>
        </thead>
        <tbody>
        <?php foreach ($structures as $structure) : ?>
            <tr>
                <td><?= $this->e($structure['name']) ?></td>
                <td>
                    <?php if ($structure['level_name']) : ?>
                        <?= $this->e($structure['level_name']) ?>
                    <?php else : ?>
                        <span class="badge">tous niveaux</span>
                    <?php endif; ?>
                </td>
                <td><?= $this->e($structure['year_label']) ?></td>
                <td><?= $this->money($structure['total_amount']) ?></td>
                <td><?= $this->number($structure['installments']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($rbac->allows('fee-structures:create') && $academicYearId !== null) : ?>
    <form method="post" action="/finance/fee-structures" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Nouvelle grille</h2>

        <div class="grid-2">
            <div class="field">
                <label for="name">Libelle *</label>
                <input id="name" name="name" required placeholder="Scolarite 6eme"
                       value="<?= $this->e($value('name')) ?>">
            </div>
            <div class="field">
                <label for="total_amount">Montant total (FCFA) *</label>
                <input id="total_amount" name="total_amount" type="text" inputmode="numeric" required
                       value="<?= $this->e($value('total_amount')) ?>">
            </div>
            <div class="field">
                <label for="level_id">Niveau</label>
                <select id="level_id" name="level_id">
                    <option value="">Tous les niveaux (grille par defaut)</option>
                    <?php foreach ($levels as $level) : ?>
                        <option value="<?= $this->e($level['id']) ?>" <?= $value('level_id') === $level['id'] ? 'selected' : '' ?>>
                            <?= $this->e($level['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="button">Enregistrer la grille</button>
    </form>
<?php endif; ?>
