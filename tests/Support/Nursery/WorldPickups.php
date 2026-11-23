<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;

/**
 * Doublure en mémoire du registre des personnes autorisées (mêmes règles que PdoAuthorizedPickupRegistry : l'enfant doit appartenir
 * à l'établissement, une autorisation « day » exige une date, la révocation est idempotente). Aucune recherche par nom.
 */
final class WorldPickups implements AuthorizedPickupRegistry
{
    /** @var array<string, array{tenant: string, est: string, info: AuthorizedPickupInfo}> */
    private array $rows = [];

    public function __construct(private readonly WorldEnrollments $enrollments)
    {
    }

    public function validOn(string $tenantId, string $establishmentId, array $learnerIds, string $date): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['tenant'] === $tenantId && $row['est'] === $establishmentId && in_array($row['info']->learnerId, $learnerIds, true) && $row['info']->isValidOn($date)) {
                $out[] = $row['info'];
            }
        }

        return $out;
    }

    public function find(string $tenantId, string $establishmentId, string $id): ?AuthorizedPickupInfo
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row['tenant'] === $tenantId && $row['est'] === $establishmentId ? $row['info'] : null;
    }

    public function grant(string $tenantId, string $establishmentId, string $learnerId, array $data, string $actorId): string
    {
        if (!in_array($learnerId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        }
        $type = (string) ($data['grant_type'] ?? '');
        if (!in_array($type, [AuthorizedPickupInfo::PERMANENT, AuthorizedPickupInfo::DAY], true) || trim((string) ($data['name'] ?? '')) === '') {
            throw HttpException::validation(['name' => ['Nom et type valides requis.']]);
        }
        if ($type === AuthorizedPickupInfo::DAY && !isset($data['valid_on'])) {
            throw HttpException::validation(['valid_on' => ['Date requise pour une autorisation du jour.']]);
        }
        $id = (string) new Ulid();
        $this->rows[$id] = ['tenant' => $tenantId, 'est' => $establishmentId, 'info' => new AuthorizedPickupInfo(
            $id,
            $learnerId,
            trim((string) $data['name']),
            (string) ($data['relationship'] ?? 'autre'),
            isset($data['phone']) ? (string) $data['phone'] : null,
            isset($data['photo_ref']) ? (string) $data['photo_ref'] : null,
            $type,
            $type === AuthorizedPickupInfo::DAY ? (string) $data['valid_on'] : null,
            AuthorizedPickupInfo::ACTIVE,
        )];

        return $id;
    }

    public function revoke(string $tenantId, string $establishmentId, string $id, string $actorId): void
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row['tenant'] !== $tenantId || $row['est'] !== $establishmentId) {
            throw HttpException::notFound('Autorisation introuvable.');
        }
        $i = $row['info'];
        $this->rows[$id]['info'] = new AuthorizedPickupInfo($i->id, $i->learnerId, $i->name, $i->relationship, $i->phone, $i->photoRef, $i->grantType, $i->validOn, AuthorizedPickupInfo::REVOKED);
    }

    /** @return list<AuthorizedPickupInfo> toutes les autorisations d'un enfant (révoquées comprises), pour les listes */
    public function allFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['tenant'] === $tenantId && $row['est'] === $establishmentId && $row['info']->learnerId === $learnerId) {
                $out[] = $row['info'];
            }
        }

        return $out;
    }
}
