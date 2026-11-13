<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Attendance\Application\AttendanceRecorderAdapter;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoAttendanceDirectory;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoAuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoDailyPresence;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;

/** Liaisons du module Attendance : contrats publics => implémentation (les services sont résolus par autowiring). */
return [
    AttendanceRecorder::class => autowire(AttendanceRecorderAdapter::class),
    AbsenteeDirectory::class => get(PdoAttendanceDirectory::class),
    AttendanceSummaryProvider::class => get(PdoAttendanceDirectory::class),
    PdoAttendanceDirectory::class => autowire(),
    \Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals::class => get(PdoAttendanceDirectory::class),
    \Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceTotals::class => \DI\autowire(\Scholaris\Modules\Socle\Attendance\Infrastructure\PdoUnitAttendanceTotals::class),
    // Contrat IPES (D19, D20) : assiduité par élément constitutif.
    \Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceSummaryProvider::class => get(\Scholaris\Modules\Socle\Attendance\Application\UnitAssiduity::class),
    // PM1 (Maternelle publique) : personnes autorisées à récupérer un enfant, sur la table authorized_pickups.
    AuthorizedPickupRegistry::class => autowire(PdoAuthorizedPickupRegistry::class),
    // PM8 (Maternelle publique) : absents du jour par structure et demi-journée, modes slot et day_part.
    DailyPresence::class => autowire(PdoDailyPresence::class),

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.attendance' => \DI\autowire(\Scholaris\Modules\Socle\Attendance\Infrastructure\PrivacyExportSource::class),
];
