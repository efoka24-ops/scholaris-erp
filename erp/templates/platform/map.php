<?php
/**
 * Carte du parc.
 *
 * Le trace est schematique et assume comme tel : son objet n est pas la
 * precision cartographique mais la lecture d un coup d oeil — ou sommes-nous
 * implantes, ou sommes-nous sollicites. Il est dessine en SVG dans la page,
 * sans bibliotheque ni appel exterieur : cela compte sur une connexion
 * camerounaise, et la politique de securite interdit de toute facon les
 * ressources tierces.
 *
 * @var \Scholaris\View\View $this
 * @var array{regions: list<array<string, mixed>>, unlocated: array<string, mixed>} $map
 */

use Scholaris\Support\Cameroon;

$typeLabels = [
    'PRIMAIRE' => 'École primaire',
    'COLLEGE' => 'College',
    'LYCEE_GENERAL' => 'Lycee général',
    'LYCEE_TECHNIQUE' => 'Lycee technique',
    'CENTRE_FORMATION' => 'Centre de formation',
    'SUPERIEUR' => 'Superieur',
];

$totalTenants = 0;
$totalPending = 0;

foreach ($map['regions'] as $region) {
    $totalTenants += count($region['tenants']);
    $totalPending += count($region['pending']);
}

$unlocatedTenants = count($map['unlocated']['tenants']);
$unlocatedPending = count($map['unlocated']['pending']);
?>
<div class="card">
    <div class="inline-form" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h2 style="margin:0">Le parc sur le territoire</h2>
            <p class="muted" style="margin:0.25rem 0 0">
                Survolez une region pour en voir le détail. Trace schematique.
            </p>
        </div>
        <div class="map__legend">
            <span><i class="map__key map__key--active"></i> établissements (<?= $this->number($totalTenants) ?>)</span>
            <span><i class="map__key map__key--pending"></i> demandes en attente (<?= $this->number($totalPending) ?>)</span>
        </div>
    </div>

    <div class="map">
        <svg class="map__svg" viewBox="0 0 <?= Cameroon::WIDTH ?> <?= Cameroon::HEIGHT ?>"
             role="img" aria-label="Carte schematique du Cameroun et répartition du parc">
            <path class="map__country" d="<?= $this->e(Cameroon::outlinePath()) ?>"/>

            <?php foreach ($map['regions'] as $region) : ?>
                <?php
                $tenantCount = count($region['tenants']);
                $pendingCount = count($region['pending']);
                $hasAny = $tenantCount > 0 || $pendingCount > 0;
                // Le rayon suit la racine du nombre : une region deux fois plus
                // fournie occupe deux fois plus de surface, non quatre.
                $radius = $hasAny ? 9 + sqrt($tenantCount + $pendingCount) * 5 : 4;
                ?>
                <g class="map__region<?= $hasAny ? ' is-activé' : '' ?>"
                   tabindex="<?= $hasAny ? '0' : '-1' ?>"
                   data-region="<?= $this->e($region['code']) ?>">
                    <?php if ($pendingCount > 0) : ?>
                        <circle class="map__halo" cx="<?= $region['x'] ?>" cy="<?= $region['y'] ?>"
                                r="<?= round($radius + 7, 1) ?>"/>
                    <?php endif; ?>
                    <circle class="map__dot" cx="<?= $region['x'] ?>" cy="<?= $region['y'] ?>"
                            r="<?= round($radius, 1) ?>"/>
                    <?php if ($hasAny) : ?>
                        <text class="map__count" x="<?= $region['x'] ?>" y="<?= $region['y'] + 4 ?>">
                            <?= $this->number($tenantCount + $pendingCount) ?>
                        </text>
                    <?php endif; ?>
                    <text class="map__name" x="<?= $region['x'] ?>" y="<?= $region['y'] - $radius - 8 ?>">
                        <?= $this->e($region['name']) ?>
                    </text>
                </g>
            <?php endforeach; ?>
        </svg>

        <?php foreach ($map['regions'] as $region) : ?>
            <div class="map__detail" id="map-detail-<?= $this->e($region['code']) ?>" hidden>
                <h3><?= $this->e($region['name']) ?></h3>
                <p class="muted">Chef-lieu : <?= $this->e($region['capital']) ?></p>

                <?php if ($region['tenants'] === [] && $region['pending'] === []) : ?>
                    <p class="muted">Aucun établissement ni demande dans cette region.</p>
                <?php endif; ?>

                <?php if ($region['tenants'] !== []) : ?>
                    <h4>Établissements (<?= $this->number(count($region['tenants'])) ?>)</h4>
                    <ul class="map__list">
                        <?php foreach ($region['tenants'] as $tenant) : ?>
                            <li>
                                <strong><?= $this->e($tenant['name']) ?></strong>
                                <span class="badge"><?= $this->e($tenant['code']) ?></span>
                                <?php if ((string) $tenant['platform_status'] === 'SUSPENDED') : ?>
                                    <span class="badge badge--warning">suspendu</span>
                                <?php endif; ?>
                                <br>
                                <span class="muted">
                                    <?= $this->e($typeLabels[$tenant['type']] ?? $tenant['type']) ?>
                                    <?php if (($tenant['city'] ?? null) !== null && $tenant['city'] !== '') : ?>
                                        &middot; <?= $this->e($tenant['city']) ?>
                                    <?php endif; ?>
                                    &middot; <?= $this->number($tenant['students_count']) ?> élèves
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($region['pending'] !== []) : ?>
                    <h4>En attente de validation (<?= $this->number(count($region['pending'])) ?>)</h4>
                    <ul class="map__list">
                        <?php foreach ($region['pending'] as $demand) : ?>
                            <li>
                                <strong><?= $this->e($demand['name']) ?></strong>
                                <span class="badge badge--warning"><?= $this->e($demand['reference'] ?: 'sans référence') ?></span>
                                <br>
                                <span class="muted">
                                    <?= $this->e($typeLabels[$demand['type']] ?? $demand['type']) ?>
                                    <?php if (($demand['city'] ?? null) !== null && $demand['city'] !== '') : ?>
                                        &middot; <?= $this->e($demand['city']) ?>
                                    <?php endif; ?>
                                    &middot; déposé le <?= $this->date($demand['created_at']) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p><a href="/admin/etablissements">Instruire ces demandes</a></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="map__detail map__detail--idle" id="map-detail-idle">
            <h3>Répartition</h3>
            <p class="muted">
                Passez sur une region pour en voir les établissements et les
                demandes en cours.
            </p>
            <?php if ($unlocatedTenants > 0 || $unlocatedPending > 0) : ?>
                <div class="alert alert--info" style="margin-top:1rem">
                    <?php if ($unlocatedTenants > 0) : ?>
                        <?= $this->number($unlocatedTenants) ?>
                        établissement<?= $unlocatedTenants > 1 ? 's' : '' ?> sans region renseignee.
                    <?php endif; ?>
                    <?php if ($unlocatedPending > 0) : ?>
                        <?= $this->number($unlocatedPending) ?>
                        demande<?= $unlocatedPending > 1 ? 's' : '' ?> sans region.
                    <?php endif; ?>
                    Ils ne peuvent pas etre situes sur la carte.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Le détail suit le survol et le clavier : une carte qui ne repond qu'a la
// souris exclut ceux qui n'en utilisent pas.
(function () {
    var map = document.currentScript.previousElementSibling;

    if (!map) { return; }

    var idle = map.querySelector('#map-détail-idle');
    var current = null;

    function show(code) {
        var panel = code ? map.querySelector('#map-détail-' + code) : null;

        if (current === panel) { return; }

        map.querySelectorAll('.map__detail').forEach(function (el) { el.hidden = true; });
        (panel || idle).hidden = false;
        current = panel;

        map.querySelectorAll('.map__region').forEach(function (el) {
            el.classList.toggle('is-hovered', el.dataset.region === code);
        });
    }

    map.querySelectorAll('.map__region').forEach(function (region) {
        var code = region.dataset.region;

        region.addEventListener('mouseenter', function () { show(code); });
        region.addEventListener('focus', function () { show(code); });
    });

    map.addEventListener('mouseleave', function () { show(null); });
})();
</script>
