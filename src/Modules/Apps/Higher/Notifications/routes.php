<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Notifications\Http\NotificationsController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/** Routes des notifications de l'IPES (préfixe /higher, WriteGate avant chaque écriture). */
return static function (HigherRoutes $routes): void {
    $routes->get('/notifications/catalog', [NotificationsController::class, 'catalog']);
    $routes->get('/notifications/log', [NotificationsController::class, 'journal']);
    $routes->post('/notifications/reminders/run', [NotificationsController::class, 'runReminders']);
};
