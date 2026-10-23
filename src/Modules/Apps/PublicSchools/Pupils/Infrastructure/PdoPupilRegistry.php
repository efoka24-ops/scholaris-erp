<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilDraft;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistration;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilEnrolmentService;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Élève et identifiant national (ELV) tels que les autres applications les voient : identité minimale, bornée au client. */
final class PdoPupilRegistry implements PupilRegistry
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly PersonDirectory $people,
        private readonly PupilEnrolmentService $enrolment,
    ) {
    }

    public function find(string $tenantId, string $personId): ?PupilInfo
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT r.learner_number, r.birth_date, p.birth_act_verified FROM pub_pupil_registrations r '
            . 'LEFT JOIN pub_pupil_profiles p ON p.tenant_id = r.tenant_id AND p.person_id = r.person_id WHERE r.tenant_id = ? AND r.person_id = ?',
        );
        $query->execute([$tenantId, $personId]);

        return $this->info($tenantId, $personId, $query->fetch(PDO::FETCH_ASSOC));
    }

    public function findByLearnerNumber(string $tenantId, string $learnerNumber): ?PupilInfo
    {
        $query = $this->connections->pdo()->prepare('SELECT person_id FROM pub_pupil_registrations WHERE tenant_id = ? AND learner_number = ?');
        $query->execute([$tenantId, $learnerNumber]);
        $personId = $query->fetchColumn();

        return is_string($personId) ? $this->find($tenantId, $personId) : null;
    }

    public function possibleDuplicates(string $tenantId, string $establishmentId, PupilDraft $draft): array
    {
        return $this->enrolment->duplicates($tenantId, $establishmentId, $draft);
    }

    public function register(string $tenantId, string $establishmentId, PupilDraft $draft, ?string $classId, string $actorId): PupilRegistration
    {
        return $this->enrolment->register($tenantId, $establishmentId, $draft, $classId, [], $actorId)['registration'];
    }

    private function info(string $tenantId, string $personId, mixed $row): ?PupilInfo
    {
        $person = $this->people->find($tenantId, $personId);
        if (!is_array($row) || $person === null) {
            return null;
        }

        return new PupilInfo(
            $tenantId,
            $personId,
            $row['learner_number'] !== null ? (string) $row['learner_number'] : null,
            $person->lastName,
            $person->firstName,
            $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
            (bool) ($row['birth_act_verified'] ?? false),
        );
    }
}
