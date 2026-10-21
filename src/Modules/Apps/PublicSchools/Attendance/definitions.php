<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AbsenceAlertService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AttendanceBatchHandler;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AttendanceRegisterService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\CallClock;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\DailyAttendanceService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\TeacherPresenceService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Console\AbsenceAlertsCommand;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\AlertController;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\AttendanceController;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Http\TeacherPresenceController;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceAlerts;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceCalls;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoTeacherPresence;

return [
    CallClock::class => autowire(),
    PdoAttendanceCalls::class => autowire(),
    PdoAttendanceAlerts::class => autowire(),
    PdoTeacherPresence::class => autowire(),
    DailyAttendanceService::class => autowire(),
    AttendanceRegisterService::class => autowire(),
    TeacherPresenceService::class => autowire(),
    AbsenceAlertService::class => autowire(),
    AttendanceController::class => autowire(),
    TeacherPresenceController::class => autowire(),
    AlertController::class => autowire(),
    AttendanceBatchHandler::class => autowire(),
    // Hors ligne (API-04) : point d'extension unique du socle Sync, un élément = l'appel d'une classe pour une demi-journée.
    'sync.handlers.public.attendance' => autowire(AttendanceBatchHandler::class),
    // Cron quotidien : artisan public:absence-alerts (NOT-03, NOT-12).
    'console.command.' . AbsenceAlertsCommand::NAME => autowire(AbsenceAlertsCommand::class),
];
