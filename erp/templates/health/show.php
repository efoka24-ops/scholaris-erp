<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $student
 * @var array<string, mixed>|null $record
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Dossier medical';
$value = static fn (string $k): string => (string) ($old[$k] ?? $record[$k] ?? '');
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($student['last_name'].' '.$student['first_name']) ?></h1>
        <p class="subtitle">Dossier medical &middot; matricule <?= $this->e($student['matricule']) ?></p>
    </div>
    <a class="button--secondary" href="/health">Retour</a>
</div>

<div class="alert">
    Donnees de sante : leur consultation est reservee a l infirmerie et a la
    direction, et tracee dans le journal d acces.
</div>

<form method="post" action="/health/<?= $this->e($student['id']) ?>" class="card">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <div class="grid-2">
        <div class="field">
            <label for="blood_type">Groupe sanguin</label>
            <input id="blood_type" name="blood_type" value="<?= $this->e($value('blood_type')) ?>">
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
        <label for="chronic_diseases">Maladies chroniques</label>
        <textarea id="chronic_diseases" name="chronic_diseases" rows="2"><?= $this->e($value('chronic_diseases')) ?></textarea>
    </div>

    <div class="field">
        <label for="medications">Traitements en cours</label>
        <textarea id="medications" name="medications" rows="2"><?= $this->e($value('medications')) ?></textarea>
    </div>

    <div class="field">
        <label for="vaccinations">Vaccinations</label>
        <textarea id="vaccinations" name="vaccinations" rows="2"><?= $this->e($value('vaccinations')) ?></textarea>
    </div>

    <div class="field">
        <label for="notes">Observations</label>
        <textarea id="notes" name="notes" rows="3"><?= $this->e($value('notes')) ?></textarea>
    </div>

    <?php if ($rbac->allows('health:create')) : ?>
        <button type="submit" class="button">Enregistrer le dossier</button>
    <?php endif; ?>
</form>
