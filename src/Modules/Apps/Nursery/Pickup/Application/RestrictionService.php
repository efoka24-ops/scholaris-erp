<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;

/**
 * Restrictions de garde (EX-MAT-11, RG-MAT-3) : décision de justice limitant la garde, saisie par la directrice seule. Bloque la
 * remise, reste invisible du parent et de la supervision ; chaque consultation est journalisée. La personne visée est désignée par
 * l'autorisation (`restricted_pickup_id`), par sa fiche (`restricted_person_id`) ou, en dernier recours, par son nom.
 */
final class RestrictionService
{
    public const TABLE = 'nu_custody_restrictions';
    public const NATURES = ['no_pickup', 'no_contact', 'other'];

    public function __construct(
        private readonly Store $store,
        private readonly ChildDirectory $children,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data learner_id, restricted_name, restricted_person_id?, restricted_pickup_id?, nature, decision_reference, valid_from, valid_to?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');
        if (isset($data['valid_to']) && (string) $data['valid_to'] < (string) $data['valid_from']) {
            throw HttpException::validation(['valid_to' => ['La fin de validité précède le début.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'restricted_name' => (string) $data['restricted_name'],
            'restricted_person_id' => $data['restricted_person_id'] ?? null, 'restricted_pickup_id' => $data['restricted_pickup_id'] ?? null,
            'nature' => (string) $data['nature'], 'decision_reference' => (string) $data['decision_reference'],
            'valid_from' => (string) $data['valid_from'], 'valid_to' => $data['valid_to'] ?? null, 'status' => 'active',
            'recorded_by' => $principal->userId, 'lifted_reason' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.restriction.created', 'custody_restriction', $id, null, ['learner_id' => $learnerId, 'section_id' => $section, 'nature' => $data['nature']]);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function lift(Principal $principal, Perimeter $perimeter, string $id, string $reason): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Restriction introuvable.');
        if ((string) $row['status'] !== 'active') {
            throw HttpException::conflict('Cette restriction est déjà levée.');
        }
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'lifted', 'lifted_reason' => $reason, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.restriction.lifted', 'custody_restriction', $id, ['status' => 'active'], ['status' => 'lifted', 'reason' => $reason]);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /**
     * Restrictions des enfants du périmètre ; la consultation est journalisée.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Principal $principal, Perimeter $perimeter, Paging $paging, ?string $learnerId): array
    {
        $where = $learnerId !== null ? ['learner_id' => $learnerId] : [];
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $where, 'created_at, id', 1000) as $row) {
            $section = $this->children->sectionOf($perimeter->tenantId, $perimeter->establishmentId, (string) $row['learner_id']);
            if ($perimeter->coversSection($section)) {
                $rows[] = $this->resource($row);
            }
        }
        $this->access->audit($principal, 'nursery.restriction.viewed', 'custody_restriction', $learnerId ?? $perimeter->establishmentId, null, ['count' => count($rows)]);

        return $paging->slice($rows);
    }

    /**
     * Restrictions actives ce jour-là pour l'enfant (usage interne de la remise et de la veille).
     *
     * @return list<array<string, mixed>>
     */
    public function activeFor(string $tenantId, string $establishmentId, string $learnerId, string $day): array
    {
        $active = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'status' => 'active'], 'id', 200) as $row) {
            if ((string) $row['valid_from'] <= $day && ($row['valid_to'] === null || (string) $row['valid_to'] >= $day)) {
                $active[] = $row;
            }
        }

        return $active;
    }

    /**
     * La restriction qui vise cette personne ce jour-là : par identifiant d'autorisation, par fiche, ou par nom normalisé.
     *
     * @param list<array<string, mixed>> $restrictions
     * @return array<string, mixed>|null
     */
    public static function matching(array $restrictions, ?AuthorizedPickupInfo $pickup, ?string $pickupId, ?string $personId): ?array
    {
        foreach ($restrictions as $restriction) {
            if ($pickupId !== null && (string) ($restriction['restricted_pickup_id'] ?? '') === $pickupId) {
                return $restriction;
            }
            if ($personId !== null && $personId !== '' && (string) ($restriction['restricted_person_id'] ?? '') === $personId) {
                return $restriction;
            }
            if ($pickup !== null && self::normalise($pickup->name) === self::normalise((string) $restriction['restricted_name'])) {
                return $restriction;
            }
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'restricted_name' => (string) $row['restricted_name'],
            'restricted_person_id' => $row['restricted_person_id'] !== null ? (string) $row['restricted_person_id'] : null,
            'restricted_pickup_id' => $row['restricted_pickup_id'] !== null ? (string) $row['restricted_pickup_id'] : null,
            'nature' => (string) $row['nature'], 'decision_reference' => (string) $row['decision_reference'],
            'valid_from' => (string) $row['valid_from'], 'valid_to' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
            'status' => (string) $row['status'], 'lifted_reason' => $row['lifted_reason'] !== null ? (string) $row['lifted_reason'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
