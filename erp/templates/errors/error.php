<?php
/**
 * Page d'erreur.
 *
 * Ne divulgue aucun detail technique : le message affiche est celui,
 * volontairement generique, porte par HttpException. Elle nomme en revanche la
 * situation, car un « 404 » sec laisse l'utilisateur sans rien a faire — il ne
 * peut pas savoir si l'adresse est fausse, si le module n'existe pas chez lui,
 * ou si l'application est cassee.
 *
 * @var \Scholaris\View\View $this
 * @var int $status
 * @var string $message
 * @var string $path
 * @var bool $isLogged
 * @var string $home
 */

$titles = [
    403 => 'Acces refuse',
    404 => 'Page introuvable',
    419 => 'Session expiree',
    500 => 'Erreur du serveur',
];

$title = $titles[$status] ?? 'Erreur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title) ?> — SCHOLARIS</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
</head>
<body>
<main class="auth">
    <div class="card card--narrow" style="text-align:center">
        <div class="stat__label" style="justify-content:center"><?= $this->e($status) ?></div>
        <h1 class="auth__title"><?= $this->e($title) ?></h1>

        <?php if ($status === 404) : ?>
            <p class="auth__hint">
                L adresse <code><?= $this->e($path) ?></code> ne correspond a
                aucune page accessible.
            </p>
            <div class="alert alert--info" style="text-align:left">
                Deux explications possibles :
                <ul style="margin:0.5rem 0 0;padding-left:1.1rem">
                    <li>
                        le module correspondant n est pas active pour votre
                        etablissement — un administrateur peut l ouvrir depuis
                        les parametres ;
                    </li>
                    <li>
                        l adresse a change, ou provient d un lien ancien.
                    </li>
                </ul>
            </div>
        <?php elseif ($status === 403) : ?>
            <p class="auth__hint">
                Votre compte n a pas les droits necessaires pour cette page.
                Si vous pensez qu il devrait les avoir, demandez-les a
                l administration de votre etablissement.
            </p>
        <?php else : ?>
            <p class="auth__hint"><?= $this->e($message) ?></p>
        <?php endif; ?>

        <p style="margin-top:1.5rem">
            <?php if ($isLogged) : ?>
                <a class="button" href="<?= $this->e($home) ?>">Retour a l accueil</a>
            <?php else : ?>
                <a class="button" href="/login">Se connecter</a>
                <a class="button button--secondary" href="/">Site public</a>
            <?php endif; ?>
        </p>
    </div>
</main>
</body>
</html>
