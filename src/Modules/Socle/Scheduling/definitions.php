<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Socle\Scheduling\Contracts\RoomCapacity;
use Scholaris\Modules\Socle\Scheduling\Contracts\TeacherClasses;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\PdoTeacherClasses;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\PdoTimetableDirectory;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\UnknownRoomCapacity;

/** Liaisons du module Scheduling. La capacité des salles reste inconnue tant que le référentiel des locaux n'est pas branché. */
return [
    TimetableDirectory::class => autowire(PdoTimetableDirectory::class),
    RoomCapacity::class => autowire(UnknownRoomCapacity::class),
    TeacherClasses::class => autowire(PdoTeacherClasses::class),
    // Contrats IPES (D11, D13) : écriture de créneaux et occurrences d'un élément constitutif.
    \Scholaris\Modules\Socle\Scheduling\Contracts\TimetableWriter::class => autowire(\Scholaris\Modules\Socle\Scheduling\Application\SlotWriter::class),
    \Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences::class => autowire(\Scholaris\Modules\Socle\Scheduling\Infrastructure\ResolvedCourseUnitOccurrences::class),

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.scheduling' => \DI\autowire(\Scholaris\Modules\Socle\Scheduling\Infrastructure\PrivacyExportSource::class),
];
