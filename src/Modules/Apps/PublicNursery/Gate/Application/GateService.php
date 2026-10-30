<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Gate\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Journal de la porte de la maternelle publique (FR-M05, FR-M08, SC-1) : arrivées, maintien à l'école en connaissance de cause,
 * registre du jour (DOC-19 en données), veille de non-récupération (NOT-06 aux parents puis à la direction, une fois par niveau,
 * enfant et jour, délais `public_nursery.pickup.*` après `public_nursery.day.end_time`) et contrôle quotidien O7 (aucune remise sans
 * autorisation valide). Jamais d'exception silencieuse : chaque alerte et chaque anomalie est journalisée.
 */
final class GateService
{
    public const END_TIME = 'public_nursery.day.end_time';
    public const LATE_AFTER = 'public_nursery.pickup.late_after_minutes';
    public const DIRECTOR_AFTER = 'public_nursery.pickup.director_after_minutes';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly AuthorizedPersonsService $authorized,
        private readonly SettingsReader $settings,
        private readonly SafetyMessenger $notifier,
        private readonly RoleDirectory $roles,
        private readonly EstablishmentDirectory $establishments,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array<string, mixed> arrivée (idempotente par enfant et jour) ou maintien à l'école */
    public function record(string $tenantId, string $establishmentId, SectionScope $scope, string $learnerId, string $type, string $day, string $occurredAt, string $actorId, ?string $remark): array
    {
        $enrollment = $this->enrollment($tenantId, $establishmentId, $learnerId);
        if ($enrollment->structureId !== null && !$scope->contains($enrollment->structureId)) {
            $this->audit->append($tenantId, $actorId, 'public_nursery.scope.denied', 'learner', $learnerId, null, ['section_id' => $enrollment->structureId]);

            throw HttpException::forbidden('Cet enfant est hors de votre périmètre.');
        }
        if ($type === 'arrival') {
            foreach ($this->events($tenantId, $establishmentId, $day, $learnerId) as $event) {
                if ($event['event_type'] === 'arrival') {
                    return self::event($event);
                }
            }
        }
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare(
            'INSERT INTO pn_gate_events (id, tenant_id, establishment_id, learner_id, structure_id, school_day, event_type, pickup_id, code_id, declared_name, restriction_id, stale_authorization, origin, client_uuid, occurred_at, recorded_at, actor_id, remark)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 0, 'online', NULL, ?, ?, ?, ?)",
        )->execute([$id, $tenantId, $establishmentId, $learnerId, $enrollment->structureId, $day, $type, $occurredAt, $occurredAt, $actorId, $remark]);
        $this->audit->append($tenantId, $actorId, 'public_nursery.gate.' . $type, 'learner', $learnerId, null, ['day' => $day]);

        return self::event($this->events($tenantId, $establishmentId, $day, $learnerId, $id)[0]);
    }

