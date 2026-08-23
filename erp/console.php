<?php

declare(strict_types=1);

/**
 * Alias de "artisan", pour ceux qui prefereront "php console.php migrate".
 *
 * L'executable reel s'appelle "artisan" parce que le shell CSHIELD de
 * l'hebergement n'autorise pas "php <fichier>" mais reconnait la commande
 * "artisan <args>". Le nom est donc dicte par l'environnement de deploiement.
 */

require __DIR__.'/artisan';
