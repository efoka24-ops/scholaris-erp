<?php
/**
 * Gabarit des pages publiques : accueil et formulaires ouverts.
 *
 * Distinct de layouts.guest, qui centre une carte unique : ici la page se
 * deroule en sections, avec un en-tete et un pied de page.
 *
 * @var \Scholaris\View\View $this
 * @var string $appName
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title ?? 'Accueil') ?> - <?= $this->e($appName ?? 'SCHOLARIS') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div class="topbar__left">
        <a class="topbar__brand" href="/"><?= $this->e($appName ?? 'SCHOLARIS') ?></a>
    </div>
    <div class="topbar__right">
        <a href="/pre-inscription">Pre-inscription</a>
        <a href="/demande-etablissement">Creer un etablissement</a>
        <a class="button" href="/login">Se connecter</a>
    </div>
</header>

<main class="public">
    <?= $this->raw($this->section('content')) ?>
</main>

<footer class="footer">
    <p><?= $this->e($appName ?? 'SCHOLARIS') ?> &middot; Gestion scolaire</p>
</footer>
</body>
</html>
