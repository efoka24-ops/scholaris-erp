<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Besoins particuliers (EX-MAT-19, US13) : signalement SANS diagnostic (domaine parmi une courte liste et description de 500 caractères
 * au plus, aucun champ médical libre), visible de l'éducatrice de la section, de la direction et des parents de l'enfant seulement.
 * Statuts : ouvert, orienté, clos. Les parents sont prévenus par la messagerie (NOT-MAT-10). Chaque consultation nominative est journalisée.
 */
final class NeedService
{
    public const TABLE = 'nu_need_reports';
    public const AREAS = ['language', 'vision', 'hearing', 'motor', 'behaviour', 'other'];
    public const TEMPLATE = 'nursery.not10.need';

    public function __construct(
        private readonly Store $store,
        private readonly ChildDirectory $children,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly Messenger $messenger,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data learner_id, need_area, summary
     * @return array<string, mixed>
     */
    public function report(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'structure_id' => $section, 'need_area' => (string) $data['need_area'], 'summary' => (string) $data['summary'], 'status' => 'open',
            'orientation_note' => null, 'reported_by' => $principal->userId, 'reported_at' => $now, 'parents_notified_at' => null, 'closed_at' => null,
        ]);
        $child = $this->people->find($tenantId, $learnerId);
        $school = $this->establishments->find($tenantId, $establishmentId);
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            $this->messenger->send($tenantId, $establishmentId, $guardian->id, self::TEMPLATE, [
                'child_name' => $child !== null ? $child->fullName() : '', 'date' => $this->clock->today(), 'establishment_name' => $school->name ?? '', 'reference' => $id,
            ]);
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['parents_notified_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.need.reported', 'need_report', $id, null, ['learner_id' => $learnerId, 'need_area' => $data['need_area']]);

        return $this->resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function orient(Principal $principal, Perimeter $perimeter, string $id, string $note): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if ((string) $row['status'] === 'closed') {
            throw HttpException::conflict('Ce besoin est clos.');
        }
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'oriented', 'orientation_note' => $note], ['id' => $id]);
        $this->access->audit($principal, 'nursery.need.oriented', 'need_report', $id);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function close(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if ((string) $row['status'] === 'closed') {
            throw HttpException::conflict('Ce besoin est déjà clos.');
        }
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'closed', 'closed_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.need.closed', 'need_report', $id);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Principal $principal, Perimeter $perimeter, Paging $paging, ?string $learnerId, ?string $status): array
    {
        $where = array_filter(['learner_id' => $learnerId, 'status' => $status], static fn (?string $v): bool => $v !== null);
        if ($learnerId !== null) {
            $this->children->assertInPerimeter($perimeter, $learnerId);
        }
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $where, 'reported_at DESC, id', 1000) as $row) {
            if ($perimeter->coversSection((string) $row['structure_id'])) {
                $rows[] = $this->resource($row);
            }
        }
        $this->access->audit($principal, 'nursery.need.viewed', 'need_report', $learnerId ?? $perimeter->establishmentId, null, ['count' => count($rows)]);

        return $paging->slice($rows);
    }

    /** @return list<array<string, mixed>> besoins d'un enfant, pour son parent */
    public function forParent(Principal $principal, string $establishmentId, string $learnerId): array
    {
        $this->access->audit($principal, 'nursery.need.viewed', 'need_report', $learnerId, null, ['by' => 'parent']);

        return array_map($this->resource(...), $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['learner_id' => $learnerId], 'reported_at DESC, id', 100));
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Signalement introuvable.');
        $this->access->assertWritable($principal, $perimeter, (string) $row['structure_id'], 'need_report', $id);

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'section_id' => (string) $row['structure_id'], 'need_area' => (string) $row['need_area'],
            'summary' => (string) $row['summary'], 'status' => (string) $row['status'], 'orientation_note' => $row['orientation_note'] !== null ? (string) $row['orientation_note'] : null,
            'reported_at' => (string) $row['reported_at'], 'closed_at' => $row['closed_at'],
        ];
    }
}
