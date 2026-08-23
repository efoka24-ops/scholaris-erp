<?php
/**
 * Module 15 : transport scolaire.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $routes, $vehicles, $students, $subscriptions
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Transport';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<h1>Transport scolaire</h1>
<p class="subtitle">
    <?= $this->number(count($routes)) ?> ligne(s) &middot;
    <?= $this->number(count($vehicles)) ?> vehicule(s) &middot;
    <?= $this->number(count($subscriptions)) ?> abonnement(s)
</p>

<?php if ($routes === []) : ?>
    <div class="card"><p class="muted">Aucune ligne. Creez un vehicule puis une ligne.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Ligne</th><th>Vehicule</th><th>Places</th><th>Arrets</th><th>Inscrire</th></tr>
        </thead>
        <tbody>
        <?php foreach ($routes as $route) : ?>
            <?php $capacity = (int) ($route['capacity'] ?? 0); ?>
            <tr>
                <td><?= $this->e($route['name']) ?></td>
                <td><?= $this->e($route['vehicle_name'] ?: '-') ?></td>
                <td class="font-mono">
                    <?= $this->e($route['subscribers']) ?><?= $capacity > 0 ? ' / '.$this->e($capacity) : '' ?>
                    <?php if ($capacity > 0 && (int) $route['subscribers'] >= $capacity) : ?>
                        <span class="badge">complet</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= $this->e($route['stops'] ?: '-') ?></td>
                <td>
                    <?php if ($rbac->allows('transport:create')) : ?>
                        <form method="post" action="/transport/routes/<?= $this->e($route['id']) ?>/subscribe"
                              style="display:flex;gap:.4rem;flex-wrap:wrap">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <select name="student_id" required style="min-width:190px">
                                <option value="">Eleve...</option>
                                <?php foreach ($students as $student) : ?>
                                    <option value="<?= $this->e($student['id']) ?>">
                                        <?= $this->e($student['last_name'].' '.$student['first_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="stop_name" placeholder="Arret" style="width:130px">
                            <button type="submit" class="link-button">Abonner</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($subscriptions !== []) : ?>
    <div class="card">
        <h2>Abonnements</h2>
        <table class="table">
            <thead><tr><th>Ligne</th><th>Eleve</th><th>Arret</th></tr></thead>
            <tbody>
            <?php foreach ($subscriptions as $subscription) : ?>
                <tr>
                    <td><?= $this->e($subscription['route_name']) ?></td>
                    <td><?= $this->e($subscription['last_name'].' '.$subscription['first_name']) ?></td>
                    <td><?= $this->e($subscription['stop_name'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($rbac->allows('transport:create')) : ?>
    <div class="grid-2" style="gap:1.25rem">
        <form method="post" action="/transport/vehicles" class="card">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Nouveau vehicule</h2>
            <div class="field">
                <label for="v_name">Nom *</label>
                <input id="v_name" name="name" required placeholder="Bus 1">
            </div>
            <div class="field">
                <label for="v_capacity">Capacite *</label>
                <input id="v_capacity" name="capacity" type="number" min="1" value="40" required>
            </div>
            <button type="submit" class="button">Ajouter</button>
        </form>

        <form method="post" action="/transport/routes" class="card">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Nouvelle ligne</h2>
            <div class="field">
                <label for="r_name">Nom *</label>
                <input id="r_name" name="name" required placeholder="Circuit Nord">
            </div>
            <div class="field">
                <label for="r_vehicle">Vehicule</label>
                <select id="r_vehicle" name="vehicle_id">
                    <option value="">Aucun</option>
                    <?php foreach ($vehicles as $vehicle) : ?>
                        <option value="<?= $this->e($vehicle['id']) ?>"><?= $this->e($vehicle['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="r_stops">Arrets</label>
                <input id="r_stops" name="stops" placeholder="Poumpoumre, Plateau, Djamboutou">
            </div>
            <button type="submit" class="button">Creer la ligne</button>
        </form>
    </div>
<?php endif; ?>
