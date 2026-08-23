<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $academicYear
 * @var array<string, float|int> $stats
 * @var array<int, array<string, mixed>> $recentStudents
 * @var \Scholaris\Auth\Rbac $rbac
 */
$this->extends('layouts.app');
$title = 'Tableau de bord';
?>
<h1>Tableau de bord</h1>
<p class="subtitle">
    <?php if ($academicYear !== null) : ?>
        Annee academique <?= $this->e($academicYear['label']) ?>
    <?php else : ?>
        Aucune annee academique active
    <?php endif; ?>
</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Eleves actifs</div>
        <div class="stat__value"><?= $this->number($stats['students']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Classes</div>
        <div class="stat__value"><?= $this->number($stats['classrooms']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Inscriptions actives</div>
        <div class="stat__value"><?= $this->number($stats['enrollments']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Total facture</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['billed']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Encaisse</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['collected']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Reste a recouvrer</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['outstanding']) ?></div>
    </div>
</div>

<?php if ($rbac->allows('students:read')) : ?>
    <div class="card">
        <h2>Derniers eleves enregistres</h2>

        <?php if ($recentStudents === []) : ?>
            <p class="muted">Aucun eleve enregistre pour le moment.</p>
        <?php else : ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Matricule</th>
                    <th>Nom</th>
                    <th>Date de naissance</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentStudents as $student) : ?>
                    <tr>
                        <td><a href="/students/<?= $this->e($student['id']) ?>"><?= $this->e($student['matricule']) ?></a></td>
                        <td><?= $this->e($student['last_name'].' '.$student['first_name']) ?></td>
                        <td><?= $this->date($student['date_of_birth']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
