<?php
/**
 * Module 8bis : cahier de textes numerique.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $entries, $classrooms, $subjects
 * @var string $classroomId, $date
 * @var bool $canCreate
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Cahier de textes';

$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Cahier de textes</h1>
<p class="subtitle"><?= $this->number(count($entries)) ?> seance(s)</p>

<form method="get" action="/course-log" class="card">
    <div class="grid-2">
        <div class="field">
            <label for="classroom">Classe</label>
            <select id="classroom" name="classroom" onchange="this.form.submit()">
                <option value="">Toutes les classes</option>
                <?php foreach ($classrooms as $classroom) : ?>
                    <option value="<?= $this->e($classroom['id']) ?>" <?= $classroomId === $classroom['id'] ? 'selected' : '' ?>>
                        <?= $this->e($classroom['level_name'].' — '.$classroom['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="date">Date</label>
            <input id="date" name="date" type="date" value="<?= $this->e($date) ?>" onchange="this.form.submit()">
        </div>
    </div>
</form>

<?php if ($canCreate) : ?>
    <form method="post" action="/course-log" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Nouvelle seance</h2>

        <div class="grid-2">
            <div class="field">
                <label for="classroom_id">Classe *</label>
                <select id="classroom_id" name="classroom_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($classrooms as $classroom) : ?>
                        <option value="<?= $this->e($classroom['id']) ?>" <?= $value('classroom_id') === $classroom['id'] ? 'selected' : '' ?>>
                            <?= $this->e($classroom['level_name'].' — '.$classroom['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="subject_id">Matière *</label>
                <select id="subject_id" name="subject_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($subjects as $subject) : ?>
                        <option value="<?= $this->e($subject['id']) ?>" <?= $value('subject_id') === $subject['id'] ? 'selected' : '' ?>>
                            <?= $this->e($subject['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="entry_date">Date *</label>
                <input id="entry_date" name="date" type="date" required value="<?= $this->e($value('date') ?: date('Y-m-d')) ?>">
            </div>
        </div>

        <div class="field">
            <label for="content">Contenu de la seance *</label>
            <textarea id="content" name="content" rows="3" required><?= $this->e($value('content')) ?></textarea>
        </div>

        <div class="field">
            <label for="homework">Devoirs donnes</label>
            <textarea id="homework" name="homework" rows="2"><?= $this->e($value('homework')) ?></textarea>
        </div>

        <button type="submit" class="button">Enregistrer la seance</button>
    </form>
<?php endif; ?>

<?php if ($entries === []) : ?>
    <div class="card"><p class="muted">Aucune seance enregistrée.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Date</th><th>Classe</th><th>Matière</th><th>Contenu</th><th>Devoirs</th><th>Enseignant</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry) : ?>
            <tr>
                <td class="font-mono"><?= $this->date($entry['date']) ?></td>
                <td><?= $this->e($entry['classroom_name']) ?></td>
                <td><span class="badge"><?= $this->e($entry['subject_name']) ?></span></td>
                <td><?= $this->e($entry['content']) ?></td>
                <td><?= $entry['homework'] ? $this->e($entry['homework']) : '<span class="muted">-</span>' ?></td>
                <td class="muted"><?= $this->e($entry['teacher_last'].' '.$entry['teacher_first']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
