<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Pickup\Domain\HandoverPolicy;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;

/**
 * Remise, refus et maintien d'un enfant (EX-MAT-10, RG-MAT-2). Une remise cite l'IDENTIFIANT d'une autorisation, ou un code du jour
 * qui désigne une autorisation : il n'existe aucun champ « nom libre » qui remette l'enfant (`declared_name` est seulement journalisé).
 * Toute voie d'écriture (API en ligne, lot hors ligne, saisie de la directrice) passe par `handover()`. Un refus est un événement
 * du journal, suivi d'une alerte aux parents et à la direction par le canal essentiel : jamais une exception silencieuse.
 *
 * Hors ligne, l'enfant est déjà parti quand le lot arrive : un événement dont l'autorisation n'est plus valable (révoquée après le
 * chargement de la liste) est ENREGISTRÉ comme remise marquée `stale_authorization` et déclenche l'alerte, sans effacer le fait.
 */
final class HandoverService
{
    public function __construct(
        private readonly AuthorizedPickupRegistry $registry,
        private readonly RestrictionService $restrictions,
        private readonly PickupCodeService $codes,
        private readonly GateJournal $journal,
        private readonly AlertSender $alerts,
        private readonly ChildDirectory $children,
        private readonly Store $store,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data learner_id, pickup_id? | code?, declared_name?, pickup_person_id?, school_day?, origin?, client_uuid?, occurred_at?
     * @return array{status: string, reason: string, stale_authorization: bool, duplicate: bool, event: array<string, mixed>, alerts: array{sent: int, queued: int, blocked: int, failed: int}|null}
     */
    public function handover(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $learnerId = (string) $data['learner_id'];
        $pickupId = isset($data['pickup_id']) ? (string) $data['pickup_id'] : null;
        $code = isset($data['code']) ? (string) $data['code'] : null;
        if (($pickupId === null) === ($code === null)) {
            throw HttpException::validation(['pickup_id' => ['Désignez la personne par l’identifiant de son autorisation (pickup_id) OU par le code du jour (code), pas les deux, pas aucun.']]);
        }
        $section = $this->children->sectionOf($tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);

        $clientUuid = isset($data['client_uuid']) ? (string) $data['client_uuid'] : null;
        if ($clientUuid !== null && ($existing = $this->journal->findByClientUuid($tenantId, $establishmentId, $clientUuid)) !== null) {
            return $this->duplicate($existing);
        }
        $origin = (string) ($data['origin'] ?? 'online');
        $day = (string) ($data['school_day'] ?? $this->clock->today());
        if ($origin === 'online' && $day !== $this->clock->today()) {
            throw HttpException::validation(['school_day' => ['Une remise en ligne se fait le jour même ; un lot hors ligne porte l’origine « offline ».']]);
        }
        $occurredAt = isset($data['occurred_at']) ? (string) $data['occurred_at'] : $this->clock->now();
        $declared = isset($data['declared_name']) ? (string) $data['declared_name'] : null;

        $codeRow = null;
        if ($code !== null) {
            try {
                $codeRow = $this->codes->verify($tenantId, $establishmentId, $learnerId, $day, $code);
                $pickupId = (string) $codeRow['pickup_id'];
            } catch (CodeRefused $refused) {
                return $this->refuse($principal, $establishmentId, $learnerId, $section, $day, $refused->reason, [
                    'event_type' => $refused->reason === 'code_blocked' ? GateJournal::REFUSED : GateJournal::CODE_FAILED, 'origin' => $origin, 'client_uuid' => $clientUuid,
                    'occurred_at' => $occurredAt, 'declared_name' => $declared, 'alert' => $refused->reason === 'code_blocked',
                ]);
            }
        }

        $pickup = $this->registry->find($tenantId, $establishmentId, (string) $pickupId);
        $restriction = RestrictionService::matching($this->restrictions->activeFor($tenantId, $establishmentId, $learnerId, $day), $pickup, $pickupId, isset($data['pickup_person_id']) ? (string) $data['pickup_person_id'] : null);
        $decision = HandoverPolicy::decide($pickup, $learnerId, $day, $restriction !== null);

        $stale = false;
        if ($decision['outcome'] === HandoverPolicy::REFUSED && $origin === 'offline' && $pickup !== null && $pickup->learnerId === $learnerId) {
            // Hors ligne, l'enfant est déjà parti : le fait est enregistré tel quel, marqué périmé, et l'alerte part.
            $stale = true;
        }
        if ($decision['outcome'] === HandoverPolicy::REFUSED && !$stale) {
            return $this->refuse($principal, $establishmentId, $learnerId, $section, $day, $decision['reason'], [
                'event_type' => GateJournal::REFUSED, 'origin' => $origin, 'client_uuid' => $clientUuid, 'occurred_at' => $occurredAt, 'declared_name' => $declared ?? $pickup?->name,
                'pickup_id' => $pickup !== null && $pickup->learnerId === $learnerId ? $pickupId : null, 'restriction_id' => $restriction !== null ? (string) $restriction['id'] : null, 'alert' => true,
                'excluded_person' => $restriction !== null ? ($restriction['restricted_person_id'] !== null ? (string) $restriction['restricted_person_id'] : null) : null,
            ]);
        }

        $event = $this->journal->append($tenantId, $establishmentId, [
            'learner_id' => $learnerId, 'structure_id' => $section, 'school_day' => $day, 'event_type' => GateJournal::HANDED_OVER, 'pickup_id' => $pickupId,
            'code_id' => $codeRow !== null ? (string) $codeRow['id'] : null, 'declared_name' => $declared ?? $pickup?->name, 'restriction_id' => $restriction !== null ? (string) $restriction['id'] : null,
            'stale' => $stale ? 1 : 0, 'origin' => $origin, 'client_uuid' => $clientUuid, 'occurred_at' => $occurredAt, 'actor_id' => $principal->userId,
            'remark' => $stale ? $decision['reason'] : 'authorized',
        ]);
        if ($codeRow !== null) {
            $this->codes->markUsed($tenantId, $establishmentId, (string) $codeRow['id'], (string) $event['id']);
        }
        $this->store->update('nu_late_alerts', $tenantId, $establishmentId, ['resolved_at' => $this->clock->now()], ['learner_id' => $learnerId, 'school_day' => $day, 'resolved_at' => null]);
        $this->access->audit($principal, 'nursery.handover.handed_over', 'gate_event', (string) $event['id'], null, ['learner_id' => $learnerId, 'pickup_id' => $pickupId, 'stale' => $stale]);
        $alerts = null;
        if ($stale) {
            $alerts = $this->alertRefusal($principal, $establishmentId, $learnerId, $day, 'Remise hors ligne avec une autorisation devenue invalide (' . $decision['reason'] . ').', (string) $event['id'], $restriction !== null && $restriction['restricted_person_id'] !== null ? (string) $restriction['restricted_person_id'] : null);
        }

        return ['status' => GateJournal::HANDED_OVER, 'reason' => $stale ? 'stale_authorization' : 'authorized', 'stale_authorization' => $stale, 'duplicate' => false, 'event' => GateJournal::resource($event), 'alerts' => $alerts];
    }

    /**
     * « Personne non listée » : l'enfant est gardé, l'événement est journalisé et l'alerte part (parents et direction).
     *
     * @param array<string, mixed> $data learner_id, declared_name?, school_day?, origin?, client_uuid?, occurred_at?
     * @return array{status: string, reason: string, stale_authorization: bool, duplicate: bool, event: array<string, mixed>, alerts: array{sent: int, queued: int, blocked: int, failed: int}|null}
     */
    public function refusal(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $clientUuid = isset($data['client_uuid']) ? (string) $data['client_uuid'] : null;
        if ($clientUuid !== null && ($existing = $this->journal->findByClientUuid($perimeter->tenantId, $perimeter->establishmentId, $clientUuid)) !== null) {
            return $this->duplicate($existing);
        }

        return $this->refuse($principal, $perimeter->establishmentId, $learnerId, $section, (string) ($data['school_day'] ?? $this->clock->today()), HandoverPolicy::UNAUTHORIZED, [
            'event_type' => GateJournal::REFUSED, 'origin' => (string) ($data['origin'] ?? 'online'), 'client_uuid' => $clientUuid,
            'occurred_at' => isset($data['occurred_at']) ? (string) $data['occurred_at'] : $this->clock->now(),
            'declared_name' => isset($data['declared_name']) ? (string) $data['declared_name'] : null, 'alert' => true,
        ]);
    }

    /**
     * Maintien de l'enfant à l'école (parent injoignable, enfant resté sous la responsabilité de l'équipe) : tracé dans le journal.
     *
     * @param array<string, mixed> $data learner_id, remark, school_day?, origin?, client_uuid?
     * @return array<string, mixed>
     */
    public function hold(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $clientUuid = isset($data['client_uuid']) ? (string) $data['client_uuid'] : null;
        if ($clientUuid !== null && ($existing = $this->journal->findByClientUuid($perimeter->tenantId, $perimeter->establishmentId, $clientUuid)) !== null) {
            return GateJournal::resource($existing);
        }
        $event = $this->journal->append($perimeter->tenantId, $perimeter->establishmentId, [
            'learner_id' => $learnerId, 'structure_id' => $section, 'school_day' => (string) ($data['school_day'] ?? $this->clock->today()), 'event_type' => GateJournal::KEPT,
            'origin' => (string) ($data['origin'] ?? 'online'), 'client_uuid' => $clientUuid, 'actor_id' => $principal->userId, 'remark' => (string) $data['remark'],
        ]);
        $this->access->audit($principal, 'nursery.handover.kept_at_school', 'gate_event', (string) $event['id'], null, ['learner_id' => $learnerId]);

        return GateJournal::resource($event);
    }

    /**
     * @param array<string, mixed> $options event_type, origin, client_uuid, occurred_at, declared_name?, pickup_id?, restriction_id?, alert, excluded_person?
     * @return array{status: string, reason: string, stale_authorization: bool, duplicate: bool, event: array<string, mixed>, alerts: array{sent: int, queued: int, blocked: int, failed: int}|null}
     */
    private function refuse(Principal $principal, string $establishmentId, string $learnerId, string $section, string $day, string $reason, array $options): array
    {
        $event = $this->journal->append($principal->tenantId, $establishmentId, [
            'learner_id' => $learnerId, 'structure_id' => $section, 'school_day' => $day, 'event_type' => $options['event_type'], 'pickup_id' => $options['pickup_id'] ?? null,
            'declared_name' => $options['declared_name'] ?? null, 'restriction_id' => $options['restriction_id'] ?? null, 'origin' => $options['origin'], 'client_uuid' => $options['client_uuid'],
            'occurred_at' => $options['occurred_at'], 'actor_id' => $principal->userId, 'remark' => $reason,
        ]);
        $this->access->audit($principal, 'nursery.handover.' . $options['event_type'], 'gate_event', (string) $event['id'], null, ['learner_id' => $learnerId, 'reason' => $reason]);
        $alerts = $options['alert'] === true ? $this->alertRefusal($principal, $establishmentId, $learnerId, $day, self::details($reason), (string) $event['id'], $options['excluded_person'] ?? null) : null;

        return ['status' => GateJournal::REFUSED, 'reason' => $reason, 'stale_authorization' => false, 'duplicate' => false, 'event' => GateJournal::resource($event), 'alerts' => $alerts];
    }

    /** @return array{sent: int, queued: int, blocked: int, failed: int} */
    private function alertRefusal(Principal $principal, string $establishmentId, string $learnerId, string $day, string $details, string $reference, ?string $excludedPersonId): array
    {
        $time = (new DateTimeImmutable($this->clock->now()))->format('H:i');
        $variables = $this->alerts->variables($principal->tenantId, $establishmentId, $learnerId, $day, $time, $details, $reference);
        $parents = $this->alerts->toParents($principal, $establishmentId, $learnerId, 'nursery.not04.refused_parent', $variables, $excludedPersonId);
        $direction = $this->alerts->toDirection($principal, $establishmentId, 'nursery.not04.refused_director', $variables);

        return [
            'sent' => $parents['sent'] + $direction['sent'], 'queued' => $parents['queued'] + $direction['queued'],
            'blocked' => $parents['blocked'] + $direction['blocked'], 'failed' => $parents['failed'] + $direction['failed'],
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array{status: string, reason: string, stale_authorization: bool, duplicate: bool, event: array<string, mixed>, alerts: null}
     */
    private function duplicate(array $existing): array
    {
        return [
            'status' => (string) $existing['event_type'] === GateJournal::HANDED_OVER ? GateJournal::HANDED_OVER : GateJournal::REFUSED, 'reason' => (string) ($existing['remark'] ?? ''),
            'stale_authorization' => (int) $existing['stale_authorization'] === 1, 'duplicate' => true, 'event' => GateJournal::resource($existing), 'alerts' => null,
        ];
    }

    private static function details(string $reason): string
    {
        return match ($reason) {
            HandoverPolicy::UNAUTHORIZED => 'Personne non autorisée.',
            HandoverPolicy::AUTHORIZATION_NOT_VALID => 'Autorisation révoquée, expirée ou d’un autre jour.',
            HandoverPolicy::RESTRICTED => 'Personne visée par une restriction de garde.',
            'code_blocked' => 'Code du jour bloqué après trop d’essais erronés.',
            default => 'Remise refusée.',
        };
    }
}
