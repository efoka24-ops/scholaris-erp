<?php
/**
 * Trace detaillee, affichee hors production uniquement.
 *
 * Application::renderUnexpected ne rend ce gabarit que si APP_ENV n'est pas
 * "production" : en production, la trace part dans les journaux et l'utilisateur
 * voit une page neutre.
 *
 * @var \Scholaris\View\View $this
 * @var \Throwable $exception
 * @var bool $isDatabase
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Erreur : <?= $this->e(get_class($exception)) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="content">
    <div class="card">
        <h1 class="error__code">Erreur applicative</h1>
        <p class="error__message"><strong><?= $this->e(get_class($exception)) ?></strong></p>
        <p><?= $this->e($exception->getMessage()) ?></p>
        <p class="muted"><?= $this->e($exception->getFile()) ?> ligne <?= $this->e($exception->getLine()) ?></p>

        <?php if ($isDatabase) : ?>
            <div class="alert alert--error">
                Erreur de base de données. Verifiez que les migrations ont ete appliquées
                (<code>artisan migrate</code>) et que le .env pointe la bonne base.
            </div>
        <?php endif; ?>

        <pre class="trace"><?= $this->e($exception->getTraceAsString()) ?></pre>
    </div>
</main>
</body>
</html>
