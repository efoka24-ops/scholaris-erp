<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $classrooms
 * @var array<int, array<string, mixed>> $students
 * @var list<string> $types
 * @var list<string> $regimes
 * @var string $preselectedStudent
 * @var array<string, mixed> $old
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Inscrire un eleve';

$value = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $default);

$typeLabels = ['NEW' => 'Nouvelle inscription', 'RE_ENROLLMENT' => 'Reinscription', 'TRANSFER' => 'Transfert'];
$regimeLabels = ['EXTERNAL' => 'Externe', 'HALF_BOARD' => 'Demi-pensionnaire', 'BOARDING' => 'Interne'];
?>
<h1>Inscrire un eleve</h1>
<p class="subtitle">La facture de scolarite est generee automatiquement depuis la grille tarifaire du niveau.</p>

<?php if ($students === []) : ?>
    <div class="card">
        <p class="muted">
            Tous les eleves actifs sont deja inscrits pour cette annee.
            <a href="/students/create">Creer un nouveau dossier eleve</a> d abord.
        </p>
    </div>
<?php else : ?>
    <form method="post" action="/enrollments" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

        <div class="grid-2">
            <div class="field">
                <label for="student_id">Eleve *</label>
                <select id="student_id" name="student_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($students as $student) :
                        $selected = $value('student_id', $preselectedStudent) === $student['id'];
                        ?>
                        <option value="<?= $this->e($student['id']) ?>" <?= $selected ? 'selected' : '' ?>>
                            <?= $this->e($student['matricule'].' - '.$student['last_name'].' '.$student['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="classroom_id">Classe *</label>
                <select id="classroom_id" name="classroom_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($classrooms as $classroom) : ?>
                        <option value="<?= $this->e($classroom['id']) ?>"
                            <?= $value('classroom_id') === $classroom['id'] ? 'selected' : '' ?>>
                            <?= $this->e($classroom['name'].' ('.$classroom['level_name'].')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="type">Type d inscription *</label>
                <select id="type" name="type" required>
                    <?php foreach ($types as $type) : ?>
                        <option value="<?= $this->e($type) ?>" <?= $value('type', 'NEW') === $type ? 'selected' : '' ?>>
                            <?= $this->e($typeLabels[$type] ?? $type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="regime">Regime *</label>
                <select id="regime" name="regime" required>
                    <?php foreach ($regimes as $regime) : ?>
                        <option value="<?= $this->e($regime) ?>" <?= $value('regime', 'EXTERNAL') === $regime ? 'selected' : '' ?>>
                            <?= $this->e($regimeLabels[$regime] ?? $regime) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="previous_school">Etablissement precedent</label>
            <input id="previous_school" name="previous_school" value="<?= $this->e($value('previous_school')) ?>">
        </div>

        <div class="checkbox">
            <input id="is_repeater" name="is_repeater" type="checkbox" value="1">
            <label for="is_repeater">Eleve redoublant</label>
        </div>

        <div class="form-actions">
            <button type="submit" class="button">Inscrire</button>
            <a class="button button--secondary" href="/enrollments">Annuler</a>
        </div>
    </form>
<?php endif; ?>
