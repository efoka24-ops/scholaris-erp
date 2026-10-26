<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Alertes d'absences répétées (spec §8, T551) : un élève absent au moins `primary.alert.absences` demi-journées CONSÉCUTIVES (jours
 * ouvrés, appels validés : contrat DailyPresence) reçoit une alerte, une seule fois par série. Exécutée chaque jour par
 * `primary:alerts` ; un client dont l'application est suspendue ou fermée est sauté. Lecture au tableau de bord, résolution motivée.
 */
final class AbsenceAlerts
{
    public const TABLE = 'pri_alerts';
    public const KIND = 'absences';
    public const READ = ['primary.dashboard.read'];
    private const PARTS = [DailyPresence::AFTERNOON, DailyPresence::MORNING];

    public function __construct(
        private readonly Store $store,
        private readonly ClassFigures $classes,
        private readonly DailyPresence $presence,
        private readonly PrimarySettings $settings,
        private readonly ApplicationAccess $applications,
        private readonly Access $access,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array{establishments: int, alerts: int, skipped_tenants: int} */
    public function run(?string $day = null): array
    {
        $day ??= $this->clock->today();
        $result = ['establishments' => 0, 'alerts' => 0, 'skipped_tenants' => 0];
        $skipped = [];
        $statement = $this->store->pdo()->query('SELECT DISTINCT tenant_id, establishment_id FROM pri_level_profiles ORDER BY tenant_id, establishment_id');
        foreach ($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tenant = (string) $row['tenant_id'];
            if (!$this->applications->allowsWrites($tenant, 'primary')) {
                $skipped[$tenant] = true;
                continue;
            }
            ++$result['establishments'];
            $result['alerts'] += $this->detect($tenant, (string) $row['establishment_id'], $day);
        }
        $result['skipped_tenants'] = count($skipped);

        return $result;
    }

    /** Détection pour un établissement : nombre d'alertes nouvelles. */
    public function detect(string $tenantId, string $establishmentId, string $day): int
    {
        $threshold = $this->settings->int($tenantId, $establishmentId, 'primary.alert.absences');
        if ($threshold <= 0) {
            return 0;
        }
        $slots = $this->slots($day, $threshold);
        $created = 0;
        foreach ($this->classes->all($tenantId, $establishmentId) as $class) {
            $absent = [];
            foreach ($slots as $i => [$date, $part]) {
                $absent[$i] = array_flip($this->presence->absentOn($tenantId, $establishmentId, $date, $class->id, $part));
            }
            foreach ($class->learnerIds as $personId) {
                $streak = 0;
                $start = null;
                foreach ($slots as $i => [$date, $part]) {
                    if (!isset($absent[$i][$personId])) {
                        break;
                    }
                    ++$streak;
                    $start = $date . ':' . $part;
                }
                if ($streak < $threshold || $start === null) {
                    continue;
                }
                if ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'alert_kind' => self::KIND, 'streak_start' => $start], 'id', 1) !== []) {
                    continue;
                }
                $now = $this->clock->now();
                $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'person_id' => $personId, 'class_structure_id' => $class->id, 'alert_kind' => self::KIND, 'streak_start' => $start,
                    'half_days' => $streak, 'detected_on' => $day, 'status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                ++$created;
            }
        }

        return $created;
    }

    /** @return list<array<string, mixed>> alertes ouvertes du périmètre (noms d'élèves compris : lecture de la direction) */
    public function open(Principal $principal, string $establishmentId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $rows = array_values(array_filter(
            $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['status' => 'open'], 'detected_on DESC, id', 500),
            static fn (array $r): bool => $perimeter->coversClass((string) $r['class_structure_id']),
        ));

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'], 'person_id' => (string) $r['person_id'], 'class_structure_id' => (string) $r['class_structure_id'],
            'kind' => (string) $r['alert_kind'], 'half_days' => (int) $r['half_days'], 'streak_start' => (string) $r['streak_start'], 'detected_on' => (string) $r['detected_on'],
        ], $rows);
    }

    /** @return array{id: string, status: string} */
    public function resolve(Principal $principal, string $establishmentId, string $id, string $note): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Alerte introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $row['class_structure_id'], self::TABLE, $id);
        if ($row['status'] !== 'open') {
            throw HttpException::conflict('Cette alerte est déjà traitée.');
        }
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, ['status' => 'resolved', 'resolved_by' => $principal->userId, 'resolved_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $this->access->audit($principal, 'primary.alert.resolved', self::TABLE, $id, ['status' => 'open'], ['status' => 'resolved', 'note' => $note]);

        return ['id' => $id, 'status' => 'resolved'];
    }

    /**
     * Demi-journées de jours ouvrés, de la plus récente à la plus ancienne (le double du seuil suffit pour trouver une série).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function slots(string $day, int $threshold): array
    {
        $slots = [];
        $date = new \DateTimeImmutable($day);
        while (count($slots) < $threshold * 2) {
            if ((int) $date->format('N') <= 5) {
                foreach (self::PARTS as $part) {
                    $slots[] = [$date->format('Y-m-d'), $part];
                }
            }
            $date = $date->modify('-1 day');
        }

        return $slots;
    }
}
