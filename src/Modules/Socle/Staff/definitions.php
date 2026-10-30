<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Staff\Application\HoursService;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStaffDirectory;

/** Liaisons du module Staff : annuaire du personnel et enregistrement des heures réalisées (appelé par Scheduling / Attendance). */
return [
    StaffDirectory::class => autowire(PdoStaffDirectory::class),
    TaughtHoursRecorder::class => get(HoursService::class),
    \Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload::class => autowire(\Scholaris\Modules\Socle\Staff\Infrastructure\PdoTeacherWorkload::class),
    // Contrats IPES (D14, D15, D17) : calcul paramétrable des vacations et écriture des affectations. Les contrôles de payabilité
    // (D16) se déclarent sous les clés `staff.vacation.payability_checks.*` (voir VacationPayabilityCheck::REGISTRY_PREFIX).
    \Scholaris\Modules\Socle\Staff\Contracts\VacationCalculator::class => get(\Scholaris\Modules\Socle\Staff\Application\VacationCalculatorService::class),
    \Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter::class => autowire(\Scholaris\Modules\Socle\Staff\Application\AssignmentWriterService::class),
    // PP19 : mutation du personnel entre établissements d'un même client.
    \Scholaris\Modules\Socle\Staff\Contracts\StaffMover::class => autowire(\Scholaris\Modules\Socle\Staff\Application\StaffMoverService::class),

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.staff' => \DI\autowire(\Scholaris\Modules\Socle\Staff\Infrastructure\PrivacyExportSource::class),
];
