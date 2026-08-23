<?php
/**
 * Examen officiel : candidats inscrits et suivi des dossiers.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $exam
 * @var array<int, array<string, mixed>> $registrations
 * @var array<int, array<string, mixed>> $candidates
 * @var list<string> $statuses
 * @var bool $isOpen
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = (string) $exam['name'];

$statusLabels = [
    'PENDING' => 'En attente',
    'VALIDATED' => 'Valide',
    'REJECTED' => 'Rejete',
    'ABSENT' => 'Absent',
    'PASSED' => 'Admis',
    'FAILED' => 'Ajourne',
];
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($exam['name']) ?></h1>
        <p class="subtitle">
            <span class="badge"><?= $this->e($exam['code']) ?></span>
            &middot; <?= $this->number(count($registrations)) ?> candidat(s)
            &middot; frais <?= $this->money($exam['fee_amount']) ?>
        </p>
    </div>
    <a class="button--secondary" href="/exams">Retour</a>
</div>

<?php if (! $isOpen) : ?>
    <div class="alert alert--error">
        Les inscriptions sont fermees
        (du <?= $this->date($exam['registration_start']) ?>
        au <?= $this->date($exam['registration_end']) ?>).
        Un dossier depose hors periode serait refuse par le centre d examen.
    </div>
<?php endif; ?>

<?php if ($registrations === []) : ?>
    <div class="card"><p class="muted">Aucun candidat inscrit.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Numero</th><th>Candidat</th><th>Serie</th><th>Centre</th><th>Frais</th><th>Statut</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($registrations as $registration) : ?>
            <tr>
                <td class="font-mono"><?= $this->e($registration['registration_number']) ?></td>
                <td>
                    <?= $this->e($registration['last_name'].' '.$registration['first_name']) ?>
                    <div class="muted font-mono" style="font-size:.72rem"><?= $this->e($registration['matricule']) ?></div>
                </td>
                <td><?= $this->e($registration['series'] ?: '-') ?></td>
                <td class="muted"><?= $this->e($registration['center_name'] ?: '-') ?></td>
                <td><?= (int) $registration['fee_paid'] === 1 ? 'regles' : '<span class="muted">non regles</span>' ?></td>
                <td><span class="badge"><?= $this->e($statusLabels[$registration['status']] ?? $registration['status']) ?></span></td>
                <td>
                    <?php if ($rbac->allows('exams:register')) : ?>
                        <form method="post" action="/exams/registrations/<?= $this->e($registration['id']) ?>"
                              style="display:flex;gap:.4rem">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <select name="status">
                                <?php foreach ($statuses as $status) : ?>
                                    <option value="<?= $this->e($status) ?>" <?= $registration['status'] === $status ? 'selected' : '' ?>>
                                        <?= $this->e($statusLabels[$status] ?? $status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="link-button">Mettre a jour</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($isOpen && $candidates !== [] && $rbac->allows('exams:register')) : ?>
    <form method="post" action="/exams/<?= $this->e($exam['id']) ?>/register" class="card" style="margin-top:1.5rem">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Inscrire un candidat</h2>

        <div class="grid-2">
            <div class="field">
                <label for="student_id">Eleve *</label>
                <select id="student_id" name="student_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($candidates as $candidate) : ?>
                        <option value="<?= $this->e($candidate['id']) ?>">
                            <?= $this->e($candidate['matricule'].' — '.$candidate['last_name'].' '.$candidate['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="series">Serie</label>
                <input id="series" name="series" placeholder="C, D, A4...">
            </div>
            <div class="field">
                <label for="center_name">Centre d examen</label>
                <input id="center_name" name="center_name">
            </div>
        </div>

        <div class="checkbox">
            <input id="fee_paid" name="fee_paid" type="checkbox" value="1">
            <label for="fee_paid">Frais d inscription regles</label>
        </div>

        <button type="submit" class="button">Inscrire</button>
    </form>
<?php endif; ?>
