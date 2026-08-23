<?php
/**
 * Page d'erreur. Ne divulgue jamais de detail technique : le message affiche
 * est celui, volontairement generique, porte par HttpException.
 *
 * @var \Scholaris\View\View $this
 * @var int $status
 * @var string $message
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Erreur <?= $this->e($status) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="auth">
    <div class="card card--narrow">
        <h1 class="error__code"><?= $this->e($status) ?></h1>
        <p class="error__message"><?= $this->e($message) ?></p>
        <p><a href="/dashboard">Retour au tableau de bord</a></p>
    </div>
</main>
</body>
</html>
