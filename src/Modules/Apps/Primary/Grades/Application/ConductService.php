<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\ScaleProvider;
use Scholaris\Modules\Apps\Primary\Support\Store;

/**
 * Conduite de la période (EX-PRI-31) : un niveau de l'échelle `primary.conduct_scale` par élève, saisi par le maître de la classe,
 * reporté au conseil par PrimaryCouncilLines. Échelle et libellés viennent des paramètres de l'école (Q14, à confirmer).
 */
final class ConductService
{
    public const TABLE = 'pri_conduct_marks';
    public const READ = ['primary.conduct.read', 'primary.conduct.enter'];
    public const ENTER = ['primary.conduct.enter'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly ScaleProvider $scales,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(Principal $principal, string $establishmentId, string $classId, string $periodId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        $names = $this->classes->names($t, $context['learner_ids']);
        $learners = array_map(static fn (string $id): array => ['person_id' => $id, 'name' => $names[$id] ?? $id], $context['learner_ids']);
        usort($learners, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $marks = array_map(static fn (array $r): array => [
            'person_id' => (string) $r['person_id'], 'scale_code' => (string) $r['scale_code'], 'remark' => $r['remark'] === null ? null : (string) $r['remark'],
        ], $this->store->select(self::TABLE, $t, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $periodId], 'person_id', 5000));

        return ['class' => ['id' => $classId, 'name' => $context['class']->name], 'period_id' => $periodId, 'scale' => $this->scales->conduct($t, $establishmentId), 'learners' => $learners, 'marks' => $marks];
    }

    /**
     * @param list<array<string, mixed>> $entries person_id, scale_code, remark
     * @return array{saved: int}
     */
    public function save(Principal $principal, string $establishmentId, string $classId, string $periodId, array $entries): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::ENTER);
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'pri_conduct', $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        $codes = $this->scales->codes($t, $establishmentId, true);
        $learners = array_flip($context['learner_ids']);
        $errors = [];
        foreach ($entries as $i => $entry) {
            if (!isset($learners[(string) ($entry['person_id'] ?? '')])) {
                $errors["entries.$i.person_id"] = ['Cet élève n\'est pas inscrit dans cette classe.'];
            }
            if (!in_array($entry['scale_code'] ?? null, $codes, true)) {
                $errors["entries.$i.scale_code"] = ['Valeur possible : ' . implode(', ', $codes) . '.'];
            }
        }
        if ($entries === []) {
            $errors['entries'] = ['Au moins une évaluation est attendue.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->store->transaction(function () use ($t, $establishmentId, $classId, $periodId, $entries, $principal): void {
            foreach ($entries as $entry) {
                $now = $this->clock->now();
                $existing = $this->store->select(self::TABLE, $t, $establishmentId, ['person_id' => (string) $entry['person_id'], 'period_id' => $periodId], 'id', 1)[0] ?? null;
                $remark = isset($entry['remark']) ? (string) $entry['remark'] : null;
                if ($existing === null) {
                    $this->store->insert(self::TABLE, $t, $establishmentId, [
                        'id' => (string) new Ulid(), 'person_id' => (string) $entry['person_id'], 'class_structure_id' => $classId, 'period_id' => $periodId,
                        'scale_code' => (string) $entry['scale_code'], 'remark' => $remark, 'entered_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $this->store->update(self::TABLE, $t, $establishmentId, ['scale_code' => (string) $entry['scale_code'], 'remark' => $remark, 'entered_by' => $principal->userId, 'updated_at' => $now], ['id' => (string) $existing['id']]);
                }
            }
        });
        $this->access->audit($principal, 'primary.conduct.saved', 'pri_conduct', $classId . ':' . $periodId, null, ['count' => count($entries)]);

        return ['saved' => count($entries)];
    }
}
