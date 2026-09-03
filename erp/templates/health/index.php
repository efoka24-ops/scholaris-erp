<?php
/**
 * Module 12 : sante scolaire.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $students
 * @var string $search
 * @var int $withRecord
 */
$this->extends('layouts.app');
$title = 'Santé scolaire';
?>
<h1>Santé scolaire</h1>
<p class="subtitle">
    <?= $this->number($withRecord) ?> dossier(s) médical(aux) sur <?= $this->number(count($students)) ?> élève(s) affiché(s)
</p>

<form method="get" action="/health" class="filters">
    <input type="search" name="q" placeholder="Nom, prenom ou matricule" value="<?= $this->e($search) ?>">
    <button type="submit" class="button--secondary">Rechercher</button>
</form>

<?php if ($students === []) : ?>
    <div class="card"><p class="muted">Aucun élève ne correspond a cette recherche.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Matricule</th><th>Élève</th><th>Groupe sanguin</th><th>Allergies</th><th>Dossier</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $student) : ?>
            <tr>
                <td class="font-mono"><?= $this->e($student['matricule']) ?></td>
                <td><?= $this->e($student['last_name'].' '.$student['first_name']) ?></td>
                <td><?= $this->e($student['blood_type'] ?: ($student['blood_group'] ?: '-')) ?></td>
                <td><?= $this->e($student['allergies'] ?: '-') ?></td>
                <td>
                    <?php if ($student['record_id'] !== null) : ?>
                        <span class="badge">renseigne</span>
                    <?php else : ?>
                        <span class="muted">absent</span>
                    <?php endif; ?>
                </td>
                <td><a href="/health/<?= $this->e($student['id']) ?>">Ouvrir</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
