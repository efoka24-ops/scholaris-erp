<?php
/**
 * Espace de l'administrateur de la plateforme.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $tenants
 * @var int $pendingRequests, $totalStudents, $totalUsers
 * @var array<int, array<string, mixed>> $recentLogins
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Administration de la plateforme';
?>
<div class="page-header">
    <div>
        <h1>Administration de la plateforme</h1>
        <p class="subtitle">
            Parc d etablissements. Vous n appartenez a aucune ecole : pour
            consulter les donnees de l une d elles, placez-vous dedans.
        </p>
    </div>
    <a class="button button--secondary" href="/admin/etablissements">
        Demandes d ouverture
        <?php if ($pendingRequests > 0) : ?>
            (<?= $this->number($pendingRequests) ?>)
        <?php endif; ?>
    </a>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Etablissements</div>
        <div class="stat__value"><?= $this->number(count($tenants)) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Eleves, tous etablissements</div>
        <div class="stat__value"><?= $this->number($totalStudents) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Comptes</div>
        <div class="stat__value"><?= $this->number($totalUsers) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Demandes en attente</div>
        <div class="stat__value"><?= $this->number($pendingRequests) ?></div>
    </div>
</div>

<div class="card">
    <h2>Etablissements</h2>

    <?php if ($tenants === []) : ?>
        <p class="muted">Aucun etablissement sur la plateforme.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr>
                <th>Code</th><th>Nom</th><th>Type</th><th>Statut</th>
                <th>Comptes</th><th>Eleves</th><th>Pre-inscription</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $tenant) : ?>
                <tr>
                    <td><span class="badge"><?= $this->e($tenant['code']) ?></span></td>
                    <td><?= $this->e($tenant['name']) ?></td>
                    <td><?= $this->e($tenant['type']) ?></td>
                    <td><?= $this->e($tenant['status']) ?></td>
                    <td><?= $this->number($tenant['users_count']) ?></td>
                    <td><?= $this->number($tenant['students_count']) ?></td>
                    <td>
                        <?php if ((int) $tenant['public_enrollment_enabled'] === 1) : ?>
                            <span class="badge">ouverte</span>
                        <?php else : ?>
                            <span class="muted">fermee</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="/admin/etablissements/<?= $this->e($tenant['id']) ?>/consulter"
                              class="inline-form">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <button type="submit" class="link-button">Consulter</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="muted">
            Entrer dans un etablissement est journalise. C est ce qui distingue
            un acces legitime d un acces silencieux aux donnees d une ecole.
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Dernieres connexions</h2>

    <?php if ($recentLogins === []) : ?>
        <p class="muted">Aucune connexion enregistree.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date</th><th>Compte</th><th>Etablissement</th><th>Adresse IP</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentLogins as $login) : ?>
                <tr>
                    <td><?= $this->date($login['timestamp'], 'd/m/Y H:i') ?></td>
                    <td><?= $this->e($login['email'] ?: '-') ?></td>
                    <td>
                        <?php if ($login['tenant_name']) : ?>
                            <?= $this->e($login['tenant_name']) ?>
                        <?php else : ?>
                            <span class="badge">plateforme</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->e($login['ip_address'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
