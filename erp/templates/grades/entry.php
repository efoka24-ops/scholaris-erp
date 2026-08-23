<?php
/**
 * Grille de saisie : une ligne par eleve, comme un cahier de notes.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $classroom
 * @var array<string, mixed> $subject
 * @var array<string, mixed> $period
 * @var array<int, array<string, mixed>> $students
 * @var array<string, array<string, mixed>> $existing
 * @var string $type
 * @var list<string> $types
 * @var bool $periodOpen
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = $subject['name'].' - '.$classroom['name'];

$typeLabels = [
    'TEST' => 'Devoir surveille',
    'HOMEWORK' => 'Devoir maison',
    'EXAM' => 'Examen',
    'RESIT' => 'Rattrapage',
];

$url = '/grades/entry/'.$classroom['id'].'/'.$subject['id'].'/'.$period['id'];
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($subject['name']) ?></h1>
        <p class="subtitle">
            <?= $this->e($classroom['name']) ?>
            &middot; Sequence <?= $this->e($period['number']) ?>
            &middot; coefficient <?= $this->e(rtrim(rtrim((string) $subject['coefficient'], '0'), '.')) ?>
        </p>
    </div>
    <a class="button button--secondary" href="/grades">Retour</a>
</div>

<?php if (! $periodOpen) : ?>
    <div class="alert alert--error">
        Cette sequence est fermee : les notes sont consultables mais ne peuvent
        plus etre modifiees.
    </div>
<?php endif; ?>

<form method="get" action="<?= $this->e($url) ?>" class="filters">
    <select name="type" onchange="this.form.submit()">
        <?php foreach ($types as $option) : ?>
            <option value="<?= $this->e($option) ?>" <?= $type === $option ? 'selected' : '' ?>>
                <?= $this->e($typeLabels[$option] ?? $option) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="button button--secondary">Changer</button></noscript>
</form>

<?php if ($students === []) : ?>
    <div class="card"><p class="muted">Aucun eleve inscrit dans cette classe.</p></div>
<?php else : ?>
    <form method="post" action="<?= $this->e($url) ?>" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <input type="hidden" name="type" value="<?= $this->e($type) ?>">

        <table class="table">
            <thead>
            <tr>
                <th>Matricule</th><th>Nom</th>
                <th style="width:120px">Note / 20</th>
                <th style="width:90px">Absent</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student) :
                $grade = $existing[(string) $student['id']] ?? null;
                $isAbsent = $grade !== null && (int) $grade['is_absent'] === 1;
                $isLocked = $grade !== null && (int) $grade['is_locked'] === 1;
                $current = $grade !== null && $grade['value'] !== null
                    ? rtrim(rtrim((string) $grade['value'], '0'), '.')
                    : '';
                ?>
                <tr>
                    <td><?= $this->e($student['matricule']) ?></td>
                    <td><?= $this->e($student['last_name'].' '.$student['first_name']) ?></td>
                    <td>
                        <input type="text" inputmode="decimal"
                               name="value[<?= $this->e($student['id']) ?>]"
                               value="<?= $this->e($current) ?>"
                               <?= $periodOpen && ! $isLocked ? '' : 'disabled' ?>
                               placeholder="-">
                    </td>
                    <td>
                        <input type="checkbox"
                               name="absent[<?= $this->e($student['id']) ?>]" value="1"
                               <?= $isAbsent ? 'checked' : '' ?>
                               <?= $periodOpen && ! $isLocked ? '' : 'disabled' ?>>
                        <?php if ($isLocked) : ?>
                            <span class="badge">verrouillee</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($periodOpen) : ?>
            <div class="form-actions">
                <button type="submit" class="button">Enregistrer les notes</button>
            </div>
            <p class="muted">
                Les cases laissees vides sont ignorees. Une note hors bareme (au-dela
                de 20) est rejetee plutot que ramenee a 20, pour ne pas transformer
                une faute de frappe en resultat.
            </p>
        <?php endif; ?>
    </form>
<?php endif; ?>
