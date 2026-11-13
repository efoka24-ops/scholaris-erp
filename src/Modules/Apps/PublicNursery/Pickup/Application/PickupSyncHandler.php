<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Domain\HandoverDecision;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScopeResolver;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Gestionnaire `sync.handlers.public_nursery.pickup` du point d'entrée commun `POST /sync/batches` (T725 ; appareil signé, aucune route
 * ni file de synchronisation propre). Éléments : `arrival`, `handover`, `refusal`, `hold`. Le droit est contrôlé ICI (porte :
 * `public_nursery.pickup.handover` ou `public_nursery.arrival.record`, dans la portée de sections de l'agent) ; l'idempotence repose
 * sur `client_uuid` (index unique du journal) : un rejeu rend `duplicate`, jamais un second événement. Une remise dont l'autorisation
 * est devenue invalide depuis le paquet du matin est ENREGISTRÉE (l'enfant est parti) marquée `stale_authorization`, avec alerte ;
 * un refus est enregistré et alerté. Un code à usage unique ne se vérifie jamais hors ligne (il est refusé ici).
 */
final class PickupSyncHandler implements BatchItemHandler
{
    public const KIND = 'public_nursery.pickup';
    private const PERMISSIONS = ['public_nursery.pickup.handover', 'public_nursery.arrival.record', 'settings.manage'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly SectionScopeResolver $scopes,
        private readonly GateService $gate,
        private readonly HandoverService $handovers,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        $principal = $context->principal;
        $establishmentId = $context->establishmentId;
        $this->guard->assertNursery($principal, $principal->tenantId, $establishmentId);
        $allowed = false;
        foreach (self::PERMISSIONS as $permission) {
            $allowed = $allowed || $this->permissions->can($principal, $permission, $establishmentId);
        }
        if (!$allowed) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', self::PERMISSIONS) . '.');
        }
        $payload = $item->payload;
        $type = (string) ($payload['type'] ?? '');
        $learner = $payload['learner_id'] ?? null;
        if (!is_string($learner) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learner) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }
        $day = array_key_exists('school_day', $payload) ? RequestContext::date($payload['school_day']) : (new \DateTimeImmutable('now'))->format('Y-m-d');
        if ($day === null) {
            throw HttpException::validation(['school_day' => ['Date au format AAAA-MM-JJ attendue.']]);
        }
        $existing = $this->gate->byClientUuid($principal->tenantId, $establishmentId, $item->clientUuid);
        if ($existing !== null) {
            return ItemResult::duplicate(['event' => $existing, 'status' => $existing['event_type']]);
        }
        $scope = $this->scopes->resolve($principal, self::PERMISSIONS, $establishmentId);
        $occurredAt = is_string($payload['occurred_at'] ?? null) && $payload['occurred_at'] !== '' ? mb_substr((string) $payload['occurred_at'], 0, 32) : (new \DateTimeImmutable('now'))->format(DATE_ATOM);

        switch ($type) {
            case 'arrival':
                return ItemResult::applied(['event' => $this->gate->record($principal->tenantId, $establishmentId, $scope, $learner, 'arrival', $day, $occurredAt, $principal->userId, null, 'offline', $item->clientUuid)]);
            case 'hold':
                $remark = $payload['remark'] ?? null;
                if (!is_string($remark) || trim($remark) === '' || mb_strlen($remark) > 500) {
                    throw HttpException::validation(['remark' => ['Le maintien à l’école exige un motif (500 caractères au plus).']]);
                }

                return ItemResult::applied(['event' => $this->gate->record($principal->tenantId, $establishmentId, $scope, $learner, 'kept_at_school', $day, $occurredAt, $principal->userId, trim($remark), 'offline', $item->clientUuid)]);
            case 'handover':
            case 'refusal':
                if (array_key_exists('code', $payload) && $payload['code'] !== null && $payload['code'] !== '') {
                    throw HttpException::validation(['code' => ['Un code à usage unique se vérifie en ligne, jamais hors ligne.']]);
                }
                $this->assertInScope($principal->tenantId, $establishmentId, $learner, $scope);
                $data = [
                    'pickup_id' => $type === 'handover' ? ($payload['pickup_id'] ?? null) : null,
                    'pickup_person_id' => $payload['pickup_person_id'] ?? null,
                    'declared_name' => $payload['declared_name'] ?? null,
                    'school_day' => $day,
                    'origin' => 'offline',
                    'client_uuid' => $item->clientUuid,
                    'occurred_at' => $occurredAt,
                ];
                if ($type === 'refusal' && ($data['declared_name'] === null || $data['declared_name'] === '')) {
                    $data['declared_name'] = '—';
                }
                $decision = $this->handovers->decide($principal->tenantId, $establishmentId, $learner, $principal->userId, $data);

                return ItemResult::applied([
                    'status' => $decision->status, 'reason' => $decision->reason, 'stale_authorization' => (int) ($decision->event['stale_authorization'] ?? 0) === 1,
                    'event' => $decision->event, 'refused' => $decision->status === HandoverDecision::REFUSED,
                ]);
            default:
                throw HttpException::validation(['type' => ['Type d’élément inconnu : arrival, handover, refusal ou hold.']]);
        }
    }

    /** Un agent ne remet ni ne refuse que les enfants de ses sections : 403, aucun événement écrit. */
    private function assertInScope(string $tenantId, string $establishmentId, string $learnerId, \Scholaris\Modules\Apps\PublicNursery\Support\SectionScope $scope): void
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                if ($enrollment->structureId !== null && !$scope->contains($enrollment->structureId)) {
                    throw HttpException::forbidden('Cet enfant est hors de votre périmètre.');
                }

                return;
            }
        }
    }
}
