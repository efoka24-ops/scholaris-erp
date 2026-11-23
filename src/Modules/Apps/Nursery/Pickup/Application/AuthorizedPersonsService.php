<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Documents\Application\NurseryDocuments;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/**
 * Personnes autorisées à récupérer un enfant (EX-MAT-09) : source unique `authorized_pickups`, lue et écrite par le contrat du socle.
 * Le parent ajoute ou retire des personnes pour SES enfants ; selon `nursery.pickup.parent_entries_need_validation` (MQ-05, défaut :
 * actif tout de suite) l'ajout d'un parent attend la validation de l'école. Un retrait révoque aussitôt l'autorisation et ses codes.
 */
final class AuthorizedPersonsService
{
    public const REQUESTS = 'nu_pickup_requests';
    public const MEDIA_NAMESPACE = 'nursery';

    public function __construct(
        private readonly AuthorizedPickupRegistry $registry,
        private readonly MediaStore $media,
        private readonly ChildDirectory $children,
        private readonly Store $store,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly Access $access,
        private readonly NurseryDocuments $documents,
    ) {
    }

    /**
     * Autorisations valables ce jour-là (permanentes et du jour) pour l'enfant.
     *
     * @return list<array<string, mixed>>
     */
    public function validFor(string $tenantId, string $establishmentId, string $learnerId, string $day): array
    {
        return array_map(self::resource(...), $this->registry->validOn($tenantId, $establishmentId, [$learnerId], $day));
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function page(Perimeter $perimeter, Paging $paging, string $learnerId, string $day): array
    {
        $this->children->assertInPerimeter($perimeter, $learnerId);

        return $paging->slice($this->validFor($perimeter->tenantId, $perimeter->establishmentId, $learnerId, $day));
    }

    /**
     * Ajout par le personnel (secrétaire, directrice) : actif immédiatement.
     *
     * @param array<string, mixed> $data name, relationship, phone?, grant_type, valid_on?, photo_base64?
     * @return array<string, mixed>
     */
    public function grantByStaff(Principal $principal, Perimeter $perimeter, string $learnerId, array $data): array
    {
        $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);

        return $this->grant($principal->tenantId, $perimeter->establishmentId, $learnerId, $principal->userId, $data, $principal);
    }

    /**
     * Ajout par le parent (portail). Rend l'autorisation, ou une demande en attente de validation de l'école.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submitByParent(Principal $principal, string $establishmentId, string $learnerId, array $data): array
    {
        if ($this->settings->bool($principal->tenantId, $establishmentId, NurserySettings::PICKUP_PARENT_VALIDATION)) {
            $id = (string) new Ulid();
            $this->store->insert(self::REQUESTS, $principal->tenantId, $establishmentId, [
                'id' => $id, 'learner_id' => $learnerId, 'requested_by' => $principal->userId, 'payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'status' => 'pending', 'created_at' => $this->clock->now(),
            ]);
            $this->access->audit($principal, 'nursery.pickup.requested', 'pickup_request', $id, null, ['learner_id' => $learnerId]);

            return ['status' => 'pending', 'request_id' => $id];
        }
        $pickup = $this->grant($principal->tenantId, $establishmentId, $learnerId, $principal->userId, $data, $principal);

        return ['status' => 'active'] + $pickup;
    }

    /** @return array<string, mixed> */
    public function revoke(Principal $principal, string $establishmentId, string $pickupId, ?Perimeter $perimeter = null, ?string $ownLearnerId = null): array
    {
        $info = $this->registry->find($principal->tenantId, $establishmentId, $pickupId) ?? throw HttpException::notFound('Autorisation introuvable.');
        if ($ownLearnerId !== null && $info->learnerId !== $ownLearnerId) {
            throw HttpException::notFound('Autorisation introuvable.');
        }
        if ($perimeter !== null) {
            $section = $this->children->sectionOf($principal->tenantId, $establishmentId, $info->learnerId) ?? throw HttpException::notFound('Autorisation introuvable.');
            $this->access->assertWritable($principal, $perimeter, $section, 'authorization', $pickupId);
        }
        $this->registry->revoke($principal->tenantId, $establishmentId, $pickupId, $principal->userId);
        // La personne retirée ne peut plus se présenter avec un code du jour encore valable.
        $this->store->update('nu_pickup_codes', $principal->tenantId, $establishmentId, ['status' => 'expired'], ['pickup_id' => $pickupId, 'status' => 'issued']);
        // La carte DOC-MAT-03 tombe avec l'autorisation : sa vérification publique indique « révoquée ».
        $this->documents->revokeFor($principal->tenantId, $establishmentId, NurseryDocuments::PICKUP_CARD, $pickupId, 'Autorisation retirée.', $principal->userId);
        $this->access->audit($principal, 'nursery.pickup.revoked', 'authorization', $pickupId, ['learner_id' => $info->learnerId], null);

        return self::resource($this->registry->find($principal->tenantId, $establishmentId, $pickupId) ?? $info);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function pendingRequests(Perimeter $perimeter, Paging $paging): array
    {
        $rows = [];
        foreach ($this->store->select(self::REQUESTS, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'pending'], 'created_at, id', 500) as $row) {
            $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, (string) $row['learner_id']);
            if ($perimeter->coversSection($section)) {
                $rows[] = $this->requestResource($row);
            }
        }

