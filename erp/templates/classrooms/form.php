<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $classroom
 * @var array<int, array<string, mixed>> $levels
 * @var array<int, array<string, mixed>> $teachers
 * @var list<string> $sections
 * @var array<string, mixed> $old
 * @var string $csrfToken
 */
$this->extends('layouts.app');

$isEdit = $classroom !== null;
$title = $isEdit ? 'Modifier une classe' : 'Nouvelle classe';
$action = $isEdit ? '/classrooms/'.$classroom['id'] : '/classrooms';
$value = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $classroom[$key] ?? $default);
?>
<h1><?= $this->e($title) ?></h1>

<form method="post" action="<?= $this->e($action) ?>" class="card">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <div class="grid-2">
        <div class="field">
            <label for="code">Code *</label>
            <input id="code" name="code" required placeholder="6EM-A" value="<?= $this->e($value('code')) ?>">
        </div>
        <div class="field">
            <label for="name">Nom *</label>
            <input id="name" name="name" required placeholder="6eme A" value="<?= $this->e($value('name')) ?>">
        </div>
        <div class="field">
            <label for="level_id">Niveau *</label>
            <select id="level_id" name="level_id" required>
                <option value="">Choisir...</option>
                <?php foreach ($levels as $level) : ?>
                    <option value="<?= $this->e($level['id']) ?>" <?= $value('level_id') === $level['id'] ? 'selected' : '' ?>>
                        <?= $this->e($level['cycle_name'].' - '.$level['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="section">Section *</label>
            <select id="section" name="section" required>
                <?php foreach ($sections as $section) : ?>
                    <option value="<?= $this->e($section) ?>" <?= $value('section') === $section ? 'selected' : '' ?>>
                        <?= $this->e($section) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="capacity">Capacite *</label>
            <input id="capacity" name="capacity" type="number" min="1" max="500" required
                   value="<?= $this->e($value('capacity', '50')) ?>">
        </div>
        <div class="field">
            <label for="main_teacher_id">Professeur principal</label>
            <select id="main_teacher_id" name="main_teacher_id">
                <option value="">Aucun</option>
                <?php foreach ($teachers as $teacher) : ?>
                    <option value="<?= $this->e($teacher['id']) ?>"
                        <?= $value('main_teacher_id') === $teacher['id'] ? 'selected' : '' ?>>
                        <?= $this->e($teacher['last_name'].' '.$teacher['first_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="button"><?= $isEdit ? 'Enregistrer' : 'Creer la classe' ?></button>
        <a class="button button--secondary" href="/classrooms">Annuler</a>
    </div>
</form>
