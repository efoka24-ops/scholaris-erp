<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\AttendanceController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    $routes->put('/classes/{id}/attendance/{date}', [AttendanceController::class, 'record']);
};