        return $paging->slice($rows);
    }

    /** @return array<string, mixed> */
    public function decide(Principal $principal, Perimeter $perimeter, string $requestId, bool $approve): array
    {
        $row = $this->store->find(self::REQUESTS, $perimeter->tenantId, $perimeter->establishmentId, $requestId) ?? throw HttpException::notFound('Demande introuvable.');
        $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, (string) $row['learner_id']) ?? throw HttpException::notFound('Demande introuvable.');
        $this->access->assertWritable($principal, $perimeter, $section, 'pickup_request', $requestId);
        if ((string) $row['status'] !== 'pending') {
            throw HttpException::conflict('Cette demande est déjà traitée.');
        }
        $pickupId = null;
        if ($approve) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row['payload'], true, 16, JSON_THROW_ON_ERROR);
            $pickupId = (string) $this->grant($perimeter->tenantId, $perimeter->establishmentId, (string) $row['learner_id'], (string) $row['requested_by'], $payload, $principal)['id'];
        }
        $this->store->update(self::REQUESTS, $perimeter->tenantId, $perimeter->establishmentId, [
            'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $principal->userId, 'decided_at' => $this->clock->now(), 'pickup_id' => $pickupId,
        ], ['id' => $requestId]);
        $this->access->audit($principal, $approve ? 'nursery.pickup.request_approved' : 'nursery.pickup.request_rejected', 'pickup_request', $requestId);

        return $this->requestResource($this->store->findOrFail(self::REQUESTS, $perimeter->tenantId, $perimeter->establishmentId, $requestId));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function grant(string $tenantId, string $establishmentId, string $learnerId, string $actorId, array $data, Principal $audited): array
    {
        $payload = [
            'name' => $data['name'] ?? null, 'relationship' => $data['relationship'] ?? null, 'phone' => $data['phone'] ?? null,
            'grant_type' => $data['grant_type'] ?? null, 'valid_on' => $data['valid_on'] ?? null,
        ];
        if (isset($data['photo_base64'])) {
            $content = base64_decode((string) $data['photo_base64'], true);
            if ($content === false || $content === '') {
                throw HttpException::validation(['photo_base64' => ['Photo illisible (base64 attendu).']]);
            }
            try {
                $payload['photo_ref'] = (string) $this->media->put($tenantId, self::MEDIA_NAMESPACE, substr(preg_replace('/[^A-Za-z0-9_-]/', '', $learnerId) ?? '', 0, 26), $content);
            } catch (\InvalidArgumentException $e) {
                throw HttpException::validation(['photo_base64' => [$e->getMessage()]]);
            }
        }
        $id = $this->registry->grant($tenantId, $establishmentId, $learnerId, $payload, $actorId);
        $this->access->audit($audited, 'nursery.pickup.granted', 'authorization', $id, null, ['learner_id' => $learnerId, 'grant_type' => $payload['grant_type']]);

        return self::resource($this->registry->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Autorisation introuvable après création.'));
    }

    /** @return array<string, mixed> */
    public static function resource(AuthorizedPickupInfo $info): array
    {
        return [
            'id' => $info->id, 'learner_id' => $info->learnerId, 'name' => $info->name, 'relationship' => $info->relationship,
            'phone' => $info->phone, 'has_photo' => $info->photoRef !== null, 'grant_type' => $info->grantType, 'valid_on' => $info->validOn, 'status' => $info->status,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function requestResource(array $row): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload'], true, 16, JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'status' => (string) $row['status'],
            'name' => $payload['name'] ?? null, 'relationship' => $payload['relationship'] ?? null, 'phone' => $payload['phone'] ?? null,
            'grant_type' => $payload['grant_type'] ?? null, 'valid_on' => $payload['valid_on'] ?? null, 'created_at' => (string) $row['created_at'],
        ];
    }
}
