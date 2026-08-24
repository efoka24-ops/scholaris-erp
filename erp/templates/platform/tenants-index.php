<?php
/**
 * Parc d etablissements : liste, filtres et actions.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $tenants
 * @var string $search
 * @var string $status
 * @var array<string, string> $types
 * @var array<string, string> $regions
 * @var string $csrfToken
 */

use Scholaris\Support\Cameroon;

$this->extends('layouts.app');
$title = 'Parc d etablissements';
?>
<div class="page-header">
    <div>
        <h1>Parc d etablissements</h1>
        <p class="subtitle">
            <?= $this->number(count($tenants)) ?> etablissement<?= count($tenants) > 1 ? 's' : '' ?>
            <?php if ($search !== '' || $status !== '') : ?>
                correspondant a votre recherche
            <?php endif; ?>
        </p>
    </div>
    <a class="button" href="/admin/parc/creer">Ouvrir un etablissement</a>
</div>

<div class="card">
    <form method="get" action="/admin/parc" class="inline-form" style="gap:0.75rem;align-items:flex-end">
        <div class="field" style="margin-bottom:0;flex:1 1 16rem">
            <label for="q">Rechercher</label>
            <input id="q" name="q" value="<?= $this->e($search) ?>" placeholder="Nom ou code">
        </div>
        <div class="field" style="margin-bottom:0">
            <label for="statut">Etat</label>
            <select id="statut" name="statut">
                <option value="">Tous</option>
                <option value="ACTIVE" <?= $status === 'ACTIVE' ? 'selected' : '' ?>>Actifs</option>
                <option value="SUSPENDED" <?= $status === 'SUSPENDED' ? 'selected' : '' ?>>Suspendus</option>
            </select>
        </div>
        <button type="submit" class="button button--secondary">Filtrer</button>
        <?php if ($search !== '' || $status !== '') : ?>
            <a class="link-button" href="/admin/parc">Effacer</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($tenants === []) : ?>
    <div class="card">
        <p class="muted">Aucun etablissement ne correspond.</p>
    </div>
<?php endif; ?>

<?php foreach ($tenants as $tenant) : ?>
    <?php
    $suspended = (string) $tenant['platform_status'] === 'SUSPENDED';
    $config = [];

    if (is_string($tenant['config_json'] ?? null) && $tenant['config_json'] !== '') {
        $decoded = json_decode((string) $tenant['config_json'], true);
        $config = is_array($decoded) ? $decoded : [];
    }
    ?>
    <div class="card">
        <div class="inline-form" style="justify-content:space-between;align-items:flex-start">
            <div>
                <h2 style="margin:0">
                    <?= $this->e($tenant['name']) ?>
                    <span class="badge"><?= $this->e($tenant['code']) ?></span>
                    <?php if ($suspended) : ?>
                        <span class="badge badge--warning">suspendu</span>
                    <?php endif; ?>
                </h2>
                <p class="muted" style="margin:0.35rem 0 0">
                    <?= $this->e($types[$tenant['type']] ?? $tenant['type']) ?>
                    &middot; <?= $this->e($tenant['status']) ?>
                    &middot; <?= $this->e(Cameroon::regionName($tenant['region'] ?? null)) ?>
                    <?php if (($tenant['city'] ?? null) !== null && $tenant['city'] !== '') : ?>
                        &middot; <?= $this->e($tenant['city']) ?>
                    <?php endif; ?>
                    &middot; <?= $this->number($tenant['students_count']) ?> eleves
                    &middot; <?= $this->number($tenant['users_count']) ?> comptes
                </p>
                <?php if ($suspended && isset($config['suspension']['reason'])) : ?>
                    <p class="muted" style="margin:0.35rem 0 0">
                        Motif : <?= $this->e($config['suspension']['reason']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="inline-form" style="gap:0.5rem">
                <a class="button button--secondary" href="/admin/parc/<?= $this->e($tenant['id']) ?>/modifier">
                    Modifier
                </a>
                <form method="post" action="/admin/etablissements/<?= $this->e($tenant['id']) ?>/consulter"
                      class="inline-form">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="link-button">Consulter</button>
                </form>
            </div>
        </div>

        <details style="margin-top:1rem">
            <summary class="muted">Actions sur le compte de cet etablissement</summary>

            <div style="margin-top:1rem;display:grid;gap:1rem">
                <?php if ($suspended) : ?>
                    <form method="post" action="/admin/parc/<?= $this->e($tenant['id']) ?>/reactiver"
                          class="inline-form" style="gap:0.75rem">
                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                        <button type="submit" class="button">Lever la suspension</button>
                        <span class="muted">Les comptes de cet etablissement pourront de nouveau se connecter.</span>
                    </form>
                <?php else : ?>
                    <form method="post" action="/admin/parc/<?= $this->e($tenant['id']) ?>/suspendre"
                          class="inline-form" style="gap:0.75rem;align-items:flex-end">
                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                        <div class="field" style="margin-bottom:0;flex:1 1 20rem">
                            <label for="reason-<?= $this->e($tenant['id']) ?>">Motif de la suspension</label>
                            <input id="reason-<?= $this->e($tenant['id']) ?>" name="reason" required
                                   placeholder="Impaye, fermeture administrative...">
                        </div>
                        <button type="submit" class="button button--secondary">Suspendre</button>
                    </form>
                <?php endif; ?>

                <?php if ((int) $tenant['students_count'] === 0) : ?>
                    <form method="post" action="/admin/parc/<?= $this->e($tenant['id']) ?>/supprimer"
                          class="inline-form" style="gap:0.75rem;align-items:flex-end">
                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                        <div class="field" style="margin-bottom:0">
                            <label for="confirm-<?= $this->e($tenant['id']) ?>">
                                Retirer du parc : saisissez <strong><?= $this->e($tenant['code']) ?></strong>
                            </label>
                            <input id="confirm-<?= $this->e($tenant['id']) ?>" name="confirm" required
                                   style="width:10rem">
                        </div>
                        <button type="submit" class="link-button">Retirer</button>
                    </form>
                <?php else : ?>
                    <p class="muted">
                        Cet etablissement compte <?= $this->number($tenant['students_count']) ?> eleves : il ne
                        peut pas etre retire. Un dossier scolaire ne s efface pas sur un clic — suspendez-le
                        si son activite doit cesser.
                    </p>
                <?php endif; ?>
            </div>
        </details>
    </div>
<?php endforeach; ?>
