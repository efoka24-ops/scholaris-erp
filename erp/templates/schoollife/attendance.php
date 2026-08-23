<?php
/**
 * Module 10 : appel numerique, une ligne par eleve.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $classrooms, $students
 * @var array<string, array<string, mixed>> $existing
 * @var string $classroomId, $date
 * @var list<string> $statuses
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Presences';

$labels = ['PRESENT' => 'Present', 'ABSENT' => 'Absent', 'LATE' => 'Retard', 'EXCUSED' => 'Excuse'];
$colours = ['PRESENT' => '#00e5a0', 'ABSENT' => '#ff4d4d', 'LATE' => '#ffb800', 'EXCUSED' => '#00c2ff'];

$counts = array_fill_keys($statuses, 0);

foreach ($existing as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']]++;
    }
}
?>
<h1>Presences</h1>
<p class="subtitle">Appel numerique par classe et par jour.</p>

<form method="get" action="/attendance" class="filters">
    <select name="classroom">
        <option value="">Choisir une classe...</option>
        <?php foreach ($classrooms as $classroom) : ?>
            <option value="<?= $this->e($classroom['id']) ?>" <?= $classroomId === $classroom['id'] ? 'selected' : '' ?>>
                <?= $this->e($classroom['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="date" value="<?= $this->e($date) ?>">
    <button type="submit" class="button--secondary">Afficher</button>
</form>

<?php if ($classroomId === '') : ?>
    <div class="card"><p class="muted">Choisissez une classe et une date.</p></div>
<?php elseif ($students === []) : ?>
    <div class="card"><p class="muted">Aucun eleve inscrit dans cette classe.</p></div>
<?php else : ?>
    <div class="stats">
        <?php foreach ($statuses as $status) : ?>
            <div class="stat">
                <div class="stat__label"><?= $this->e($labels[$status]) ?></div>
                <div class="stat__value" style="color:<?= $this->e($colours[$status]) ?>">
                    <?= $this->number($counts[$status]) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post" action="/attendance/<?= $this->e($classroomId) ?>" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <input type="hidden" name="date" value="<?= $this->e($date) ?>">

        <table class="table">
            <thead>
            <tr><th>Matricule</th><th>Eleve</th><th>Statut</th><th>Motif</th></tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student) : ?>
                <?php
                $row = $existing[(string) $student['id']] ?? null;
                $current = $row !== null ? (string) $row['status'] : 'PRESENT';
                ?>
                <tr>
                    <td class="font-mono"><?= $this->e($student['matricule']) ?></td>
                    <td><?= $this->e($student['last_name'].' '.$student['first_name']) ?></td>
                    <td>
                        <select name="status[<?= $this->e($student['id']) ?>]">
                            <?php foreach ($statuses as $status) : ?>
                                <option value="<?= $this->e($status) ?>" <?= $current === $status ? 'selected' : '' ?>>
                                    <?= $this->e($labels[$status]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="reason[<?= $this->e($student['id']) ?>]"
                               placeholder="Motif (facultatif)"
                               value="<?= $this->e($row['reason'] ?? '') ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($rbac->allows('attendance:create')) : ?>
            <div class="form-actions">
                <button type="submit" class="button">Enregistrer l appel</button>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
