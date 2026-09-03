<?php
/**
 * Module 11 : incidents disciplinaires et sanctions.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $incidents, $students
 * @var list<string> $types, $sanctions
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Discipline';

$typeLabels = [
    'RETARD' => 'Retard',
    'ABSENCE_INJUSTIFIEE' => 'Absence injustifiee',
    'INSOLENCE' => 'Insolence',
    'BAGARRE' => 'Bagarre',
    'TRICHERIE' => 'Tricherie',
    'AUTRE' => 'Autre',
];

$sanctionLabels = [
    'AVERTISSEMENT' => 'Avertissement',
    'BLAME' => 'Blame',
    'EXCLUSION_COURS' => 'Exclusion de cours',
    'EXCLUSION_TEMPORAIRE' => 'Exclusion temporaire',
    'CONVOCATION_PARENTS' => 'Convocation des parents',
    'AUTRE' => 'Autre',
];

$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Discipline</h1>
<p class="subtitle"><?= $this->number(count($incidents)) ?> incident(s) enregistré(s)</p>

<?php if ($rbac->allows('discipline:create')) : ?>
    <form method="post" action="/discipline" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Signaler un incident</h2>

        <div class="grid-2">
            <div class="field">
                <label for="student_id">Élève *</label>
                <select id="student_id" name="student_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($students as $student) : ?>
                        <option value="<?= $this->e($student['id']) ?>" <?= $value('student_id') === $student['id'] ? 'selected' : '' ?>>
                            <?= $this->e($student['matricule'].' — '.$student['last_name'].' '.$student['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="type">Type *</label>
                <select id="type" name="type" required>
                    <?php foreach ($types as $type) : ?>
                        <option value="<?= $this->e($type) ?>" <?= $value('type') === $type ? 'selected' : '' ?>>
                            <?= $this->e($typeLabels[$type] ?? $type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="date">Date *</label>
                <input id="date" name="date" type="date" required value="<?= $this->e($value('date') ?: date('Y-m-d')) ?>">
            </div>
            <div class="field">
                <label for="sanction">Sanction</label>
                <select id="sanction" name="sanction">
                    <option value="">Aucune pour l'instant</option>
                    <?php foreach ($sanctions as $sanction) : ?>
                        <option value="<?= $this->e($sanction) ?>" <?= $value('sanction') === $sanction ? 'selected' : '' ?>>
                            <?= $this->e($sanctionLabels[$sanction] ?? $sanction) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="description">Description *</label>
            <textarea id="description" name="description" rows="3" required><?= $this->e($value('description')) ?></textarea>
        </div>

        <div class="field">
            <label for="sanction_details">Détails de la sanction</label>
            <input id="sanction_details" name="sanction_details" value="<?= $this->e($value('sanction_details')) ?>">
        </div>

        <button type="submit" class="button">Enregistrer l'incident</button>
    </form>
<?php endif; ?>

<?php if ($incidents === []) : ?>
    <div class="card"><p class="muted">Aucun incident enregistré.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Date</th><th>Élève</th><th>Type</th><th>Description</th><th>Sanction</th><th>Signale par</th></tr>
        </thead>
        <tbody>
        <?php foreach ($incidents as $incident) : ?>
            <tr>
                <td class="font-mono"><?= $this->date($incident['date']) ?></td>
                <td><a href="/students/<?= $this->e($incident['student_id']) ?>"><?= $this->e($incident['last_name'].' '.$incident['first_name']) ?></a></td>
                <td><span class="badge"><?= $this->e($typeLabels[$incident['type']] ?? $incident['type']) ?></span></td>
                <td><?= $this->e($incident['description']) ?></td>
                <td>
                    <?php if ($incident['sanction']) : ?>
                        <?= $this->e($sanctionLabels[$incident['sanction']] ?? $incident['sanction']) ?>
                    <?php else : ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= $this->e($incident['reporter_last'].' '.$incident['reporter_first']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
