<?php
/**
 * Tableau de bord de direction : vue d'ensemble de l'etablissement.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $academicYear, $openPeriod
 * @var array<string, float|int> $stats
 * @var array<int, array<string, mixed>> $recentStudents
 * @var int $pendingApplications
 * @var \Scholaris\Auth\Rbac $rbac
 * @var \Scholaris\Tenant\Features $features
 */
$this->extends('layouts.app');
$title = 'Tableau de bord';
$studentsLabel = $features->label('students', 'Eleves');
?>
<h1>Tableau de bord</h1>
<p class="subtitle">
    <?php if ($academicYear !== null) : ?>
        Annee academique <?= $this->e($academicYear['label']) ?>
    <?php else : ?>
        Aucune annee academique active
    <?php endif; ?>
    &middot; <?= $this->e($features->typeLabel()) ?>
</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label"><?= $this->e($studentsLabel) ?> actifs</div>
        <div class="stat__value"><?= $this->number($stats['students']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Inscriptions</div>
        <div class="stat__value"><?= $this->number($stats['enrollments']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label"><?= $this->e($features->label('classrooms', 'Classes')) ?></div>
        <div class="stat__value"><?= $this->number($stats['classrooms']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Personnel</div>
        <div class="stat__value"><?= $this->number($stats['staff']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Recouvrement</div>
        <div class="stat__value"><?= $this->e(number_format($stats['recovery'], 1, ',', ' ')) ?> %</div>
    </div>
    <div class="stat">
        <div class="stat__label">Reste a recouvrer</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['outstanding']) ?></div>
    </div>
</div>

<div class="card">
    <h2>A traiter</h2>
    <dl class="details">
        <dt>Periode de saisie</dt>
        <dd>
            <?php if ($openPeriod !== null) : ?>
                Sequence <?= $this->e($openPeriod['number']) ?> ouverte
            <?php else : ?>
                <span class="muted">aucune periode ouverte</span>
            <?php endif; ?>
        </dd>
        <dt>Pre-inscriptions en attente</dt>
        <dd>
            <?= $this->number($pendingApplications) ?>
            <?php if ($pendingApplications > 0) : ?>
                <span class="badge">a instruire</span>
            <?php endif; ?>
        </dd>
    </dl>
</div>

<?php if ($rbac->allows('students:read')) : ?>
    <div class="card">
        <h2>Derniers <?= $this->e(strtolower($studentsLabel)) ?> enregistres</h2>

        <?php if ($recentStudents === []) : ?>
            <p class="muted">Aucun dossier enregistre pour le moment.</p>
        <?php else : ?>
            <table class="table">
                <thead><tr><th>Matricule</th><th>Nom</th><th>Naissance</th></tr></thead>
                <tbody>
                <?php foreach ($recentStudents as $student) : ?>
                    <tr>
                        <td><a href="/students/<?= $this->e($student['id']) ?>"><?= $this->e($student['matricule']) ?></a></td>
                        <td><?= $this->e($student['last_name'].' '.$student['first_name']) ?></td>
                        <td class="font-mono"><?= $this->date($student['date_of_birth']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
