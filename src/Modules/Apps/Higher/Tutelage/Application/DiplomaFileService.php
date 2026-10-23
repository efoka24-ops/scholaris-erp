<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\DeliberationRecords;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Tutelage\Domain\TransmissionRules;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageStore;

/**
 * Dossiers de diplôme transmis à la tutelle (EX-TUT-03, T306) : transmis SEULEMENT après la délibération finale signée (décision
 * annuelle ouvrant droit au dossier) et le quitus (solde nul), RG-TUT-2 ; suivi transmis -> en cours -> disponible -> retiré.
 */
final class DiplomaFileService
{
    public const TABLE = 'hi_diploma_files';
    public const REFUSED_TYPE = 'https://api.scholaris.cm/errors/diploma-file-not-ready';

    public function __construct(
        private readonly TutelageStore $store,
        private readonly TutelageAccess $access,
        private readonly TutelageContext $context,
        private readonly StudentDirectory $students,
        private readonly DeliberationRecords $deliberations,
        private readonly FinancialHolds $holds,
        private readonly TutelageClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function transmit(Principal $principal, string $establishmentId, string $personId, string $programId, string $academicYearId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, TutelagePermissions::INTERNAL);
        $tenantId = $principal->tenantId;
        [$program, $tutelage] = $this->context->resolve($tenantId, $establishmentId, $programId, $academicYearId);
        $enrolment = null;
        foreach ($this->students->enrolmentsOf($tenantId, $establishmentId, $personId) as $candidate) {
            if ($candidate->programId === $programId && $candidate->academicYearId === $academicYearId) {
                $enrolment = $candidate;
                break;
            }
        }
        if ($enrolment === null) {
            throw HttpException::validation(['person_id' => ['Aucune inscription de cet étudiant dans la filière pour cette année.']]);
        }
        $id = (string) new Ulid();
        $row = ['school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $enrolment->levelId];
        $this->access->assertWritable($principal, $perimeter, $row, 'diploma_file', $id);
        $decision = $this->deliberations->yearDecision($tenantId, $establishmentId, $personId, $programId, $academicYearId);
        $refusals = TransmissionRules::diplomaRefusals($decision, $this->holds->balanceXaf($tenantId, $establishmentId, $personId));
        if ($refusals !== [] || $decision === null) {
            $this->access->audit($principal, 'higher.tutelage.diploma_file_refused', 'diploma_file', $personId, null, ['reasons' => $refusals]);
            throw new HttpException(409, 'Dossier de diplôme non transmissible', implode(' ', $refusals), self::REFUSED_TYPE, ['person_id' => $refusals]);
        }
        $now = $this->clock->now();
        $values = $row + [
            'id' => $id, 'academic_year_id' => $academicYearId, 'person_id' => $personId, 'enrolment_id' => $enrolment->id, 'tutelage_id' => $tutelage->id,
            'university_name' => $tutelage->universityName, 'decision_code' => $decision['decision_code'], 'status' => TransmissionRules::FILE_TRANSMITTED,
            'transmitted_at' => $now, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $values);
        $this->access->audit($principal, 'higher.tutelage.diploma_file_transmitted', 'diploma_file', $id, null, $values);

        return $values;
    }

    /** @return array<string, mixed> */
    public function move(Principal $principal, string $establishmentId, string $id, string $status, ?string $note): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, TutelagePermissions::INTERNAL);
        $row = $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Dossier introuvable.');
        $this->access->assertReadable($perimeter, $row);
        $this->access->assertWritable($principal, $perimeter, $row, 'diploma_file', $id);
        if (!TransmissionRules::canMoveFile((string) $row['status'], $status)) {
            throw HttpException::conflict(sprintf('Passage %s -> %s impossible.', $row['status'], $status));
        }
        $now = $this->clock->now();
        $changes = ['status' => $status, 'status_note' => $note, 'status_changed_at' => $now, 'updated_at' => $now];
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'higher.tutelage.diploma_file_' . $status, 'diploma_file', $id, $row, $changes);

        return $changes + $row;
    }
}
