<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $classrooms
 * @var array<string, int> $counts
 * @var \Scholaris\Auth\Rbac $rbac
 */
$this->extends('layouts.app');
$title = 'Classes';
?>
<div class="page-header">
    <div>
        <h1>Classes</h1>
        <p class="subtitle"><?= $this->number(count($classrooms)) ?> classe(s)</p>
    </div>
    <?php if ($rbac->allows('classrooms:create')) : ?>
        <a class="button" href="/classrooms/create">Nouvelle classe</a>
    <?php endif; ?>
</div>

<?php if ($classrooms === []) : ?>
    <div class="card"><p class="muted">Aucune classe. Creez-en une pour commencer les inscriptions.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr>
            <th>Classe</th><th>Niveau</th><th>Section</th>
            <th>Professeur principal</th><th>Effectif</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($classrooms as $classroom) :
            $count = $counts[(string) $classroom['id']] ?? 0;
            $capacity = (int) $classroom['capacity'];
            ?>
            <tr>
                <td><a href="/classrooms/<?= $this->e($classroom['id']) ?>"><?= $this->e($classroom['name']) ?></a></td>
                <td><?= $this->e($classroom['level_name']) ?></td>
                <td><span class="badge"><?= $this->e($classroom['section']) ?></span></td>
                <td>
                    <?php if ($classroom['teacher_last_name']) : ?>
                        <?= $this->e($classroom['teacher_last_name'].' '.$classroom['teacher_first_name']) ?>
                    <?php else : ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $this->number($count) ?> / <?= $this->number($capacity) ?>
                    <?php if ($capacity > 0 && $count >= $capacity) : ?>
                        <span class="badge">complet</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
