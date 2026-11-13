<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Scheduling\Http\CalendarController;
use Scholaris\Modules\Socle\Scheduling\Http\ChangeController;
use Scholaris\Modules\Socle\Scheduling\Http\SlotController;
use Scholaris\Modules\Socle\Scheduling\Http\TimetableController;

/**
 * Routes du service S07 Calendrier et emplois du temps. Toutes exigent un établissement actif (X-Establishment-Id).
 */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/calendar-events', [CalendarController::class, 'index']);
    $routes->add('GET', '/calendar-events/export', [CalendarController::class, 'export']);
    $routes->add('POST', '/calendar-events', [CalendarController::class, 'store']);
    $routes->add('GET', '/calendar-events/{id}', [CalendarController::class, 'show']);
    $routes->add('PATCH', '/calendar-events/{id}', [CalendarController::class, 'update']);
    $routes->add('DELETE', '/calendar-events/{id}', [CalendarController::class, 'destroy']);

    $routes->add('GET', '/timetable-slots', [SlotController::class, 'index']);
    $routes->add('POST', '/timetable-slots', [SlotController::class, 'store']);
    $routes->add('POST', '/timetable-slots/check', [SlotController::class, 'check']);
    $routes->add('GET', '/timetable-slots/export', [SlotController::class, 'export']);
    $routes->add('GET', '/timetable-slots/{id}', [SlotController::class, 'show']);
    $routes->add('PATCH', '/timetable-slots/{id}', [SlotController::class, 'update']);
    $routes->add('DELETE', '/timetable-slots/{id}', [SlotController::class, 'destroy']);

    $routes->add('GET', '/timetable-changes', [ChangeController::class, 'index']);
    $routes->add('GET', '/timetable-changes/export', [ChangeController::class, 'export']);
    $routes->add('POST', '/timetable-changes', [ChangeController::class, 'store']);
    $routes->add('GET', '/timetable-changes/{id}', [ChangeController::class, 'show']);
    $routes->add('DELETE', '/timetable-changes/{id}', [ChangeController::class, 'destroy']);

    $routes->add('GET', '/timetable', [TimetableController::class, 'week']);
    $routes->add('GET', '/timetable/consumption', [TimetableController::class, 'consumption']);
};
