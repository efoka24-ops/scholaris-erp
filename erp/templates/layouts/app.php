<?php
/**
 * Gabarit de l'espace authentifie.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $auth
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string|null $tenantName
 * @var string|null $flashSuccess
 * @var string|null $flashError
 * @var string $csrfToken
 * @var \Scholaris\Tenant\Features $features
 */

// Les entrees de menu sont filtrees par permission : un utilisateur ne voit que
// ce qu'il peut reellement ouvrir, plutot que de decouvrir un refus en cliquant.
// Un administrateur de plateforme sans etablissement courant ne voit que
// l'administration : les rubriques scolaires n'auraient aucune donnee a
// afficher, faute d'ecole a laquelle il appartienne.
$onlyPlatform = ($isPlatformAccount ?? false) && $tenantName === null;

$navigation = $onlyPlatform
    ? [
        ['/admin', 'Tableau de bord', 'tenants:read', null],
        ['/admin/etablissements', 'Demandes d ouverture', 'tenants:read', null],
        ['/admin/parc', 'Parc d etablissements', 'tenants:read', null],
        ['/admin/courriers', 'Courriers envoyes', 'tenants:read', null],
        ['/admin/maintenance', 'Maintenance', 'tenants:update', null],
    ]
    // Chaque entree porte sa permission et, le cas echeant, la fonctionnalite
    // dont elle depend. Une ecole primaire ne verra donc jamais « Examens
    // officiels » si ce module ne la concerne pas.
    : [
        ['/dashboard', 'Tableau de bord', null, null],
        ['/students', $features->label('students', 'Eleves'), 'students:read', null],
        ['/enrollments', 'Inscriptions', 'enrollments:read', null],
        ['/classrooms', $features->label('classrooms', 'Classes'), 'classrooms:read', 'structure.classrooms'],
        ['/timetable', 'Emplois du temps', 'timetables:read', 'life.timetable'],
        ['/attendance', 'Presences', 'attendance:read', 'life.attendance'],
        ['/grades', 'Notes', 'grades:read', null],
        ['/bulletins', 'Bulletins', 'bulletins:read', null],
        ['/discipline', 'Discipline', 'discipline:read', 'life.discipline'],
        ['/exams', 'Examens officiels', 'exams:read', 'exams.official'],
        ['/finance', 'Finance', 'finance-dashboard:read', 'finance.fees'],
        ['/finance/invoices', 'Factures', 'invoices:read', 'finance.payments'],
        ['/health', 'Sante', 'health:read', 'life.health'],
        ['/library', 'Bibliotheque', 'library:read', 'life.library'],
        ['/transport', 'Transport', 'transport:read', 'life.transport'],
        ['/catering', 'Cantine', 'catering:read', 'life.catering'],
        ['/patrimoine', 'Patrimoine', 'assets:read', 'life.assets'],
        ['/hr', 'Personnel', 'hr:read', 'hr.payroll'],
        ['/communication', 'Communication', 'communications:read', null],
        ['/messages', 'Messagerie', 'internal-messages:read', null],
        ['/annees-scolaires', 'Annee scolaire', 'academic-years:read', null],
        ['/parametres', 'Parametres', 'tenants:update', null],
    ];

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title ?? 'SCHOLARIS') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/icon.svg">
    <meta name="theme-color" content="#09080f">
    <!-- Saisie hors-ligne : differe l'envoi quand le reseau manque, sans
         jamais dupliquer au retour de la connexion. -->
    <script src="/assets/offline.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="topbar__left">
        <a class="brand" href="/dashboard">
            <span class="brand__mark">S</span>
            <span class="brand__name">SCHOLARIS<span>.</span></span>
        </a>
        <?php if ($tenantName !== null) : ?>
            <span class="topbar__tenant"><?= $this->e($tenantName) ?></span>
        <?php else : ?>
            <span class="section-tag" style="color:#c8ff00">PLATEFORME</span>
        <?php endif; ?>
    </div>
    <div class="topbar__right">
        <span class="topbar__user">
            <?= $this->e(trim(($auth['first_name'] ?? '').' '.($auth['last_name'] ?? ''))) ?>
        </span>
        <form method="post" action="/logout" class="inline-form">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <button type="submit" class="link-button">Deconnexion</button>
        </form>
    </div>
</header>

<div class="shell">
    <nav class="sidebar">
        <?php foreach ($navigation as [$href, $label, $permission, $feature]) : ?>
            <?php
            $allowed = $permission === null || $rbac->allows($permission);
            $available = $feature === null || $features->enabled($feature);
            ?>
            <?php if ($allowed && $available) : ?>
                <a href="<?= $this->e($href) ?>"
                   class="sidebar__link<?= str_starts_with($currentPath, $href) && $href !== '/dashboard' || $currentPath === $href ? ' sidebar__link--active' : '' ?>">
                    <?= $this->e($label) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <main class="content">
        <?php if (($isPlatformAccount ?? false) && $tenantName !== null) : ?>
            <div class="impersonation">
                <span>
                    Vous consultez <strong><?= $this->e($tenantName) ?></strong> en tant
                    qu administrateur de la plateforme. Cet acces est journalise.
                </span>
                <form method="post" action="/admin/quitter" class="inline-form">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="button button--secondary">Quitter cet etablissement</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess !== null) : ?>
            <div class="alert alert--success" role="status"><?= $this->e($flashSuccess) ?></div>
        <?php endif; ?>

        <?php if ($flashError !== null) : ?>
            <div class="alert alert--error" role="alert"><?= $this->e($flashError) ?></div>
        <?php endif; ?>

        <?= $this->raw($this->section('content')) ?>
    </main>
</div>
</body>
</html>
