<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $students
 * @var int $total, $page, $pages, $perPage
 * @var string $search, $status
 * @var list<string> $statuses
 * @var \Scholaris\Auth\Rbac $rbac
 */
$this->extends('layouts.app');
$title = 'Eleves';
?>
<div class="page-header">
    <div>
        <h1>Eleves</h1>
        <p class="subtitle"><?= $this->number($total) ?> dossier(s)</p>
    </div>
    <?php if ($rbac->allows('students:create')) : ?>
        <a class="button" href="/students/create">Nouvel eleve</a>
    <?php endif; ?>
</div>

<form method="get" action="/students" class="filters">
    <input type="search" name="q" placeholder="Nom, prenom ou matricule"
           value="<?= $this->e($search) ?>">
    <select name="status">
        <option value="">Tous les statuts</option>
        <?php foreach ($statuses as $option) : ?>
            <option value="<?= $this->e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                <?= $this->e($option) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Filtrer</button>
</form>

<?php if ($students === []) : ?>
    <div class="card"><p class="muted">Aucun eleve ne correspond a cette recherche.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr>
            <th>Matricule</th>
            <th>Nom</th>
            <th>Prenom</th>
            <th>Naissance</th>
            <th>Sexe</th>
            <th>Statut</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $student) : ?>
            <tr>
                <td><a href="/students/<?= $this->e($student['id']) ?>"><?= $this->e($student['matricule']) ?></a></td>
                <td><?= $this->e($student['last_name']) ?></td>
                <td><?= $this->e($student['first_name']) ?></td>
                <td><?= $this->date($student['date_of_birth']) ?></td>
                <td><?= $this->e($student['gender'] === 'MALE' ? 'M' : 'F') ?></td>
                <td><span class="badge"><?= $this->e($student['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $pages; $p++) : ?>
                <a class="pagination__page<?= $p === $page ? ' pagination__page--active' : '' ?>"
                   href="/students?page=<?= $p ?>&amp;q=<?= urlencode($search) ?>&amp;status=<?= urlencode($status) ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
