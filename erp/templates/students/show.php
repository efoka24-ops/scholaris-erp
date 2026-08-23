<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $student
 * @var array<int, array<string, mixed>> $enrollments
 * @var array<int, array<string, mixed>> $invoices
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = $student['last_name'].' '.$student['first_name'];
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($student['last_name'].' '.$student['first_name']) ?></h1>
        <p class="subtitle">
            Matricule <?= $this->e($student['matricule']) ?>
            &middot; <span class="badge"><?= $this->e($student['status']) ?></span>
        </p>
    </div>
    <?php if ($rbac->allows('students:update')) : ?>
        <a class="button" href="/students/<?= $this->e($student['id']) ?>/edit">Modifier</a>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Etat civil</h2>
    <dl class="details">
        <dt>Date de naissance</dt><dd><?= $this->date($student['date_of_birth']) ?></dd>
        <dt>Lieu de naissance</dt><dd><?= $this->e($student['place_of_birth'] ?: '-') ?></dd>
        <dt>Sexe</dt><dd><?= $this->e($student['gender'] === 'MALE' ? 'Masculin' : 'Feminin') ?></dd>
        <dt>Nationalite</dt><dd><?= $this->e($student['nationality']) ?></dd>
        <dt>Groupe sanguin</dt><dd><?= $this->e($student['blood_group'] ?: '-') ?></dd>
        <dt>Contact d urgence</dt><dd><?= $this->e($student['emergency_contact'] ?: '-') ?></dd>
        <dt>Allergies</dt><dd><?= $this->e($student['allergies'] ?: '-') ?></dd>
        <dt>Handicap</dt><dd><?= $this->e($student['handicap'] ?: '-') ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Inscriptions</h2>
    <?php if ($enrollments === []) : ?>
        <p class="muted">Aucune inscription enregistree.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Annee</th><th>Classe</th><th>Type</th><th>Regime</th><th>Statut</th></tr>
            </thead>
            <tbody>
            <?php foreach ($enrollments as $enrollment) : ?>
                <tr>
                    <td><?= $this->e($enrollment['year_label']) ?></td>
                    <td><?= $this->e($enrollment['classroom_name']) ?></td>
                    <td><?= $this->e($enrollment['type']) ?></td>
                    <td><?= $this->e($enrollment['regime']) ?></td>
                    <td><span class="badge"><?= $this->e($enrollment['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('invoices:read')) : ?>
    <div class="card">
        <h2>Facturation</h2>
        <?php if ($invoices === []) : ?>
            <p class="muted">Aucune facture emise.</p>
        <?php else : ?>
            <table class="table">
                <thead>
                <tr><th>Emise le</th><th>Total</th><th>Regle</th><th>Solde</th><th>Statut</th></tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice) : ?>
                    <tr>
                        <td><?= $this->date($invoice['created_at']) ?></td>
                        <td><?= $this->money($invoice['total_amount']) ?></td>
                        <td><?= $this->money($invoice['paid_amount']) ?></td>
                        <td><?= $this->money($invoice['balance']) ?></td>
                        <td><span class="badge"><?= $this->e($invoice['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($rbac->allows('students:update')) : ?>
    <form method="post" action="/students/<?= $this->e($student['id']) ?>/delete" class="card"
          onsubmit="return confirm('Archiver ce dossier ? Il restera consultable dans l historique.');">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Archiver</h2>
        <p class="muted">
            Le dossier est retire des listes actives mais conserve : les notes, factures
            et bulletins deja emis restent consultables.
        </p>
        <button type="submit" class="button button--danger">Archiver ce dossier</button>
    </form>
<?php endif; ?>
