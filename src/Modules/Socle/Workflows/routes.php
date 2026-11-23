<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Workflows\Http\WorkflowController;

/**
 * Circuits de validation configurables (W-001). /workflows : établissement actif, permission `workflows.manage` (sauf /workflows/effective : forme minimale, tout le personnel).
 * /platform/** : administrateur de plateforme uniquement, sans en-tête d'établissement (réglage d'un client depuis sa fiche, ou
 * plancher posé pour tous les clients).
 */
return static function (RouteRegistry $routes): void {
    $platform = ['tenant' => false];

    $routes->add('GET', '/workflows', [WorkflowController::class, 'index']);
    $routes->add('GET', '/workflows/effective', [WorkflowController::class, 'effective']);
    $routes->add('GET', '/workflows/{application}', [WorkflowController::class, 'index']);
    $routes->add('GET', '/workflows/{application}/{circuit}', [WorkflowController::class, 'show']);
    $routes->add('PUT', '/workflows/{application}/{circuit}', [WorkflowController::class, 'update']);
    $routes->add('DELETE', '/workflows/{application}/{circuit}', [WorkflowController::class, 'reset']);
    $routes->add('GET', '/workflows/{application}/{circuit}/history', [WorkflowController::class, 'history']);

    $routes->add('GET', '/platform/clients/{tenant}/workflows', [WorkflowController::class, 'index'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}/workflows/{application}', [WorkflowController::class, 'index'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}/workflows/{application}/{circuit}', [WorkflowController::class, 'show'], $platform);
    $routes->add('PUT', '/platform/clients/{tenant}/workflows/{application}/{circuit}', [WorkflowController::class, 'update'], $platform);
    $routes->add('DELETE', '/platform/clients/{tenant}/workflows/{application}/{circuit}', [WorkflowController::class, 'reset'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}/workflows/{application}/{circuit}/history', [WorkflowController::class, 'history'], $platform);

    $routes->add('GET', '/platform/workflows', [WorkflowController::class, 'index'], $platform);
    $routes->add('GET', '/platform/workflows/{application}/{circuit}', [WorkflowController::class, 'show'], $platform);
    $routes->add('PUT', '/platform/workflows/{application}/{circuit}', [WorkflowController::class, 'update'], $platform);
    $routes->add('DELETE', '/platform/workflows/{application}/{circuit}', [WorkflowController::class, 'reset'], $platform);
    $routes->add('GET', '/platform/workflows/{application}/{circuit}/history', [WorkflowController::class, 'history'], $platform);
};
