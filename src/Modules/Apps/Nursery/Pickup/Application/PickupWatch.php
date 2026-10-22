<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use DateTimeImmutable;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Veille de non-récupération (EX-MAT-12, RG-MAT-4 de la spec) : un enfant PRÉSENT (arrivée enregistrée à l'appel) et non sorti passé
 * l'heure de fin de l'école reçoit une alerte aux parents puis, plus tard, à la direction. Les trois durées viennent de /settings
 * (`day_end_time`, `late_after_minutes`, `director_after_minutes`) ; sans heure de fin saisie par l'école, ou sans délai, le niveau
 * concerné reste inactif. Une alerte par niveau, enfant et jour (index unique) : la commande peut tourner chaque minute. Un jour
 * sans appel (fermeture) n'a aucune arrivée, donc aucune alerte ; un enfant absent n'a pas d'arrivée non plus.
 */
final class PickupWatch
{
    public const PARENT = 'parent';
    public const DIRECTOR = 'director';
    public const TABLE = 'nu_late_alerts';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Store $store,
        private readonly GateJournal $journal,
        private readonly NurserySettings $settings,
        private readonly AlertSender $alerts,
        private readonly ApplicationAccess $applications,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /** @return list<array{0: string, 1: string}> couples (client, établissement) qui ont des sections */
    public function establishments(): array
    {
        $rows = $this->connections->pdo()->query('SELECT DISTINCT tenant_id, establishment_id FROM nu_sections ORDER BY tenant_id, establishment_id');
        $out = [];
        foreach ($rows !== false ? $rows->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $out[] = [(string) $row['tenant_id'], (string) $row['establishment_id']];
        }

        return $out;
    }

    /**
     * @return array{parent: int, director: int} alertes émises par cette passe
     */
    public function run(string $tenantId, string $establishmentId): array
    {
        $emitted = [self::PARENT => 0, self::DIRECTOR => 0];
        if (!$this->applications->allowsWrites($tenantId, 'nursery')) {
            return $emitted;
        }
        $end = $this->settings->time($tenantId, $establishmentId, NurserySettings::PICKUP_DAY_END);
        if ($end === null) {
            return $emitted;
        }
        $now = $this->clock->at();
        $day = $now->format('Y-m-d');
        $endAt = new DateTimeImmutable($day . ' ' . $end . ':00', $now->getTimezone());
        $lateAfter = $this->settings->int($tenantId, $establishmentId, NurserySettings::PICKUP_LATE_AFTER);
        $directorAfter = $this->settings->int($tenantId, $establishmentId, NurserySettings::PICKUP_DIRECTOR_AFTER);
        $levels = [];
        if ($lateAfter !== null) {
            $levels[self::PARENT] = $endAt->modify('+' . $lateAfter . ' minutes');
            if ($directorAfter !== null) {
                $levels[self::DIRECTOR] = $levels[self::PARENT]->modify('+' . $directorAfter . ' minutes');
            }
        }
        $settled = $this->journal->settledLearnerIds($tenantId, $establishmentId, $day);
        $actor = new Principal('system', $tenantId);
        foreach (array_diff($this->journal->arrivedLearnerIds($tenantId, $establishmentId, $day), $settled) as $learnerId) {
            foreach ($levels as $level => $threshold) {
                if ($now < $threshold || !$this->claim($tenantId, $establishmentId, $learnerId, $day, $level)) {
                    continue;
                }
                $variables = $this->alerts->variables($tenantId, $establishmentId, $learnerId, $day, $now->format('H:i'), 'Enfant encore à l’école après l’heure de fin.', $learnerId . '-' . $day);
                $summary = $level === self::PARENT
                    ? $this->alerts->toParents($actor, $establishmentId, $learnerId, 'nursery.not02.parent_late', $variables)
                    : $this->alerts->toDirection($actor, $establishmentId, 'nursery.not03.director_late', $variables);
                $this->access->audit($actor, 'nursery.late_alert.' . $level, 'child', $learnerId, null, ['day' => $day] + $summary);
                ++$emitted[$level];
            }
        }

        return $emitted;
    }

    /** Pose l'alerte du niveau pour l'enfant et le jour : faux si elle existe déjà (idempotence). */
    private function claim(string $tenantId, string $establishmentId, string $learnerId, string $day, string $level): bool
    {
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'school_day' => $day, 'alert_level' => $level]) > 0) {
            return false;
        }
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'learner_id' => $learnerId, 'school_day' => $day, 'alert_level' => $level, 'sent_at' => $this->clock->now(), 'resolved_at' => null,
        ]);

        return true;
    }
}
