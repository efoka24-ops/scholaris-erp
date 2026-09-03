<?php
/**
 * Espace de l'administrateur de la plateforme.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $tenants
 * @var int $pendingRequests, $totalStudents, $totalUsers
 * @var array<int, array<string, mixed>> $recentLogins
 * @var array<string, mixed> $stats
 * @var array{regions: list<array<string, mixed>>, unlocated: array<string, mixed>} $map
 * @var array<string, mixed>|null $auth
 * @var string $csrfToken
 */

use Scholaris\Support\Cameroon;
use Scholaris\View\Navigation;

$this->extends('layouts.app');
$title = 'Administration de la plateforme';

$days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$months = [
    1 => 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre',
];
$now = time();
$fullDate = $days[(int) date('w', $now)].' '.date('j', $now).' '
    .$months[(int) date('n', $now)].' '.date('Y', $now);

/** Variation affichee : signe explicite, et rien du tout si incalculable. */
$trend = static function (?float $value, string $unit = '%'): string {
    if ($value === null) {
        return '';
    }

    $sign = $value > 0 ? '+' : '';
    $tone = $value > 0 ? 'up' : ($value < 0 ? 'down' : 'flat');

    // Les valeurs proviennent d un calcul numerique et de constantes du
    // gabarit : rien d ici ne vient de l utilisateur.
    return '<span class="stat__trend stat__trend--'.$tone.'">'
        .$sign.htmlspecialchars(number_format($value, 1, ',', ' '), ENT_QUOTES, 'UTF-8')
        .' '.htmlspecialchars($unit, ENT_QUOTES, 'UTF-8')
        .'</span>';
};

/** Montants en francs : abreges au million des qu ils deviennent illisibles. */
$money = static function (float $amount): string {
    if ($amount >= 1000000) {
        return number_format($amount / 1000000, 2, ',', ' ').'M';
    }

    return number_format($amount, 0, ',', ' ');
};
?>
<div class="hero">
    <div class="hero__eyebrow">Tableau de bord</div>
    <h1>Bonjour <?= $this->e(trim(($auth['first_name'] ?? '').' '.($auth['last_name'] ?? ''))) ?></h1>
    <p class="hero__meta">
        <?= $this->e($fullDate) ?> &middot; <span id="platform-clock"><?= date('H:i', $now) ?></span>
        <?php // Dire ce que l'on regarde : sans cela, un delegue croit voir le
              // pays entier et lit ses chiffres comme des chiffres nationaux. ?>
        &middot; <?= $this->e($scope->label()) ?>
    </p>

    <div class="hero__chips">
        <span class="hero__chip">
            Établissements <strong><?= $this->number($stats['tenants']['total']) ?></strong>
        </span>
        <span class="hero__chip">
            Comptes <strong><?= $this->number($stats['users']['total']) ?></strong>
        </span>
        <span class="hero__chip">
            En attente <strong><?= $this->number($stats['requests']['pending']) ?></strong>
        </span>
    </div>
</div>

<div class="page-header">
    <p class="muted" style="margin:0">
        Vous n'appartenez a aucun établissement : pour consulter les données de
        l'un d'eux, placez-vous dedans.
    </p>
    <a class="button<?= $stats['requests']['pending'] > 0 ? '' : ' button--secondary' ?>"
       href="/admin/établissements">
        Demandes d'ouverture
        <?php if ($stats['requests']['pending'] > 0) : ?>
            (<?= $this->number($stats['requests']['pending']) ?>)
        <?php endif; ?>
    </a>
</div>

<?php if ($stats['requests']['pending'] > 0) : ?>
    <div class="alert alert--<?= ($stats['requests']['oldestPendingDays'] ?? 0) >= 7 ? 'error' : 'info' ?>">
        <strong><?= $this->number($stats['requests']['pending']) ?></strong>
        demande<?= $stats['requests']['pending'] > 1 ? 's' : '' ?> d'ouverture
        en attente de votre décision.
        <?php if (($stats['requests']['oldestPendingDays'] ?? null) !== null) : ?>
            La plus ancienne attend depuis
            <strong><?= $this->number($stats['requests']['oldestPendingDays']) ?> jour<?= $stats['requests']['oldestPendingDays'] > 1 ? 's' : '' ?></strong>.
        <?php endif; ?>
        <a href="/admin/etablissements">Les instruire</a>
    </div>
<?php endif; ?>

