<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $enrollments
 * @var array<int, array<string, mixed>> $classrooms
 * @var string $classroomId
 * @var int $total, $page, $pages
 * @var bool $hasAcademicYear
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Inscriptions';
?>
<div class="page-header">
    <div>
        <h1>Inscriptions</h1>
        <p class="subtitle"><?= $this->number($total) ?> inscription(s) sur l annee en cours</p>
    </div>
    <?php if ($rbac->allows('enrollments:create')) : ?>
        <a class="button" href="/enrollments/create">Inscrire un eleve</a>
    <?php endif; ?>
</div>

<?php if (! $hasAcademicYear) : ?>
    <div class="alert alert--error">
        Aucune annee academique active. Les inscriptions sont impossibles tant
        qu une annee n est pas ouverte.
    </div>
<?php endif; ?>

<form method="get" action="/enrollments" class="filters">
    <select name="classroom">
        <option value="">Toutes les classes</option>
        <?php foreach ($classrooms as $classroom) : ?>
            <option value="<?= $this->e($classroom['id']) ?>" <?= $classroomId === $classroom['id'] ? 'selected' : '' ?>>
                <?= $this->e($classroom['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Filtrer</button>
</form>

<?php if ($enrollments === []) : ?>
    <div class="card"><p class="muted">Aucune inscription pour ce filtre.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr>
            <th>Matricule</th><th>Eleve</th><th>Classe</th><th>Type</th>
            <th>Regime</th><th>Facture</th><th>Statut</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($enrollments as $enrollment) : ?>
            <tr>
                <td><a href="/students/<?= $this->e($enrollment['student_id']) ?>"><?= $this->e($enrollment['matricule']) ?></a></td>
                <td><?= $this->e($enrollment['last_name'].' '.$enrollment['first_name']) ?></td>
                <td><?= $this->e($enrollment['classroom_name']) ?></td>
                <td><?= $this->e($enrollment['type']) ?></td>
                <td><?= $this->e($enrollment['regime']) ?></td>
                <td>
                    <?php if ($enrollment['invoice_status'] === null) : ?>
                        <span class="muted">aucune</span>
                    <?php else : ?>
                        <span class="badge"><?= $this->e($enrollment['invoice_status']) ?></span>
                        <?php if ((float) $enrollment['balance'] > 0) : ?>
                            <?= $this->money($enrollment['balance']) ?> du
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><span class="badge"><?= $this->e($enrollment['status']) ?></span></td>
                <td>
                    <?php if ($enrollment['status'] === 'ACTIVE' && $rbac->allows('enrollments:update')) : ?>
                        <form method="post" action="/enrollments/<?= $this->e($enrollment['id']) ?>/cancel"
                              class="inline-form"
                              onsubmit="return confirm('Annuler cette inscription ?');">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <button type="submit" class="link-button">Annuler</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $pages; $p++) : ?>
                <a class="pagination__page<?= $p === $page ? ' pagination__page--active' : '' ?>"
                   href="/enrollments?page=<?= $p ?>&amp;classroom=<?= urlencode($classroomId) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
