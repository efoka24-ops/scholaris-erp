<?php
/**
 * Journal d audit.
 *
 * Un administrateur national entre dans les donnees d ecoles qui ne sont pas
 * les siennes. Cette trace est ce qui distingue un acces legitime d un acces
 * silencieux — et elle ne vaut que si elle est consultable.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $entries
 * @var list<array<string, mixed>> $actions
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $action
 */
$this->extends('layouts.app');
$title = 'Journal d audit';

$pages = (int) ceil($total / $perPage);

$labels = [
    'login' => 'Connexion',
    'tenant.create' => 'Etablissement cree',
    'tenant.update' => 'Etablissement modifie',
    'tenant.suspend' => 'Etablissement suspendu',
    'tenant.restore' => 'Suspension levee',
    'tenant.delete' => 'Etablissement retire',
    'tenant.enter' => 'Entree dans un etablissement',
    'establishment.approve' => 'Demande approuvee',
    'establishment.reject' => 'Demande refusee',
    'user.reset_password' => 'Mot de passe reinitialise',
    'user.deactivate' => 'Compte desactive',
    'user.activate' => 'Compte reactive',
    'user.unlock' => 'Compte deverrouille',
    'user.create_platform_admin' => 'Administrateur nomme',
    'schema.migrate' => 'Migration appliquee',
    'http.not_found' => 'Page introuvable',
];
?>
<div class="page-header">
    <div>
        <h1>Journal d audit</h1>
        <p class="subtitle">
            <?= $this->number($total) ?> evenement<?= $total > 1 ? 's' : '' ?> enregistre<?= $total > 1 ? 's' : '' ?>
        </p>
    </div>
</div>

<div class="card">
    <form method="get" action="/admin/journal" class="inline-form" style="gap:0.75rem;align-items:flex-end">
        <div class="field" style="margin-bottom:0;flex:1 1 20rem">
            <label for="action">Type d evenement</label>
            <select id="action" name="action">
                <option value="">Tous</option>
                <?php foreach ($actions as $row) : ?>
                    <option value="<?= $this->e($row['action']) ?>" <?= $action === (string) $row['action'] ? 'selected' : '' ?>>
                        <?= $this->e($labels[$row['action']] ?? $row['action']) ?>
                        (<?= $this->number($row['total']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button button--secondary">Filtrer</button>
        <?php if ($action !== '') : ?>
            <a class="link-button" href="/admin/journal">Effacer</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if ($entries === []) : ?>
        <p class="muted">Aucun evenement ne correspond.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr>
                <th>Date</th><th>Evenement</th><th>Auteur</th>
                <th>Perimetre</th><th>Cible</th><th>Adresse IP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry) : ?>
                <tr>
                    <td><?= $this->date($entry['timestamp'], 'd/m/Y H:i:s') ?></td>
                    <td><?= $this->e($labels[$entry['action']] ?? $entry['action']) ?></td>
                    <td>
                        <?php if (($entry['email'] ?? null) === null) : ?>
                            <span class="muted">systeme</span>
                        <?php else : ?>
                            <?= $this->e($entry['email']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($entry['tenant_name'] ?? null) === null) : ?>
                            <span class="badge">plateforme</span>
                        <?php else : ?>
                            <?= $this->e($entry['tenant_name']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="muted">
                        <?php if (($entry['resource_id'] ?? null) !== null && $entry['resource_id'] !== '') : ?>
                            <?php // L'identifiant est ce qui permet de retrouver l'acte : pour une
                                  // page introuvable, c'est l'adresse demandee. ?>
                            <code><?= $this->e($entry['resource_id']) ?></code>
                        <?php else : ?>
                            <?= $this->e($entry['resource']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= $this->e($entry['ip_address'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1) : ?>
            <p class="muted">
                Page <?= $this->number($page) ?> sur <?= $this->number($pages) ?>
                <?php if ($page > 1) : ?>
                    &middot;
                    <a href="/admin/journal?action=<?= urlencode($action) ?>&amp;page=<?= $page - 1 ?>">precedente</a>
                <?php endif; ?>
                <?php if ($page < $pages) : ?>
                    &middot;
                    <a href="/admin/journal?action=<?= urlencode($action) ?>&amp;page=<?= $page + 1 ?>">suivante</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