<div class="stats stats--rich">
    <div class="stat stat--tinted stat--blue">
        <div class="stat__icon"><?= $this->raw(Navigation::icon("users")) ?></div>
        <div class="stat__label">Élèves inscrits</div>
        <div class="stat__value"><?= $this->number($stats['students']['total']) ?></div>
        <div class="stat__foot">
            +<?= $this->number($stats['students']['thisMonth']) ?> ce mois
            <?= $trend($stats['students']['trend']) ?>
        </div>
    </div>

    <div class="stat stat--tinted stat--green">
        <div class="stat__icon"><?= $this->raw(Navigation::icon("check")) ?></div>
        <div class="stat__label">Taux de présence</div>
        <?php if ($stats['attendance']['rate'] === null) : ?>
            <div class="stat__value stat__value--muted">&mdash;</div>
            <div class="stat__foot">aucun appel saisi aujourd'hui</div>
        <?php else : ?>
            <div class="stat__value"><?= $this->e(number_format($stats['attendance']['rate'], 1, ',', ' ')) ?>%</div>
            <div class="stat__foot">
                aujourd'hui, sur <?= $this->number($stats['attendance']['recorded']) ?> saisies
                <?= $trend($stats['attendance']['trend'], 'pt') ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="stat stat--tinted stat--amber">
        <div class="stat__icon"><?= $this->raw(Navigation::icon("inbox")) ?></div>
        <div class="stat__label">Demandes d'établissement</div>
        <div class="stat__value"><?= $this->number($stats['requests']['pending']) ?></div>
        <div class="stat__foot">
            en attente &middot; <?= $this->number($stats['requests']['thisMonth']) ?> déposées ce mois
            <?= $trend($stats['requests']['trend']) ?>
        </div>
    </div>

    <div class="stat stat--tinted stat--pink">
        <div class="stat__icon"><?= $this->raw(Navigation::icon("wallet")) ?></div>
        <div class="stat__label">Recouvrement du mois</div>
        <div class="stat__value"><?= $this->e($money((float) $stats['collection']['thisMonth'])) ?></div>
        <div class="stat__foot">
            FCFA encaissés
            <?= $trend($stats['collection']['trend']) ?>
        </div>
    </div>

    <div class="stat stat--tinted stat--violet">
        <div class="stat__icon"><?= $this->raw(Navigation::icon("building")) ?></div>
        <div class="stat__label">Établissements</div>
        <div class="stat__value"><?= $this->number($stats['tenants']['total']) ?></div>
        <div class="stat__foot">
            <?= $this->number($stats['tenants']['active']) ?> actifs
            <?php if ($stats['tenants']['suspended'] > 0) : ?>
                &middot; <?= $this->number($stats['tenants']['suspended']) ?> suspendus
            <?php endif; ?>
        </div>
    </div>

    <div class="stat">
        <div class="stat__label">Comptes</div>
        <div class="stat__value"><?= $this->number($stats['users']['total']) ?></div>
        <div class="stat__foot">tous établissements confondus</div>
    </div>
</div>

<?php
/**
 * Tout ce que le Super Admin administre, avec son chiffre du moment.
 *
 * L'écran montrait des indicateurs sans ouvrir sur rien : on y lisait
 * « 3 demandes en attente » sans pouvoir les instruire, et la moitie des
 * écrans n'etait atteignable que par le menu lateral. Un tableau de bord qui
 * ne mene nulle part n'est qu'un rapport.
 *
 * @var array<string, int> $governance
 * @var array<string, mixed> $hr
 * @var int $pendingMigrations
 */
