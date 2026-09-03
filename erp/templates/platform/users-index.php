<?php
/**
 * Comptes, vus depuis la plateforme.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $tenants
 * @var int $total, $page, $perPage
 * @var string $search, $scope
 * @var \Scholaris\Auth\Rbac $rbac
 * @var array<string, mixed> $profiles
 * @var string $csrfToken
 */
use Scholaris\Support\Cameroon;

$this->extends('layouts.app');
$title = 'Comptes';

$pages = (int) ceil($total / $perPage);
$query = static fn (array $extra): string => '/admin/comptes?'.http_build_query($extra);
?>
<div class="page-header">
    <div>
        <h1>Comptes</h1>
        <p class="subtitle">
            <?= $this->number($total) ?> compte<?= $total > 1 ? 's' : '' ?>
            sur l'ensemble de la plateforme
        </p>
    </div>
    <a class="button" href="/admin/comptes/creer">Nommer un administrateur</a>
</div>

<div class="card">
    <div class="inline-form" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h2 style="margin:0">Répartition des comptes</h2>
            <p class="muted" style="margin:0.25rem 0 0">
                Un compte créé n'est pas un compte utilise. L'ecart entre les
                deux colonnes designe ceux qu'il faut relancer.
            </p>
        </div>
        <?php if ($profiles['activationRate'] !== null) : ?>
            <div style="text-align:right">
                <div class="stat__label">Taux d'activation</div>
                <div class="stat__value"><?= $this->e(number_format($profiles['activationRate'], 1, ',', ' ')) ?>%</div>
            </div>
        <?php endif; ?>
    </div>

    <table class="table" style="margin-top:1rem">
        <thead>
        <tr>
            <th>Profil</th><th>Crees</th><th>Actives</th><th>Taux</th>
            <th>Desactives</th><th>Nouveaux (30 j)</th><th>Sans connexion depuis 30 j</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($profiles['profiles'] as $profile) : ?>
            <?php if ($profile['created'] === 0) { continue; } ?>
            <tr>
                <td><strong><?= $this->e($profile['label']) ?></strong></td>
                <td><?= $this->number($profile['created']) ?></td>
                <td><?= $this->number($profile['activated']) ?></td>
                <td>
                    <?php if ($profile['activation_rate'] === null) : ?>
                        <span class="muted">&mdash;</span>
                    <?php else : ?>
                        <?= $this->e(number_format($profile['activation_rate'], 1, ',', ' ')) ?>%
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($profile['suspended'] > 0) : ?>
                        <span class="badge badge--warning"><?= $this->number($profile['suspended']) ?></span>
                    <?php else : ?>
                        <span class="muted">0</span>
                    <?php endif; ?>
                </td>
                <td><?= $this->number($profile['recent']) ?></td>
                <td><?= $this->number($profile['dormant']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($profiles['withoutRole'] > 0) : ?>
        <p class="muted">
            <?= $this->number($profiles['withoutRole']) ?> compte<?= $profiles['withoutRole'] > 1 ? 's' : '' ?>
            sans role : ils ne peuvent rien ouvrir dans l'application.
        </p>
    <?php endif; ?>

    <?php if ($profiles['topTenants'] !== []) : ?>
        <h3 style="margin-top:1.5rem;font-size:0.875rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted)">
            Établissements les plus fournis
        </h3>
        <ul class="map__list">
            <?php foreach ($profiles['topTenants'] as $row) : ?>
                <li>
                    <strong><?= $this->e($row['name']) ?></strong>
                    <span class="badge"><?= $this->e($row['code']) ?></span>
                    <span class="muted">&middot; <?= $this->number($row['accounts']) ?> comptes</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" action="/admin/comptes" class="inline-form" style="gap:0.75rem;align-items:flex-end">
        <div class="field" style="margin-bottom:0;flex:1 1 16rem">
            <label for="q">Rechercher</label>
            <input id="q" name="q" value="<?= $this->e($search) ?>" placeholder="Nom, prenom ou email">
        </div>
        <div class="field" style="margin-bottom:0">
            <label for="perimetre">Périmètre</label>
            <select id="perimetre" name="perimetre">
                <option value="tous">Tous</option>
                <option value="plateforme" <?= $scope === 'plateforme' ? 'selected' : '' ?>>
                    Administration de la plateforme
                </option>
                <?php foreach ($tenants as $tenant) : ?>
                    <option value="<?= $this->e($tenant['id']) ?>" <?= $scope === (string) $tenant['id'] ? 'selected' : '' ?>>
                        <?= $this->e($tenant['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button button--secondary">Filtrer</button>
        <?php if ($search !== '' || ($scope !== '' && $scope !== 'tous')) : ?>
            <a class="link-button" href="/admin/comptes">Effacer</a>
        <?php endif; ?>
    </form>
</div>

<datalist id="regions-list">
    <?php foreach (Cameroon::regionChoices() as $regionCode => $regionLabel) : ?>
        <option value="<?= $this->e($regionCode) ?>"><?= $this->e($regionLabel) ?></option>
    <?php endforeach; ?>
</datalist>

<div class="card">
    <?php if ($users === []) : ?>
        <p class="muted">Aucun compte ne correspond.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr>
                <th>Nom</th><th>Email</th><th>Établissement</th><th>Role</th>
                <th>État</th><th>Dernière connexion</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user) : ?>
                <?php
                $inactive = (string) $user['status'] !== 'ACTIVE';
                $locked = ($user['locked_until'] ?? null) !== null
                    && strtotime((string) $user['locked_until']) > time();
                ?>
                <tr>
                    <td><?= $this->e(trim($user['last_name'].' '.$user['first_name'])) ?></td>
                    <td><?= $this->e($user['email']) ?></td>
                    <td>
                        <?php if (($user['tenant_name'] ?? null) !== null) : ?>
                            <?= $this->e($user['tenant_name']) ?>
                        <?php elseif (($user['scope_type'] ?? null) === 'REGION') : ?>
                            <span class="badge badge--warning">
                                <?= $this->e(Cameroon::regionName($user['scope_value'] ?? null)) ?>
                            </span>
                        <?php elseif (($user['scope_type'] ?? null) === 'DEPARTMENT') : ?>
                            <span class="badge badge--warning"><?= $this->e($user['scope_value']) ?></span>
                        <?php else : ?>
                            <span class="badge">tout le territoire</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="muted"><?= $this->e($user['role_name'] ?? 'aucun') ?></span></td>
                    <td>
                        <?php if ($inactive) : ?>
                            <span class="badge badge--warning">désactivé</span>
                        <?php elseif ($locked) : ?>
                            <span class="badge badge--warning">verrouillé</span>
                        <?php else : ?>
                            <span class="badge badge--success">actif</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($user['last_login'] ?? null) === null) : ?>
                            <span class="muted">jamais</span>
                        <?php else : ?>
                            <?= $this->date($user['last_login'], 'd/m/Y H:i') ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        <form method="post" action="/admin/comptes/<?= $this->e($user['id']) ?>/mot-de-passe"
                              class="inline-form">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <button type="submit" class="link-button">Nouveau mot de passe</button>
                        </form>
                        <?php if ($locked) : ?>
                            <form method="post" action="/admin/comptes/<?= $this->e($user['id']) ?>/déverrouiller"
                                  class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Déverrouiller</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($inactive) : ?>
                            <form method="post" action="/admin/comptes/<?= $this->e($user['id']) ?>/activer"
                                  class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Réactiver</button>
                            </form>
                        <?php else : ?>
                            <form method="post" action="/admin/comptes/<?= $this->e($user['id']) ?>/désactiver"
                                  class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Désactiver</button>
                            </form>
                        <?php endif; ?>

                        <?php if (($user['tenant_name'] ?? null) === null && $rbac->allows('users:assign-roles')) : ?>
                            <details>
                                <summary class="link-button" style="display:inline">Périmètre</summary>
                                <form method="post" action="/admin/comptes/<?= $this->e($user['id']) ?>/périmètre"
                                      class="inline-form" style="margin-top:0.5rem;justify-content:flex-end">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <select name="scope_type">
                                        <option value="PLATFORM">Tout le territoire</option>
                                        <option value="REGION" <?= ($user['scope_type'] ?? '') === 'REGION' ? 'selected' : '' ?>>Region</option>
                                        <option value="DEPARTMENT" <?= ($user['scope_type'] ?? '') === 'DEPARTMENT' ? 'selected' : '' ?>>Département</option>
                                    </select>
                                    <input name="scope_value" list="regions-list" style="width:11rem"
                                           value="<?= $this->e($user['scope_value'] ?? '') ?>">
                                    <button type="submit" class="link-button">Appliquer</button>
                                </form>
                            </details>
                        <?php endif; ?>

                        <?php if ($rbac->allows('users:delete')) : ?>
                            <details>
                                <summary class="link-button" style="display:inline">Supprimer</summary>
                                <form method="post" action="/admin/comptes/<?= $this->e($user['id']) ?>/supprimer"
                                      class="inline-form" style="margin-top:0.5rem;justify-content:flex-end">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <?php // La confirmation est l adresse elle-meme : une case a cocher se
                                          // coche par megarde, une adresse se recopie deliberement. ?>
                                    <input name="confirm" required placeholder="Saisir l email pour confirmer"
                                           style="width:16rem">
                                    <button type="submit" class="link-button">Confirmer</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1) : ?>
            <p class="muted">
                Page <?= $this->number($page) ?> sur <?= $this->number($pages) ?>
                <?php if ($page > 1) : ?>
                    &middot; <a href="<?= $this->e($query(['q' => $search, 'périmètre' => $scope, 'page' => $page - 1])) ?>">precedente</a>
                <?php endif; ?>
                <?php if ($page < $pages) : ?>
                    &middot; <a href="<?= $this->e($query(['q' => $search, 'périmètre' => $scope, 'page' => $page + 1])) ?>">suivante</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <p class="muted">
            Un mot de passe ne se consulte pas, il se remplace : il n'existe
            nulle part en clair, et cela doit le rester.
        </p>
    <?php endif; ?>
</div>
