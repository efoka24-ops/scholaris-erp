<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoAttendanceDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\AbsenteeDirectory;

/** Liaisons du module Attendance : contrats publics => implémentation (les services sont résolus par autowiring). */
return [
    AbsenteeDirectory::class => get(PdoAttendanceDirectory::class),
    AttendanceSummaryProvider::class => get(PdoAttendanceDirectory::class),
    PdoAttendanceDirectory::class => autowire(),
    \Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals::class => get(PdoAttendanceDirectory::class),
    // Contrat IPES (D19, D20) : assiduité par élément constitutif.
    \Scholaris\Modules\Socle\Attendance\Contracts\UnitAttendanceSummaryProvider::class => get(\Scholaris\Modules\Socle\Attendance\Application\UnitAssiduity::class),

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.attendance' => \DI\autowire(\Scholaris\Modules\Socle\Attendance\Infrastructure\PrivacyExportSource::class),
];
