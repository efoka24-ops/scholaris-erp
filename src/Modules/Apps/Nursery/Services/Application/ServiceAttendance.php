<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Services\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Présence au service (garderie, cantine, transport) : la liste du jour = les enfants abonnés ce jour-là (périmètre de sections) ;
 * la présence se note par enfant (une ligne par offre, enfant et jour, corrigeable). Un enfant non abonné ce jour est refusé.
 */
final class ServiceAttendance
{
    public const TABLE = 'nu_service_attendance';

    public function __construct(
        private readonly Store $store,
        private readonly ServiceCatalog $catalog,
        private readonly ChildDirectory $children,
        private readonly PersonDirectory $people,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> liste du jour pour une offre */
    public function roster(Perimeter $perimeter, string $planId, string $day): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $plan = $this->catalog->plan($tenantId, $establishmentId, $planId);
        $visible = $this->children->sectionsByChild($perimeter);
        $marks = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['plan_id' => $planId, 'service_day' => $day], 'id', 5000) as $row) {
            $marks[(string) $row['learner_id']] = (int) $row['present'] === 1;
        }
        $rows = [];
        foreach ($this->catalog->activeOn($tenantId, $establishmentId, $day, $planId) as $subscription) {
            $learnerId = (string) $subscription['learner_id'];
            if (!isset($visible[$learnerId])) {
                continue;
            }
            $person = $this->people->find($tenantId, $learnerId);
            $rows[] = ['learner_id' => $learnerId, 'name' => $person?->fullName(), 'section_id' => $visible[$learnerId], 'present' => $marks[$learnerId] ?? null];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return ['plan' => ServiceCatalog::planResource($plan), 'date' => $day, 'children' => $rows, 'present' => count(array_filter($rows, static fn (array $r): bool => $r['present'] === true))];
    }

    /**
     * @param list<array{learner_id: string, present: bool}> $marks
     * @return array<string, mixed>
     */
    public function record(Principal $principal, Perimeter $perimeter, string $planId, string $day, array $marks): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $subscribed = [];
        foreach ($this->catalog->activeOn($tenantId, $establishmentId, $day, $planId) as $subscription) {
            $subscribed[(string) $subscription['learner_id']] = true;
        }
        $this->catalog->plan($tenantId, $establishmentId, $planId);
        foreach ($marks as $mark) {
            if (!isset($subscribed[$mark['learner_id']])) {
                throw HttpException::validation(['marks' => ['Un enfant n’est pas abonné à ce service ce jour-là.']]);
            }
            $section = $this->children->assertInPerimeter($perimeter, $mark['learner_id']);
            $this->access->assertWritable($principal, $perimeter, $section, 'child', $mark['learner_id']);
        }
        $now = $this->clock->now();
        foreach ($marks as $mark) {
            $where = ['plan_id' => $planId, 'learner_id' => $mark['learner_id'], 'service_day' => $day];
            $values = ['present' => $mark['present'] ? 1 : 0, 'recorded_by' => $principal->userId, 'recorded_at' => $now];
            if ($this->store->update(self::TABLE, $tenantId, $establishmentId, $values, $where) === 0) {
                $this->store->insert(self::TABLE, $tenantId, $establishmentId, ['id' => (string) new Ulid()] + $where + $values);
            }
        }
        $this->access->audit($principal, 'nursery.service.attendance', 'service_plan', $planId, null, ['date' => $day, 'count' => count($marks)]);

        return $this->roster($perimeter, $planId, $day);
    }

    /** Nombre de présents notés ce jour (toutes offres), pour le tableau de bord. */
    public function presentOn(string $tenantId, string $establishmentId, string $day): int
    {
        return $this->store->count(self::TABLE, $tenantId, $establishmentId, ['service_day' => $day, 'present' => 1]);
    }
}
