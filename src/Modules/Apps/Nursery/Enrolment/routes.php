<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\Enrolment\Http\ChildController;
use Scholaris\Modules\Apps\Nursery\Enrolment\Http\EnrolmentController;
use Scholaris\Modules\Apps\Nursery\Support\NurseryRoutes;

return static function (NurseryRoutes $routes): void {
    $routes->get('/children', [ChildController::class, 'index']);
    $routes->get('/children/{id}', [ChildController::class, 'show']);
    $routes->post('/enrolments', [EnrolmentController::class, 'store']);
};
