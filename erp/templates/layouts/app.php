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
 */

// Les entrees de menu sont filtrees par permission : un utilisateur ne voit que
// ce qu'il peut reellement ouvrir, plutot que de decouvrir un refus en cliquant.
// Un administrateur de plateforme sans etablissement courant ne voit que
// l'administration : les rubriques scolaires n'auraient aucune donnee a
// afficher, faute d'ecole a laquelle il appartienne.
$onlyPlatform = ($isPlatformAccount ?? false) && $tenantName === null;

$navigation = $onlyPlatform
    ? [
        ['/admin', 'Plateforme', 'tenants:read'],
        ['/admin/etablissements', 'Demandes d ouverture', 'tenants:read'],
    ]
    : [
        ['/dashboard', 'Tableau de bord', null],
        ['/students', 'Eleves', 'students:read'],
        ['/enrollments', 'Inscriptions', 'enrollments:read'],
        ['/classrooms', 'Classes', 'classrooms:read'],
        ['/grades', 'Notes', 'grades:read'],
        ['/finance', 'Finance', 'finance-dashboard:read'],
        ['/finance/invoices', 'Factures', 'invoices:read'],
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
</head>
<body>
<header class="topbar">
    <div class="topbar__left">
        <a class="topbar__brand" href="/dashboard">SCHOLARIS</a>
        <span class="topbar__tenant"><?= $this->e($tenantName) ?></span>
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
        <?php foreach ($navigation as [$href, $label, $permission]) : ?>
            <?php if ($permission === null || $rbac->allows($permission)) : ?>
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
