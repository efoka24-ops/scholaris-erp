<?php
/**
 * Annee scolaire et periodes de saisie.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $years
 * @var array<string, list<array<string, mixed>>> $periods
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Annee scolaire';

$nextYear = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
?>
<h1>Annee scolaire</h1>
<p class="subtitle">
    Une seule annee est active, et une seule sequence ouverte a la saisie.
    Tant qu aucune sequence n est ouverte, ni notes ni appel ne peuvent etre
    enregistres.
</p>

<?php if ($years === []) : ?>
    <div class="alert alert--error">
        Aucune annee scolaire n est encore ouverte. Creez-en une pour commencer :
        les inscriptions, l appel et les notes en dependent.
    </div>
<?php endif; ?>

<?php if ($rbac->allows('academic-years:create')) : ?>
    <div class="card">
        <h2>Ouvrir une annee</h2>
        <form method="post" action="/annees-scolaires" class="inline-form" style="gap:0.75rem;align-items:flex-end">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <div class="field" style="margin-bottom:0">
                <label for="start_year">Annee de rentree</label>
                <input id="start_year" name="start_year" type="number" min="2000" max="2100"
                       value="<?= $nextYear ?>" required style="width:8rem">
            </div>
            <button type="submit" class="button">Creer et activer</button>
        </form>
        <p class="muted" style="margin-top:0.75rem">
            Six sequences sont creees automatiquement. La premiere est ouverte a
            la saisie, les suivantes le seront a votre main.
        </p>
    </div>
<?php endif; ?>

<?php foreach ($years as $year) : ?>
    <?php $isActive = (string) $year['status'] === 'ACTIVE'; ?>
    <div class="card">
        <div class="inline-form" style="justify-content:space-between;align-items:center">
            <h2 style="margin:0">
                <?= $this->e($year['label']) ?>
                <?php if ($isActive) : ?>
                    <span class="section-tag" style="color:#c8ff00">EN COURS</span>
                <?php else : ?>
                    <span class="section-tag muted">CLOSE</span>
                <?php endif; ?>
            </h2>

            <?php if (! $isActive && $rbac->allows('academic-years:update')) : ?>
                <form method="post" action="/annees-scolaires/<?= $this->e($year['id']) ?>/activer" class="inline-form">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="button button--secondary">Rendre active</button>
                </form>
            <?php endif; ?>
        </div>

        <table class="table" style="margin-top:1rem">
            <thead>
                <tr>
                    <th>Sequence</th>
                    <th>Du</th>
                    <th>Au</th>
                    <th>Saisie</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($periods[(string) $year['id']] ?? [] as $period) : ?>
                <?php $isOpen = (string) $period['grading_status'] === 'OPEN'; ?>
                <tr>
                    <td>Sequence <?= (int) $period['number'] ?></td>
                    <td><?= $this->e($period['start_date']) ?></td>
                    <td><?= $this->e($period['end_date']) ?></td>
                    <td>
                        <?php if ($isOpen) : ?>
                            <span class="badge badge--success">Ouverte</span>
                        <?php else : ?>
                            <span class="badge">Fermee</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?php if ($rbac->allows('academic-years:update')) : ?>
                            <?php if ($isOpen) : ?>
                                <form method="post" action="/periodes/<?= $this->e($period['id']) ?>/fermer" class="inline-form">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <button type="submit" class="link-button">Fermer la saisie</button>
                                </form>
                            <?php else : ?>
                                <form method="post" action="/periodes/<?= $this->e($period['id']) ?>/ouvrir" class="inline-form">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <button type="submit" class="link-button">Ouvrir la saisie</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
