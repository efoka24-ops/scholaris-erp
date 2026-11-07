<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Pickup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Sortie sécurisée du primaire (EX-PRI-33, RG-PRI-22 ; Q13 : désactivée par défaut). Seuls les niveaux listés dans
 * `primary.pickup.levels` sont concernés. Une remise n'est enregistrée que vers une personne autorisée EN VIGUEUR ce jour, désignée
 * par l'identifiant de son autorisation (registre du socle) ; une personne non autorisée donne un REFUS journalisé, signalé au
 * parent et à la direction (NOT-PRI-07, urgent).
 */
final class PickupService
{
    public const TABLE = 'pri_pickup_logs';
    public const READ = ['primary.pickup.read', 'primary.pickup.handover'];
    public const HANDOVER = ['primary.pickup.handover'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly PrimarySettings $settings,
        private readonly AuthorizedPickupRegistry $registry,
        private readonly PersonDirectory $people,
        private readonly DirectionDirectory $direction,
        private readonly Messenger $messenger,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> élèves de la classe, personnes autorisées du jour, remises et refus du jour */
    public function day(Principal $principal, string $establishmentId, string $classId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        $today = $this->clock->today();
        $enabled = $this->enabled($t, $establishmentId, $context['level_id']);
        $names = $this->classes->names($t, $context['learner_ids']);
        $authorised = [];
        foreach ($enabled ? $this->registry->validOn($t, $establishmentId, $context['learner_ids'], $today) : [] as $info) {
            $authorised[$info->learnerId][] = ['pickup_id' => $info->id, 'name' => $info->name, 'relationship' => $info->relationship, 'grant_type' => $info->grantType];
        }
        $logs = [];
        foreach ($this->store->select(self::TABLE, $t, $establishmentId, ['class_structure_id' => $classId, 'handled_on' => $today], 'handled_at', 2000) as $row) {
            $logs[(string) $row['person_id']][] = ['outcome' => (string) $row['outcome'], 'pickup_id' => $row['pickup_id'] === null ? null : (string) $row['pickup_id'], 'presented_name' => $row['presented_name'] === null ? null : (string) $row['presented_name'], 'handled_at' => (string) $row['handled_at']];
        }
        $learners = array_map(static fn (string $id): array => ['person_id' => $id, 'name' => $names[$id] ?? $id, 'authorised' => $authorised[$id] ?? [], 'events' => $logs[$id] ?? []], $context['learner_ids']);
        usort($learners, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return ['class' => ['id' => $classId, 'name' => $context['class']->name], 'date' => $today, 'enabled' => $enabled, 'learners' => $learners];
    }

    /** @return array<string, mixed> remise à une personne autorisée en vigueur */
    public function handover(Principal $principal, string $establishmentId, string $classId, string $personId, string $pickupId): array
    {
        [$classId, $t] = $this->prepare($principal, $establishmentId, $classId, $personId);
        $info = $this->registry->find($t, $establishmentId, $pickupId);
        $today = $this->clock->today();
        if ($info === null || $info->learnerId !== $personId || !$info->isValidOn($today)) {
            throw HttpException::validation(['pickup_id' => ['Cette personne n\'est pas autorisée aujourd\'hui pour cet élève : enregistrez un refus.']]);
        }

        return $this->log($principal, $establishmentId, $personId, $classId, 'handed', $pickupId, $info->name, null, 0);
    }

    /** @return array<string, mixed> refus : personne non autorisée ; parent et direction prévenus */
    public function refuse(Principal $principal, string $establishmentId, string $classId, string $personId, string $presentedName, ?string $note): array
    {
        [$classId, $t] = $this->prepare($principal, $establishmentId, $classId, $personId);
        $child = $this->people->find($t, $personId);
        $at = $this->clock->at();
        $vars = ['child_name' => $child === null ? '' : $child->fullName(), 'date' => $at->format('Y-m-d'), 'time' => $at->format('H:i'), 'details' => $presentedName, 'reference' => substr((string) new Ulid(), -8)];
        $notified = 0;
        foreach ($this->people->guarantorsOf($t, $personId) as $guardian) {
            $notified += $this->messenger->send($t, $establishmentId, $guardian->id, 'pri.not07.pickup_refused.parent', $vars, [], true)->status === DeliveryResult::BLOCKED ? 0 : 1;
        }
        foreach ($this->direction->directionPersonIds($t, $establishmentId) as $director) {
            $notified += $this->messenger->send($t, $establishmentId, $director, 'pri.not07.pickup_refused.director', $vars, [], true)->status === DeliveryResult::BLOCKED ? 0 : 1;
        }

        return $this->log($principal, $establishmentId, $personId, $classId, 'refused', null, $presentedName, $note, $notified);
    }

    /** @return array{0: string, 1: string} */
    private function prepare(Principal $principal, string $establishmentId, string $classId, string $personId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::HANDOVER);
        $t = $principal->tenantId;
        $this->access->assertWritableClass($principal, $perimeter, $classId, self::TABLE, $personId);
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        if (!in_array($personId, $context['learner_ids'], true)) {
            throw HttpException::notFound('Élève introuvable dans cette classe.');
        }
        if (!$this->enabled($t, $establishmentId, $context['level_id'])) {
            throw HttpException::conflict('La sortie sécurisée n\'est pas activée pour ce niveau (paramètre primary.pickup.levels).');
        }

        return [$classId, $t];
    }

    private function enabled(string $tenantId, string $establishmentId, ?string $levelId): bool
    {
        return $levelId !== null && in_array($levelId, $this->settings->strings($tenantId, $establishmentId, 'primary.pickup.levels'), true);
    }

    /** @return array<string, mixed> */
    private function log(Principal $principal, string $establishmentId, string $personId, string $classId, string $outcome, ?string $pickupId, ?string $presented, ?string $note, int $notified): array
    {
        $t = $principal->tenantId;
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $t, $establishmentId, [
            'id' => $id, 'person_id' => $personId, 'class_structure_id' => $classId, 'pickup_id' => $pickupId, 'outcome' => $outcome, 'presented_name' => $presented,
            'note' => $note, 'handled_by' => $principal->userId, 'handled_on' => $this->clock->today(), 'handled_at' => $now, 'notified_count' => $notified,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.pickup.' . $outcome, self::TABLE, $id, null, ['person_id' => $personId, 'pickup_id' => $pickupId]);

        return ['id' => $id, 'person_id' => $personId, 'outcome' => $outcome, 'pickup_id' => $pickupId, 'presented_name' => $presented, 'handled_at' => $now, 'notified' => $notified];
    }
}
