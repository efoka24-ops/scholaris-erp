<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Infrastructure\DisciplineRepository;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Personnes autorisées à récupérer un enfant (maternelle) : autorisation permanente ou du jour. Service minimal :
 * l'application Maternelle (confirmation de remise, alerte) viendra plus tard.
 */
final class PickupService
{
    public function __construct(
        private readonly DisciplineRepository $repository,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data learner_id, pickup_name, relationship, phone, photo_document_ref, grant_type, valid_on
     * @return array<string, mixed>
     */
    public function grant(Principal $principal, string $establishmentId, array $data): array
    {
        if (!in_array($data['learner_id'], $this->enrollments->personIdsInEstablishment($principal->tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        }
        $day = $data['grant_type'] === 'day';
        if ($day && !isset($data['valid_on'])) {
            throw HttpException::validation(['valid_on' => ['Une autorisation du jour doit préciser sa date.']]);
        }
        if (!$day && isset($data['valid_on'])) {
            throw HttpException::validation(['valid_on' => ['Une autorisation permanente n\'a pas de date.']]);
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->repository->insert(DisciplineRepository::PICKUPS, [
            'id' => $id, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'learner_id' => $data['learner_id'],
            'pickup_name' => $data['pickup_name'], 'relationship' => $data['relationship'], 'phone' => $data['phone'] ?? null,
            'photo_document_ref' => $data['photo_document_ref'] ?? null, 'grant_type' => $data['grant_type'], 'valid_on' => $data['valid_on'] ?? null,
            'granted_by' => $principal->userId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->mustFind($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function revoke(Principal $principal, string $establishmentId, string $id): array
    {
        $pickup = $this->mustFind($principal->tenantId, $establishmentId, $id);
        if ($pickup['status'] === 'active') {
            $this->repository->update(DisciplineRepository::PICKUPS, $principal->tenantId, $id, ['status' => 'revoked', 'updated_at' => $this->clock->now()]);
        }

        return $this->mustFind($principal->tenantId, $establishmentId, $id);
    }

    /** Vrai si cette personne est autorisée à récupérer l'enfant à cette date (permanente, ou du jour). */
    public function isAuthorized(string $tenantId, string $establishmentId, string $learnerId, string $pickupName, string $date): bool
    {
        foreach ($this->repository->activePickups($tenantId, $establishmentId, $learnerId) as $pickup) {
            if (mb_strtolower(trim((string) $pickup['pickup_name'])) === mb_strtolower(trim($pickupName))
                && ($pickup['grant_type'] === 'permanent' || $pickup['valid_on'] === $date)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function mustFind(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->find(DisciplineRepository::PICKUPS, $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Autorisation introuvable.');
    }
}
