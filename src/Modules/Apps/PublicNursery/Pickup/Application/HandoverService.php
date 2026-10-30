<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Application;

use DateTimeImmutable;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Domain\HandoverDecision;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

final class HandoverService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AuthorizedPersonsService $authorizedPersons,
        private readonly CustodyRestrictionService $restrictions,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly RoleDirectory $roles,
        private readonly SafetyMessenger $notifier,
        private readonly AuditLog $audit,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function decide(string $tenantId, string $establishmentId, string $learnerId, string $actorId, array $data): HandoverDecision
    {
        if (!in_array($learnerId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        }
        $pickupId = trim((string) ($data['pickup_id'] ?? ''));
        if ($pickupId === '') {
            throw HttpException::validation(['pickup_id' => ['Champ requis.']]);
        }
        $schoolDay = $this->date($data['school_day'] ?? null, 'school_day');
        $origin = $this->requiredText($data['origin'] ?? 'api', 10, 'origin');
        $declaredName = $this->optionalText($data['declared_name'] ?? null, 160, 'declared_name');
        $pickupPersonId = $this->optionalText($data['pickup_person_id'] ?? null, 40, 'pickup_person_id');
        $clientUuid = $this->optionalText($data['client_uuid'] ?? null, 40, 'client_uuid');

        $learner = $this->people->find($tenantId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');
        $this->establishments->find($tenantId, $establishmentId) ?? throw HttpException::notFound('Établissement introuvable.');
        $pickup = $this->authorizedPersons->find($tenantId, $establishmentId, $pickupId);
        $restriction = $this->matchingRestriction($tenantId, $establishmentId, $learnerId, $schoolDay, $pickupId, $pickupPersonId);
        $now = new DateTimeImmutable('now');
        $eventId = (string) new Ulid();

        if ($pickup === null || (string) $pickup['learner_id'] !== $learnerId) {
            $event = $this->recordEvent($tenantId, $establishmentId, $learnerId, $actorId, $eventId, $schoolDay, 'refused', $origin, $clientUuid, $declaredName, null, null, 0, 'unauthorized');
            $this->notifyRefusal($tenantId, $establishmentId, $learnerId, $learner->fullName(), $schoolDay, $now, 'Personne non autorisée.', $eventId, null);
            return HandoverDecision::refused('unauthorized', $event);
        }

        $staleAuthorization = (int) ($pickup['status'] !== 'active' || ((string) $pickup['grant_type'] === 'day' && (string) $pickup['valid_on'] !== $schoolDay));
        if ($staleAuthorization === 1) {
            $event = $this->recordEvent($tenantId, $establishmentId, $learnerId, $actorId, $eventId, $schoolDay, 'refused', $origin, $clientUuid, $declaredName ?: (string) $pickup['pickup_name'], (string) $pickupId, null, 1, 'stale_authorization');
            $this->notifyRefusal($tenantId, $establishmentId, $learnerId, $learner->fullName(), $schoolDay, $now, 'Autorisation révoquée, expirée ou hors du jour.', $eventId, null);
            return HandoverDecision::refused('stale_authorization', $event);
        }

        if ($restriction !== null) {
            $event = $this->recordEvent($tenantId, $establishmentId, $learnerId, $actorId, $eventId, $schoolDay, 'refused', $origin, $clientUuid, $declaredName ?: (string) $pickup['pickup_name'], (string) $pickupId, (string) $restriction['id'], 0, 'restricted');
            $this->notifyRefusal($tenantId, $establishmentId, $learnerId, $learner->fullName(), $schoolDay, $now, 'Personne visée par une restriction active.', $eventId, (string) $restriction['id']);
            return HandoverDecision::refused('restricted', $event);
        }

        $event = $this->recordEvent($tenantId, $establishmentId, $learnerId, $actorId, $eventId, $schoolDay, 'handed_over', $origin, $clientUuid, $declaredName ?: (string) $pickup['pickup_name'], (string) $pickupId, null, 0, 'authorized');

        return HandoverDecision::handedOver($event);
    }

    /** @return array<string, mixed>|null */
    private function matchingRestriction(string $tenantId, string $establishmentId, string $learnerId, string $schoolDay, string $pickupId, ?string $pickupPersonId): ?array
    {
        foreach ($this->restrictions->activeFor($tenantId, $establishmentId, $learnerId, $schoolDay) as $restriction) {
            if ((string) $restriction['restricted_pickup_id'] === $pickupId) {
                return $restriction;
            }
            if ($pickupPersonId !== null && $pickupPersonId !== '' && (string) $restriction['restricted_person_id'] === $pickupPersonId) {
                return $restriction;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function recordEvent(string $tenantId, string $establishmentId, string $learnerId, string $actorId, string $eventId, string $schoolDay, string $eventType, string $origin, ?string $clientUuid, ?string $declaredName, ?string $pickupId, ?string $restrictionId, int $staleAuthorization, string $reason): array
    {
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        $event = [
            'id' => $eventId,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'learner_id' => $learnerId,
            'structure_id' => null,
            'school_day' => $schoolDay,
            'event_type' => $eventType,
            'pickup_id' => $pickupId,
            'code_id' => null,
            'declared_name' => $declaredName,
            'restriction_id' => $restrictionId,
            'stale_authorization' => $staleAuthorization,
            'origin' => $origin,
            'client_uuid' => $clientUuid,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'actor_id' => $actorId,
            'remark' => $reason,
        ];
        $this->connections->pdo()->prepare(
            'INSERT INTO pn_gate_events (id, tenant_id, establishment_id, learner_id, structure_id, school_day, event_type, pickup_id, code_id, declared_name, restriction_id, stale_authorization, origin, client_uuid, occurred_at, recorded_at, actor_id, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $event['id'],
            $tenantId,
            $establishmentId,
            $learnerId,
            null,
            $schoolDay,
            $eventType,
            $pickupId,
            null,
            $declaredName,
            $restrictionId,
            $staleAuthorization,
            $origin,
            $clientUuid,
            $now,
            $now,
            $actorId,
            $reason,
        ]);
        $this->audit->append($tenantId, $actorId, 'public_nursery.handover.' . $eventType, 'pn_gate_event', $eventId, null, $event);

        return $event;
    }

    private function notifyRefusal(string $tenantId, string $establishmentId, string $learnerId, string $learnerName, string $schoolDay, DateTimeImmutable $now, string $details, string $reference, ?string $restrictionId): void
    {
        $time = $now->format('H:i');
        $guardians = $this->people->guarantorsOf($tenantId, $learnerId);
        $restrictedPersonId = null;
        if ($restrictionId !== null) {
            $row = $this->connections->pdo()->prepare('SELECT restricted_person_id FROM pn_custody_restrictions WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1');
            $row->execute([$tenantId, $establishmentId, $restrictionId]);
            $restrictedPersonId = $row->fetchColumn();
            $restrictedPersonId = $restrictedPersonId !== false ? (string) $restrictedPersonId : null;
        }
        foreach ($guardians as $guardian) {
            if ($restrictedPersonId !== null && $restrictedPersonId !== '' && $guardian->id === $restrictedPersonId) {
                continue;
            }
            $this->notifier->send($tenantId, $guardian->id, 'pn.not07.parent', [
                'child_name' => $learnerName,
                'date' => $schoolDay,
                'time' => $time,
                'details' => $details,
                'reference' => $reference,
            ]);
        }
        foreach ($this->roles->personIdsWithRole($tenantId, $establishmentId, ['directeur']) as $directorId) {
            $this->notifier->send($tenantId, $directorId, 'pn.not07.director', [
                'child_name' => $learnerName,
                'date' => $schoolDay,
                'time' => $time,
                'details' => $details,
                'reference' => $reference,
            ]);
        }
    }

    private function requiredText(mixed $value, int $max, string $field): string
    {
        if ($value === null || $value === '') {
            throw HttpException::validation([$field => ['Champ requis.']]);
        }

        return $this->boundedText($value, $max, $field);
    }

    private function optionalText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->boundedText($value, $max, $field);
    }

    private function boundedText(mixed $value, int $max, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            throw HttpException::validation([$field => [sprintf('Texte de %d caractères au plus attendu.', $max)]]);
        }

        return trim($value);
    }

    private function date(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return (new DateTimeImmutable('now'))->format('Y-m-d');
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw HttpException::validation([$field => ['Date AAAA-MM-JJ attendue.']]);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw HttpException::validation([$field => ['Date AAAA-MM-JJ attendue.']]);
        }

        return $value;
    }
}
