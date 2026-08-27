<?php
/**
 * Gabarit des pages accessibles sans authentification.
 *
 * @var \Scholaris\View\View $this
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title ?? 'Connexion') ?></title>
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<main class="auth">
    <?= $this->raw($this->section('content')) ?>
</main>
</body>
</html>
