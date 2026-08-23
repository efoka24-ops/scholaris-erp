<?php
/**
 * Formulaire de creation et de modification.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $student
 * @var array<string, mixed> $old
 * @var list<string> $genders, $statuses
 * @var string $csrfToken
 */
$this->extends('layouts.app');

$isEdit = $student !== null;
$title = $isEdit ? 'Modifier un eleve' : 'Nouvel eleve';
$action = $isEdit ? '/students/'.$student['id'] : '/students';

// Priorite aux valeurs re-saisies apres un refus de validation, puis au dossier
// existant : l'utilisateur ne doit pas perdre ce qu'il vient de taper.
$value = static function (string $field, string $default = '') use ($old, $student) {
    return (string) ($old[$field] ?? $student[$field] ?? $default);
};
?>
<h1><?= $this->e($title) ?></h1>

<form method="post" action="<?= $this->e($action) ?>" class="card">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <div class="grid-2">
        <div class="field">
            <label for="last_name">Nom *</label>
            <input id="last_name" name="last_name" required value="<?= $this->e($value('last_name')) ?>">
        </div>
        <div class="field">
            <label for="first_name">Prenom *</label>
            <input id="first_name" name="first_name" required value="<?= $this->e($value('first_name')) ?>">
        </div>
        <div class="field">
            <label for="date_of_birth">Date de naissance *</label>
            <input id="date_of_birth" name="date_of_birth" type="date" required
                   value="<?= $this->e($value('date_of_birth')) ?>">
        </div>
        <div class="field">
            <label for="place_of_birth">Lieu de naissance</label>
            <input id="place_of_birth" name="place_of_birth" value="<?= $this->e($value('place_of_birth')) ?>">
        </div>
        <div class="field">
            <label for="gender">Sexe *</label>
            <select id="gender" name="gender" required>
                <?php foreach ($genders as $gender) : ?>
                    <option value="<?= $this->e($gender) ?>" <?= $value('gender') === $gender ? 'selected' : '' ?>>
                        <?= $this->e($gender === 'MALE' ? 'Masculin' : 'Feminin') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="nationality">Nationalite</label>
            <input id="nationality" name="nationality" value="<?= $this->e($value('nationality', 'Camerounaise')) ?>">
        </div>
        <div class="field">
            <label for="blood_group">Groupe sanguin</label>
            <input id="blood_group" name="blood_group" value="<?= $this->e($value('blood_group')) ?>">
        </div>
        <div class="field">
            <label for="emergency_contact">Contact d urgence</label>
            <input id="emergency_contact" name="emergency_contact" value="<?= $this->e($value('emergency_contact')) ?>">
        </div>
    </div>

    <div class="field">
        <label for="allergies">Allergies</label>
        <textarea id="allergies" name="allergies" rows="2"><?= $this->e($value('allergies')) ?></textarea>
    </div>

    <div class="field">
        <label for="handicap">Handicap</label>
        <textarea id="handicap" name="handicap" rows="2"><?= $this->e($value('handicap')) ?></textarea>
    </div>

    <?php if ($isEdit) : ?>
        <div class="field">
            <label for="status">Statut</label>
            <select id="status" name="status">
                <?php foreach ($statuses as $option) : ?>
                    <option value="<?= $this->e($option) ?>" <?= $value('status') === $option ? 'selected' : '' ?>>
                        <?= $this->e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="button"><?= $isEdit ? 'Enregistrer' : 'Creer le dossier' ?></button>
        <a class="button button--secondary" href="<?= $isEdit ? '/students/'.$this->e($student['id']) : '/students' ?>">Annuler</a>
    </div>
</form>

<?php if (! $isEdit) : ?>
    <p class="muted">Le matricule est attribue automatiquement a la creation.</p>
<?php endif; ?>
