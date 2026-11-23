<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Infrastructure;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmissionDirectory;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmittedApplication;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\ApplicationStatus;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Contrat AdmissionDirectory sur les tables hi_applications et hi_transfer_equivalences. */
final class PdoAdmissionDirectory implements AdmissionDirectory
{
    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly AuditLog $audit,
    ) {
    }

    public function application(string $tenantId, string $establishmentId, string $applicationId): ?AdmittedApplication
    {
        $row = $this->store->find('hi_applications', $tenantId, $establishmentId, $applicationId);
        if ($row === null) {
            return null;
        }
        $capitalized = [];
        foreach ($this->store->select('hi_transfer_equivalences', $tenantId, $establishmentId, ['application_id' => $applicationId, 'granted' => 1], 'unit_code') as $equivalence) {
            $capitalized[] = (string) $equivalence['unit_code'];
        }

        return new AdmittedApplication(
            (string) $row['id'],
            (string) $row['reference'],
            (string) $row['person_id'],
            (string) $row['campaign_id'],
            (string) $row['school_id'],
            (string) $row['department_id'],
            (string) $row['program_id'],
            (string) $row['level_id'],
            (string) $row['academic_year_id'],
            (string) $row['status'],
            (string) $row['application_kind'],
            isset($row['enrolment_id']) ? (string) $row['enrolment_id'] : null,
            array_values(array_unique($capitalized)),
        );
    }

    public function markEnrolled(string $tenantId, string $establishmentId, string $applicationId, string $enrolmentId, ?string $actorId): void
    {
        $row = $this->store->find('hi_applications', $tenantId, $establishmentId, $applicationId) ?? throw HttpException::notFound('Candidature introuvable.');
        if ($row['status'] === ApplicationStatus::ENROLLED && $row['enrolment_id'] === $enrolmentId) {
            return;
        }
        if ($row['status'] !== ApplicationStatus::ADMITTED) {
            throw HttpException::conflict('Seule une candidature « admis » peut donner lieu à une inscription.');
        }
        $this->store->update('hi_applications', $tenantId, $establishmentId, [
            'status' => ApplicationStatus::ENROLLED, 'enrolment_id' => $enrolmentId, 'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Douala')))->format(DATE_ATOM),
        ], ['id' => $applicationId]);
        $this->audit->append($tenantId, $actorId, 'higher.application.enrolled', 'hi_application', $applicationId, ['status' => ApplicationStatus::ADMITTED], ['enrolment_id' => $enrolmentId]);
    }
}
