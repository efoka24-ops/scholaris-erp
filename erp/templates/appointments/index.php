<?php
/**
 * Module 39 : rendez-vous.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $appointments, $upcoming, $staff, $students
 * @var list<string> $statuses
 * @var string $status
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Rendez-vous';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');

$label = static function (string $status): string {
    return match ($status) {
        'CONFIRME' => 'Confirmé',
        'HONORE' => 'Honoré',
        'ANNULE' => 'Annulé',
        default => 'Demandé',
    };
};

$badge = static function (string $status): string {
    return match ($status) {
        'CONFIRME' => 'badge badge--success',
        'HONORE' => 'badge badge--neutral',
        'ANNULE' => 'badge badge--danger',
        default => 'badge badge--warning',
    };
};
?>
<h1>Rendez-vous</h1>
<p class="subtitle"><?= $this->number(count($upcoming)) ?> rendez-vous à venir</p>

<?php if ($upcoming !== []) : ?>
    <div class="card">
        <h2>À venir</h2>
        <table class="table">
            <thead>
            <tr><th>Date et heure</th><th>Reçu par</th><th>Demandeur</th><th>Objet</th><th>Lieu</th><th>État</th></tr>
            </thead>
            <tbody>
            <?php foreach ($upcoming as $appointment) : ?>
                <tr>
                    <td class="font-mono"><?= $this->date($appointment['scheduled_at'], 'd/m/Y H:i') ?></td>
                    <td><?= $this->e($appointment['staff_last'].' '.$appointment['staff_first']) ?></td>
                    <td><?= $this->e($appointment['requester_name']) ?></td>
                    <td><?= $this->e($appointment['subject']) ?></td>
                    <td><?= $this->e($appointment['location']) ?></td>
                    <td>
                        <span class="<?= $this->e($badge((string) $appointment['status'])) ?>">
                            <?= $this->e($label((string) $appointment['status'])) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form method="get" action="/rendez-vous" class="inline-form">
    <select name="statut" aria-label="Filtrer par état">
        <option value="">Tous les états</option>
        <?php foreach ($statuses as $option) : ?>
            <option value="<?= $this->e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                <?= $this->e($label($option)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Filtrer</button>
</form>

<div class="card">
    <h2>Tous les rendez-vous</h2>
    <?php if ($appointments === []) : ?>
        <p class="muted">Aucun rendez-vous.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date et heure</th><th>Reçu par</th><th>Demandeur</th><th>Objet</th><th>Durée</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($appointments as $appointment) : ?>
                <tr>
                    <td class="font-mono"><?= $this->date($appointment['scheduled_at'], 'd/m/Y H:i') ?></td>
                    <td><?= $this->e($appointment['staff_last'].' '.$appointment['staff_first']) ?></td>
                    <td><?= $this->e($appointment['requester_name']) ?></td>
                    <td><?= $this->e($appointment['subject']) ?></td>
                    <td class="font-mono"><?= $this->number($appointment['duration_minutes']) ?> min</td>
                    <td>
                        <span class="<?= $this->e($badge((string) $appointment['status'])) ?>">
                            <?= $this->e($label((string) $appointment['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($rbac->allows('appointments:update')) : ?>
                            <form method="post" action="/rendez-vous/<?= $this->e($appointment['id']) ?>/statut" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <select name="status" aria-label="Nouvel état">
                                    <?php foreach ($statuses as $option) : ?>
                                        <option value="<?= $this->e($option) ?>"
                                            <?= (string) $appointment['status'] === $option ? 'selected' : '' ?>>
                                            <?= $this->e($label($option)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="link-button">Modifier</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('appointments:create')) : ?>
    <div class="card">
        <form method="post" action="/rendez-vous">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Prendre un rendez-vous</h2>
            <p class="muted">
                Un créneau déjà occupé pour la même personne sera refusé : deux rendez-vous
                simultanés se traduisent par une famille qui attend dans un couloir.
            </p>
            <div class="grid-2">
                <div class="field">
                    <label for="staff_id">Reçu par *</label>
                    <select id="staff_id" name="staff_id" required>
                        <?php foreach ($staff as $member) : ?>
                            <option value="<?= $this->e($member['id']) ?>">
                                <?= $this->e($member['last_name'].' '.$member['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="requester_name">Demandeur *</label>
                    <input id="requester_name" name="requester_name" required
                           value="<?= $this->e($value('requester_name')) ?>"
                           placeholder="Nom du parent ou du visiteur">
                </div>
                <div class="field">
                    <label for="scheduled_at">Date et heure *</label>
                    <input id="scheduled_at" name="scheduled_at" type="datetime-local" required>
                </div>
                <div class="field">
                    <label for="duration_minutes">Durée (minutes)</label>
                    <input id="duration_minutes" name="duration_minutes" inputmode="numeric" value="30">
                </div>
                <div class="field">
                    <label for="student_id">Élève concerné</label>
                    <select id="student_id" name="student_id">
                        <option value="">Aucun</option>
                        <?php foreach ($students as $student) : ?>
                            <option value="<?= $this->e($student['id']) ?>">
                                <?= $this->e($student['last_name'].' '.$student['first_name'].' — '.$student['matricule']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="location">Lieu</label>
                    <input id="location" name="location" placeholder="Bureau du directeur, salle des professeurs...">
                </div>
            </div>
            <div class="field">
                <label for="subject">Objet *</label>
                <input id="subject" name="subject" required value="<?= $this->e($value('subject')) ?>">
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"></textarea>
            </div>
            <button type="submit" class="button">Enregistrer le rendez-vous</button>
        </form>
    </div>
<?php endif; ?>
