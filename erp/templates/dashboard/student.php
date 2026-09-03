<?php
/**
 * Tableau de bord eleve : ses propres donnees uniquement.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $student
 * @var array<string, mixed>|null $enrollment
 * @var array<string, mixed>|null $invoice
 * @var array<int, array<string, mixed>> $results
 * @var int $absences
 */
$this->extends('layouts.app');
$title = 'Mon espace';
?>
<h1>Bonjour <?= $this->e($student['first_name']) ?></h1>
<p class="subtitle">
    <?= $this->e($student['matricule']) ?>
    <?php if ($enrollment !== null) : ?>
        &middot; <?= $this->e($enrollment['classroom_name']) ?>
        (<?= $this->e($enrollment['level_name']) ?>)
    <?php endif; ?>
</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Absences</div>
        <div class="stat__value"><?= $this->number($absences) ?></div>
    </div>

    <?php if ($results !== []) : ?>
        <div class="stat">
            <div class="stat__label">Dernière moyenne</div>
            <div class="stat__value"><?= $this->e(number_format((float) $results[0]['general_average'], 2, ',', ' ')) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">Rang</div>
            <div class="stat__value">
                <?= $this->e($results[0]['rank_position']) ?>
                <?php if ($results[0]['total_students']) : ?>
                    <span style="font-size:1rem;color:rgba(242,240,232,.4)">/ <?= $this->e($results[0]['total_students']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($invoice !== null) : ?>
        <div class="stat">
            <div class="stat__label">Reste a payer</div>
            <div class="stat__value stat__value--money"><?= $this->money($invoice['balance']) ?></div>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Mes resultats</h2>

    <?php if ($results === []) : ?>
        <p class="muted">
            Aucun résultat publié pour le moment. Les moyennes apparaissent une
            fois le conseil de classe tenu.
        </p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Séquence</th><th>Moyenne</th><th>Rang</th><th>Mention</th><th>Appreciation</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $result) : ?>
                <tr>
                    <td>Séquence <?= $this->e($result['period_number']) ?></td>
                    <td class="font-mono" style="font-weight:700;color:<?= (float) $result['general_average'] >= 10 ? 'var(--green-dark)' : 'var(--red)' ?>">
                        <?= $this->e(number_format((float) $result['general_average'], 2, ',', ' ')) ?>
                    </td>
                    <td class="font-mono">
                        <?= $this->e($result['rank_position']) ?>
                        <?php if ($result['total_students']) : ?>
                            / <?= $this->e($result['total_students']) ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge"><?= $this->e($result['mention']) ?></span></td>
                    <td class="muted"><?= $this->e($result['teacher_comment'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
