<?php
/**
 * Module 18 : ressources humaines.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $employees
 * @var array<int, array<string, mixed>> $leaves
 * @var array<int, array<string, mixed>> $users
 * @var int $pending
 * @var float $payroll
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Ressources humaines';

$statusLabels = ['PENDING' => 'En attente', 'APPROVED' => 'Approuve', 'REJECTED' => 'Refuse'];
$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Ressources humaines</h1>
<p class="subtitle"><?= $this->number(count($employees)) ?> membre(s) du personnel</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Effectif</div>
        <div class="stat__value"><?= $this->number(count($employees)) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Masse salariale</div>
        <div class="stat__value stat__value--money"><?= $this->money($payroll) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Conges en attente</div>
        <div class="stat__value"><?= $this->number($pending) ?></div>
    </div>
</div>

<div class="card">
    <h2>Personnel</h2>

    <?php if ($employees === []) : ?>
        <p class="muted">Aucune fiche personnel.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Nom</th><th>Fonction</th><th>Departement</th><th>Embauche</th><th>Salaire</th><th>Compte</th></tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $employee) : ?>
                <tr>
                    <td><?= $this->e($employee['last_name'].' '.$employee['first_name']) ?></td>
                    <td><?= $this->e($employee['position']) ?></td>
                    <td class="muted"><?= $this->e($employee['department'] ?: '-') ?></td>
                    <td class="font-mono"><?= $this->date($employee['hire_date']) ?></td>
                    <td><?= $employee['salary'] !== null ? $this->money($employee['salary']) : '-' ?></td>
                    <td class="muted"><?= $this->e($employee['email'] ?: 'sans compte') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Demandes de conge</h2>

    <?php if ($leaves === []) : ?>
        <p class="muted">Aucune demande.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Employe</th><th>Du</th><th>Au</th><th>Motif</th><th>Statut</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($leaves as $leave) : ?>
                <tr>
                    <td><?= $this->e($leave['last_name'].' '.$leave['first_name']) ?></td>
                    <td class="font-mono"><?= $this->date($leave['start_date']) ?></td>
                    <td class="font-mono"><?= $this->date($leave['end_date']) ?></td>
                    <td><?= $this->e($leave['reason']) ?></td>
                    <td><span class="badge"><?= $this->e($statusLabels[$leave['status']] ?? $leave['status']) ?></span></td>
                    <td>
                        <?php if ($leave['status'] === 'PENDING' && $rbac->allows('hr:update')) : ?>
                            <form method="post" action="/hr/leaves/<?= $this->e($leave['id']) ?>" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <input type="hidden" name="decision" value="APPROVED">
                                <button type="submit" class="link-button">Approuver</button>
                            </form>
                            &middot;
                            <form method="post" action="/hr/leaves/<?= $this->e($leave['id']) ?>" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <input type="hidden" name="decision" value="REJECTED">
                                <button type="submit" class="link-button">Refuser</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('hr:create')) : ?>
    <div class="grid-2" style="gap:1.25rem">
        <form method="post" action="/hr/employees" class="card">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Nouvelle fiche personnel</h2>

            <div class="field">
                <label for="last_name">Nom *</label>
                <input id="last_name" name="last_name" required value="<?= $this->e($value('last_name')) ?>">
            </div>
            <div class="field">
                <label for="first_name">Prenom *</label>
                <input id="first_name" name="first_name" required value="<?= $this->e($value('first_name')) ?>">
            </div>
            <div class="field">
                <label for="position">Fonction *</label>
                <input id="position" name="position" required placeholder="Enseignant, Surveillant...">
            </div>
            <div class="field">
                <label for="department">Departement</label>
                <input id="department" name="department">
            </div>
            <div class="field">
                <label for="hire_date">Date d embauche *</label>
                <input id="hire_date" name="hire_date" type="date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="field">
                <label for="salary">Salaire mensuel (FCFA)</label>
                <input id="salary" name="salary" inputmode="numeric">
            </div>
            <div class="field">
                <label for="user_id">Compte de connexion</label>
                <select id="user_id" name="user_id">
                    <option value="">Aucun</option>
                    <?php foreach ($users as $user) : ?>
                        <option value="<?= $this->e($user['id']) ?>">
                            <?= $this->e($user['last_name'].' '.$user['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="button">Creer la fiche</button>
        </form>

        <form method="post" action="/hr/leaves" class="card">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Demande de conge</h2>

            <div class="field">
                <label for="employee_id">Employe *</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($employees as $employee) : ?>
                        <option value="<?= $this->e($employee['id']) ?>">
                            <?= $this->e($employee['last_name'].' '.$employee['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="start_date">Du *</label>
                <input id="start_date" name="start_date" type="date" required>
            </div>
            <div class="field">
                <label for="end_date">Au *</label>
                <input id="end_date" name="end_date" type="date" required>
            </div>
            <div class="field">
                <label for="reason">Motif *</label>
                <textarea id="reason" name="reason" rows="2" required></textarea>
            </div>

            <button type="submit" class="button">Enregistrer la demande</button>
        </form>
    </div>
<?php endif; ?>
