<?php

declare(strict_types=1);

/**
 * Point d'entree unique de l'application.
 *
 * Seul ce dossier est expose sur le web. Le code, la configuration et les
 * gabarits vivent au-dessus de la racine web et ne sont pas accessibles par
 * URL, meme si la reecriture Apache venait a etre desactivee.
 */

use Scholaris\Application;
use Scholaris\Autoloader;
use Scholaris\Http\Request;

$root = dirname(__DIR__);

require $root.'/src/Autoloader.php';

Autoloader::register($root.'/src');

try {
    $app = Application::boot($root);
    $app->handle(Request::capture())->send();
} catch (Throwable $e) {
    // Panne avant meme que l'application ne soit assemblee (base injoignable,
    // .env absent) : aucun detail a l'ecran, tout part dans les journaux.
    error_log('[scholaris] demarrage impossible : '.$e->getMessage());

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        .'<title>Service indisponible</title></head><body>'
        .'<h1>Service momentanement indisponible</h1>'
        .'<p>L application ne parvient pas a demarrer. Reessayez dans quelques instants.</p>'
        .'</body></html>';
}
