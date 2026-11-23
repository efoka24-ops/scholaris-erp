<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\HigherApply\Http\PublicApplyController;

/**
 * Page publique « Candidater » de l'application Supérieur (sans compte) : seules routes publiques de l'application, volontairement
 * hors du préfixe /higher (dont toutes les routes exigent une authentification et le droit d'usage). Limites de débit de
 * /api/v1/public/ (config/api.php) en plus des limites propres à l'inscription.
 */
return static function (RouteRegistry $registry): void {
    $public = ['public' => true];
    $registry->add('GET', '/public/admissions/{slug}', [PublicApplyController::class, 'show'], $public);
    $registry->add('POST', '/public/admissions/{slug}/register', [PublicApplyController::class, 'register'], $public);
};
