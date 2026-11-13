<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentWriter;
use Scholaris\Modules\Socle\People\Domain\EnrollmentStatus;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Réalisation d'EnrollmentWriter sur EnrollmentService (mêmes règles que POST /enrollments puis /validate). */
final class EnrollmentWriterService implements EnrollmentWriter
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly PdoStore $store,
    ) {
    }

    public function enrol(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, ?string $actorId): string
    {
        $existing = $this->store->where('enrollments', $tenantId, [
            'person_id' => $personId, 'establishment_id' => $establishmentId, 'academic_year_id' => $academicYearId, 'structure_id' => $structureId,
        ]);
        foreach ($existing as $row) {
            $status = EnrollmentStatus::from((string) $row['status']);
            if ($status === EnrollmentStatus::Draft) {
                return (string) $this->enrollments->validate($tenantId, $establishmentId, (string) $row['id'])['id'];
            }
            if ($status->isLive()) {
                return (string) $row['id'];
            }
        }
        $draft = $this->enrollments->createDraft($tenantId, $establishmentId, $personId, $structureId, $academicYearId, $actorId);
        try {
            return (string) $this->enrollments->validate($tenantId, $establishmentId, (string) $draft['id'])['id'];
        } catch (\Throwable $e) {
            // Refus (dossier incomplet, garde) : aucun brouillon laissé derrière, qui bloquerait une inscription ultérieure.
            $this->store->delete('enrollments', $tenantId, ['id' => (string) $draft['id']]);

            throw $e;
        }
    }
}
