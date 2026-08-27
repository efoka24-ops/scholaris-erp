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

use Scholaris\View\Navigation;

$onlyPlatform = ($isPlatformAccount ?? false) && $tenantName === null;

// Les rubriques sont regroupees : une liste de vingt entrees sans intertitre
// oblige a la relire entierement pour trouver la bonne.
$sections = $onlyPlatform
    ? Navigation::platform()
    : Navigation::school($features);

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currentLabel = Navigation::currentLabel($sections, $currentPath, $title ?? 'Accueil');

$fullName = trim(($auth['first_name'] ?? '').' '.($auth['last_name'] ?? ''));
$initials = Navigation::initials($fullName);
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
    <meta name="theme-color" content="#3c1e86">
    <!-- Saisie hors-ligne : differe l'envoi quand le reseau manque, sans
         jamais dupliquer au retour de la connexion. -->
    <script src="/assets/offline.js" defer></script>
</head>
<body>
<nav class="sidebar" id="sidebar">
    <a class="brand" href="<?= $onlyPlatform ? '/admin' : '/dashboard' ?>">
        <span class="brand__mark">S</span>
        <span>
            <span class="brand__name">SCHOLARIS</span>
            <span class="brand__tag">ERP v2.0 &middot; TRU GROUP</span>
        </span>
    </a>

    <div class="sidebar__nav">
        <?php foreach ($sections as $section) : ?>
            <?php
            // Une rubrique dont toutes les entrees sont masquees ne doit pas
            // laisser son intertitre orphelin.
            $visible = [];

            foreach ($section['items'] as $item) {
                $allowed = $item['permission'] === null || $rbac->allows($item['permission']);
                $available = $item['feature'] === null || $features->enabled($item['feature']);

                if ($allowed && $available) {
                    $visible[] = $item;
                }
            }
            ?>
            <?php if ($visible === []) { continue; } ?>

            <?php if ($section['title'] !== null) : ?>
                <div class="sidebar__section"><?= $this->e($section['title']) ?></div>
            <?php endif; ?>

            <?php foreach ($visible as $item) : ?>
                <a href="<?= $this->e($item['href']) ?>"
                   class="sidebar__link<?= Navigation::isActive($item['href'], $currentPath) ? ' sidebar__link--active' : '' ?>">
                    <?= $this->raw(Navigation::icon($item['icon'])) ?>
                    <span><?= $this->e($item['label']) ?></span>
                    <?php if (($badges[$item['href']] ?? 0) > 0) : ?>
                        <span class="sidebar__badge"><?= $this->number($badges[$item['href']]) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <div class="sidebar__foot">
        <span class="avatar avatar--<?= Navigation::colorIndex($fullName) ?>"><?= $this->e($initials) ?></span>
        <span class="sidebar__identity">
            <strong><?= $this->e($fullName !== '' ? $fullName : 'Utilisateur') ?></strong>
            <span><?= $this->e($auth['email'] ?? '') ?></span>
        </span>
        <form method="post" action="/logout" class="inline-form">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <button type="submit" class="link-button" title="Se deconnecter" aria-label="Se deconnecter">
                <?= $this->raw(Navigation::icon('logout')) ?>
            </button>
        </form>
    </div>
</nav>

<header class="topbar">
    <div class="topbar__left">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Afficher la navigation">
            <?= $this->raw(Navigation::icon('menu')) ?>
        </button>
        <div class="topbar__crumb">
            <span>SCHOLARIS</span>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="topbar__crumb-current"><?= $this->e($currentLabel) ?></span>
        </div>
    </div>

    <div class="topbar__right">
        <?php if ($tenantName !== null) : ?>
            <span class="topbar__tenant"><?= $this->e($tenantName) ?></span>
        <?php else : ?>
            <span class="section-tag">Plateforme</span>
        <?php endif; ?>

        <?php if (($currentPeriod ?? null) !== null) : ?>
            <span class="topbar__period"><i></i><?= $this->e($currentPeriod) ?></span>
        <?php endif; ?>
    </div>
</header>

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

<script>
// Navigation repliable sur telephone : dix-sept rem de menu ne laisseraient
// rien au contenu.
(function () {
    var toggle = document.getElementById('nav-toggle');

    if (!toggle) { return; }

    toggle.addEventListener('click', function () {
        document.body.classList.toggle('nav-open');
    });

    // Un clic dans le contenu referme le menu : sur mobile il recouvre la page.
    document.querySelector('.content').addEventListener('click', function () {
        document.body.classList.remove('nav-open');
    });
})();
</script>
</body>
</html>
