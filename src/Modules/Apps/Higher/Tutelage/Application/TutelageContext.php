<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\TutelageInfo;
use Scholaris\Modules\Apps\Higher\Tutelage\Domain\TransmissionRules;

/**
 * Filière et tutelle de l'année (registre d'Academic, RG-ORG-8) ; vue de la convention avec son échéance. Les alertes NOT-15
 * (90/30/7 jours, DG et directeur académique) sont envoyées par Academic\ExpiryAlertService ; ici, seul l'affichage.
 */
final class TutelageContext
{
    public function __construct(
        private readonly AcademicDirectory $academic,
        private readonly TutelageClock $clock,
    ) {
    }

    /** @return array{0: ProgramInfo, 1: TutelageInfo} */
    public function resolve(string $tenantId, string $establishmentId, string $programId, string $academicYearId): array
    {
        $program = $this->academic->program($tenantId, $establishmentId, $programId) ?? throw HttpException::notFound('Filière introuvable.');
        $tutelage = $this->academic->tutelageFor($tenantId, $establishmentId, $programId, $academicYearId)
            ?? throw HttpException::validation(['program_id' => ['Aucune université de tutelle enregistrée pour cette filière et cette année (RG-ORG-8).']]);

        return [$program, $tutelage];
    }

    public function program(string $tenantId, string $establishmentId, string $programId): ProgramInfo
    {
        return $this->academic->program($tenantId, $establishmentId, $programId) ?? throw HttpException::notFound('Filière introuvable.');
    }

    /** @return array<string, mixed> */
    public function convention(ProgramInfo $program, TutelageInfo $tutelage): array
    {
        $days = TransmissionRules::daysLeft($tutelage->conventionEndsOn, $this->clock->today());

        return [
            'tutelage_id' => $tutelage->id, 'program_id' => $program->id, 'program_name' => $program->name, 'academic_year_id' => $tutelage->academicYearId,
            'university_name' => $tutelage->universityName, 'convention_reference' => $tutelage->conventionReference,
            'convention_ends_on' => $tutelage->conventionEndsOn, 'approved_programme_ref' => $tutelage->approvedProgrammeRef, 'days_left' => $days,
            'expiring' => $days !== null && $days <= TutelagePermissions::EXPIRY_WARNING_DAYS, 'expired' => $days !== null && $days < 0,
        ];
    }
}