$areas = [
    [
        'href' => '/admin/etablissements',
        'icon' => 'inbox',
        'title' => 'Demandes d\'ouverture',
        'value' => $stats['requests']['pending'],
        'unit' => 'en attente de décision',
        'alert' => $stats['requests']['pending'] > 0,
    ],
    [
        'href' => '/admin/parc',
        'icon' => 'building',
        'title' => 'Parc d\'établissements',
        'value' => $stats['tenants']['total'],
        'unit' => $stats['tenants']['suspended'] > 0
            ? $stats['tenants']['suspended'].' suspendus'
            : 'tous actifs',
        'alert' => $stats['tenants']['suspended'] > 0,
    ],
    [
        'href' => '/admin/comptes',
        'icon' => 'users',
        'title' => 'Comptes',
        'value' => $stats['users']['total'],
        'unit' => 'créer, réinitialiser, suspendre',
        'alert' => false,
    ],
    [
        'href' => '/admin/habilitations',
        'icon' => 'shield',
        'title' => 'Habilitations',
        'value' => $governance['roles'],
        'unit' => 'roles et leurs droits',
        'alert' => false,
    ],
    [
        'href' => '/admin/rapports',
        'icon' => 'chart',
        'title' => 'Rapports',
        'value' => $stats['tenants']['total'],
        'unit' => 'établissements compares, export CSV',
        'alert' => false,
    ],
    [
        'href' => '/admin/journal',
        'icon' => 'history',
        'title' => 'Journal d\'audit',
        'value' => $governance['auditEvents'],
        'unit' => 'actes traces',
        'alert' => false,
    ],
    [
        'href' => '/admin/courriers',
        'icon' => 'mail',
        'title' => 'Courriers envoyés',
        'value' => $governance['mailsTotal'],
        'unit' => $governance['mailsFailed'] > 0
            ? $governance['mailsFailed'].' non remis'
            : 'tous remis',
        'alert' => $governance['mailsFailed'] > 0,
    ],
    [
        'href' => '/admin/maintenance',
        'icon' => 'settings',
        'title' => 'Maintenance',
        'value' => $pendingMigrations,
        'unit' => $pendingMigrations > 0 ? 'migrations a appliquer' : 'schema a jour',
        'alert' => $pendingMigrations > 0,
    ],
];
?>
<div class="card">
    <h2 style="margin:0">Ce que vous administrez</h2>
    <p class="muted" style="margin:0.25rem 0 1.25rem">
        Chaque domaine avec son chiffre du moment. En ambre, ce qui appelle une
        action de votre part.
    </p>

    <div class="tiles">
        <?php foreach ($areas as $area) : ?>
            <a class="tile<?= $area['alert'] ? ' tile--alert' : '' ?>" href="<?= $this->e($area['href']) ?>">
                <span class="tile__icon"><?= $this->raw(Navigation::icon($area['icon'])) ?></span>
                <span class="tile__body">
                    <span class="tile__value"><?= $this->number($area['value']) ?></span>
                    <strong><?= $this->e($area['title']) ?></strong>
                    <span class="muted"><?= $this->e($area['unit']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

    <div class="card">
        <h2 style="margin:0">Personnel</h2>
        <p class="muted" style="margin:0.2rem 0 1rem">
            Effectifs et encadrement sur votre périmètre
        </p>

        <div class="stats stats--compact" style="margin-bottom:1rem">
            <div class="stat">
                <div class="stat__value"><?= $this->number($hr['total']) ?></div>
                <div class="stat__label">Agents en poste</div>
            </div>
            <div class="stat stat--figure-violet">
                <div class="stat__value">
                    <?php if ($hr['ratio'] === null) : ?>
                        &mdash;
                    <?php else : ?>
                        <?= $this->e(number_format($hr['ratio'], 1, ',', ' ')) ?>
                    <?php endif; ?>
                </div>
                <div class="stat__label">Élèves par agent</div>
            </div>
            <div class="stat stat--figure-amber">
                <div class="stat__value"><?= $this->number($hr['onLeave']) ?></div>
                <div class="stat__label">En congé aujourd'hui</div>
            </div>
        </div>

        <?php if ($hr['byPosition'] === []) : ?>
            <p class="muted">Aucun personnel enregistré sur ce périmètre.</p>
        <?php else : ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fonction</th><th>Effectif</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($hr['byPosition'], 0, 8) as $row) : ?>
                        <tr>
                            <td><?= $this->e($row['position']) ?></td>
                            <td><?= $this->number($row['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="muted">
            Le taux d'encadrement est le seul chiffre qui se compare d'un
            établissement a l'autre : un effectif brut ne dit rien sans la
            taille de l'école.
        </p>
    </div>

<?= $this->include('platform.map', ['map' => $map]) ?>

<div class="card">
    <div class="inline-form" style="justify-content:space-between;align-items:center">
        <h2 style="margin:0">Établissements</h2>
        <a class="button button--secondary" href="/admin/parc">Gerer le parc</a>
    </div>

    <?php if ($tenants === []) : ?>
        <p class="muted">Aucun établissement sur la plateforme.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr>
                <th>Code</th><th>Nom</th><th>Type</th><th>Localisation</th>
                <th>Comptes</th><th>Élèves</th><th>Pre-inscription</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $tenant) : ?>
                <tr>
                    <td><span class="badge"><?= $this->e($tenant['code']) ?></span></td>
                    <td><?= $this->e($tenant['name']) ?></td>
                    <td><?= $this->e($tenant['type']) ?></td>
                    <td>
                        <?= $this->e(Cameroon::regionName($tenant['region'] ?? null)) ?>
                        <?php if (($tenant['city'] ?? null) !== null && $tenant['city'] !== '') : ?>
                            <span class="muted">&middot; <?= $this->e($tenant['city']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->number($tenant['users_count']) ?></td>
                    <td><?= $this->number($tenant['students_count']) ?></td>
                    <td>
                        <?php if ((int) $tenant['public_enrollment_enabled'] === 1) : ?>
                            <span class="badge">ouverte</span>
                        <?php else : ?>
                            <span class="muted">fermée</span>
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
            Entrer dans un établissement est journalisé. C'est ce qui distingue
            un accès legitime d'un accès silencieux aux données d'une école.
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Dernières connexions</h2>

    <?php if ($recentLogins === []) : ?>
        <p class="muted">Aucune connexion enregistrée.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date</th><th>Compte</th><th>Établissement</th><th>Adresse IP</th></tr>
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

<script>
// L'heure affichée doit rester juste : une page laissee ouverte toute la
// matinee annoncerait sinon l'heure de son chargement.
(function () {
    var clock = document.getElementById('platform-clock');

    if (!clock) { return; }

    setInterval(function () {
        var now = new Date();
        clock.textContent = String(now.getHours()).padStart(2, '0') + ':'
            + String(now.getMinutes()).padStart(2, '0');
    }, 20000);
})();
</script>