    /**
     * Registre du jour : chaque enfant inscrit (dans la portée) avec arrivée, sortie (à qui), refus, maintien, alertes.
     *
     * @return array<string, mixed>
     */
    public function register(string $tenantId, string $establishmentId, SectionScope $scope, string $day): array
    {
        $events = [];
        foreach ($this->events($tenantId, $establishmentId, $day) as $event) {
            $events[(string) $event['learner_id']][] = $event;
        }
        $alerts = [];
        $statement = $this->connections->pdo()->prepare('SELECT learner_id, alert_level FROM pn_late_alerts WHERE tenant_id = ? AND establishment_id = ? AND school_day = ?');
        $statement->execute([$tenantId, $establishmentId, $day]);
        foreach (array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), 'is_array')) as $row) {
            $alerts[(string) $row['learner_id']][] = (string) $row['alert_level'];
        }
        $rows = [];
        $totals = ['children' => 0, 'arrived' => 0, 'left' => 0, 'still_at_school' => 0, 'kept' => 0, 'refusals' => 0, 'alerts' => 0];
        foreach ($this->enrolled($tenantId, $establishmentId) as $learnerId => $sectionId) {
            if ($sectionId !== null && !$scope->contains($sectionId)) {
                continue;
            }
            $row = ['learner_id' => $learnerId, 'name' => $this->people->find($tenantId, $learnerId)?->fullName() ?? '', 'section_id' => $sectionId,
                'arrived_at' => null, 'left_at' => null, 'left_to' => null, 'kept' => false, 'refusals' => 0, 'stale' => false, 'alerts' => $alerts[$learnerId] ?? []];
            foreach ($events[$learnerId] ?? [] as $event) {
                switch ((string) $event['event_type']) {
                    case 'arrival':
                        $row['arrived_at'] ??= (string) $event['occurred_at'];
                        break;
                    case 'handed_over':
                        $row['left_at'] = (string) $event['occurred_at'];
                        $row['left_to'] = (string) ($event['declared_name'] ?? '');
                        $row['stale'] = (int) $event['stale_authorization'] === 1;
                        break;
                    case 'kept_at_school':
                        $row['kept'] = true;
                        break;
                    case 'refused':
                        ++$row['refusals'];
                        break;
                }
            }
            $row['status'] = $row['left_at'] !== null ? 'left' : ($row['arrived_at'] !== null ? ($row['kept'] ? 'kept' : 'still_at_school') : 'not_arrived');
            ++$totals['children'];
            $totals['arrived'] += $row['arrived_at'] !== null ? 1 : 0;
            $totals['left'] += $row['status'] === 'left' ? 1 : 0;
            $totals['still_at_school'] += $row['status'] === 'still_at_school' ? 1 : 0;
            $totals['kept'] += $row['status'] === 'kept' ? 1 : 0;
            $totals['refusals'] += $row['refusals'];
            $totals['alerts'] += count($row['alerts']);
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return ['date' => $day, 'totals' => $totals, 'children' => $rows];
    }

    /**
     * Veille de non-récupération (NOT-06) : inactive sans heure de fin ni délais paramétrés ; idempotente (index unique).
     *
     * @return array{parent: int, director: int}
     */
    public function watch(string $tenantId, string $establishmentId, \DateTimeImmutable $now): array
    {
        $sent = ['parent' => 0, 'director' => 0];
        $end = $this->settings->value($tenantId, $establishmentId, self::END_TIME);
        $late = $this->settings->value($tenantId, $establishmentId, self::LATE_AFTER);
        $director = $this->settings->value($tenantId, $establishmentId, self::DIRECTOR_AFTER);
        if (!is_string($end) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) !== 1 || !is_int($late) || !is_int($director)) {
            return $sent;
        }
        $day = $now->format('Y-m-d');
        $endAt = new \DateTimeImmutable($day . 'T' . $end . ':00', $now->getTimezone());
        $parentAt = $endAt->modify('+' . $late . ' minutes');
        $directorAt = $parentAt->modify('+' . $director . ' minutes');
        if ($now < $parentAt) {
            return $sent;
        }
        $register = $this->register($tenantId, $establishmentId, SectionScope::wholeEstablishment(), $day);
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        foreach ($register['children'] as $child) {
            if ($child['status'] !== 'still_at_school') {
                continue;
            }
            $levels = ['parent' => $parentAt];
            if ($now >= $directorAt) {
                $levels['director'] = $directorAt;
            }
            foreach ($levels as $level => $at) {
                if (!$this->claim($tenantId, $establishmentId, (string) $child['learner_id'], $day, $level, $now)) {
                    continue;
                }
                $variables = ['child_name' => (string) $child['name'], 'time' => $at->format('H:i'), 'date' => $day, 'establishment_name' => $establishment->name ?? '', 'reference' => (string) $child['learner_id']];
                $recipients = $level === 'parent'
                    ? array_map(static fn ($g): string => $g->id, $this->people->guarantorsOf($tenantId, (string) $child['learner_id']))
                    : $this->roles->personIdsWithRole($tenantId, $establishmentId, ['directeur']);
                $delivered = 0;
                foreach ($recipients as $personId) {
                    $result = $this->notifier->send($tenantId, $personId, 'pn.not06.' . $level, $variables);
                    $delivered += in_array($result->status, ['sent', 'queued'], true) ? 1 : 0;
                }
                if ($delivered === 0) {
                    // Jamais d'alerte silencieuse : l'échec est tracé pour la direction et le contrôle quotidien.
                    $this->audit->append($tenantId, 'system', 'public_nursery.alert.undelivered', 'learner', (string) $child['learner_id'], null, ['level' => $level, 'recipients' => count($recipients)]);
                }
                ++$sent[$level];
            }
        }

        return $sent;
    }

    /**
     * Contrôle O7 : toute remise sans autorisation valide du même enfant est une anomalie (attendu : zéro).
     *
     * @return list<array<string, mixed>>
     */
    public function audit(string $tenantId, string $establishmentId, string $day): array
    {
        $anomalies = [];
        foreach ($this->events($tenantId, $establishmentId, $day) as $event) {
            if ($event['event_type'] !== 'handed_over') {
                continue;
            }
            $pickupId = $event['pickup_id'] !== null ? (string) $event['pickup_id'] : '';
            $pickup = $pickupId === '' ? null : $this->authorized->find($tenantId, $establishmentId, $pickupId);
            $reason = match (true) {
                $pickup === null => 'no_authorization',
                (string) $pickup['learner_id'] !== (string) $event['learner_id'] => 'other_child_authorization',
                (int) $event['stale_authorization'] === 1 => 'stale_authorization',
                default => null,
            };
            if ($reason !== null) {
                $anomalies[] = ['event_id' => (string) $event['id'], 'learner_id' => (string) $event['learner_id'], 'reason' => $reason, 'occurred_at' => (string) $event['occurred_at']];
                $this->audit->append($tenantId, 'system', 'public_nursery.pickup.audit_anomaly', 'gate_event', (string) $event['id'], null, ['reason' => $reason]);
            }
        }

        return $anomalies;
    }

    /** @return list<array{0: string, 1: string}> écoles qui ont un journal (cron) */
    public function establishments(): array
    {
        $rows = $this->connections->pdo()->query('SELECT DISTINCT tenant_id, establishment_id FROM pn_gate_events ORDER BY tenant_id, establishment_id');
        $out = [];
        foreach ($rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $out[] = [(string) $row['tenant_id'], (string) $row['establishment_id']];
        }

        return $out;
    }

    private function claim(string $tenantId, string $establishmentId, string $learnerId, string $day, string $level, \DateTimeImmutable $now): bool
    {
        try {
            $this->connections->pdo()->prepare('INSERT INTO pn_late_alerts (id, tenant_id, establishment_id, learner_id, school_day, alert_level, sent_at, delivery_ref, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)')
                ->execute([(string) new Ulid(), $tenantId, $establishmentId, $learnerId, $day, $level, $now->format(DATE_ATOM)]);

            return true;
        } catch (\PDOException) {
            return false; // déjà émise pour ce niveau, cet enfant et ce jour
        }
    }

    /** @return array<string, ?string> enfant => section (inscriptions vivantes de l'établissement) */
    private function enrolled(string $tenantId, string $establishmentId): array
    {
        $out = [];
        foreach ($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId) as $personId) {
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                    $out[$personId] = $enrollment->structureId;
                    break;
                }
            }
        }

        return $out;
    }

    private function enrollment(string $tenantId, string $establishmentId, string $learnerId): EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return $enrollment;
            }
        }

        throw HttpException::notFound('Enfant introuvable dans cet établissement.');
    }

    /** @return list<array<string, mixed>> */
    private function events(string $tenantId, string $establishmentId, string $day, ?string $learnerId = null, ?string $id = null): array
    {
        $sql = 'SELECT * FROM pn_gate_events WHERE tenant_id = ? AND establishment_id = ? AND school_day = ?';
        $params = [$tenantId, $establishmentId, $day];
        if ($learnerId !== null) {
            $sql .= ' AND learner_id = ?';
            $params[] = $learnerId;
        }
        if ($id !== null) {
            $sql .= ' AND id = ?';
            $params[] = $id;
        }
        $statement = $this->connections->pdo()->prepare($sql . ' ORDER BY occurred_at, id');
        $statement->execute($params);

        return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function event(array $row): array
    {
        return ['id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'event_type' => (string) $row['event_type'], 'school_day' => (string) $row['school_day'], 'occurred_at' => (string) $row['occurred_at']];
    }
}
