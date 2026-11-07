<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Gate\Application;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Fermeture exceptionnelle de l'école (C-106, NOT-14) : la direction déclare un jour de fermeture avec son motif ; chaque parent d'un
 * enfant inscrit est prévenu UNE fois par message de sécurité (modèle plateforme `pn.not14.parent` : un parent qui a refusé les SMS non
 * essentiels le reçoit quand même). Déclaration idempotente par école et par jour. La veille de non-récupération (NOT-06) ne s'applique
 * pas un jour de fermeture.
 */
final class ClosureService
{
    public const TABLE = 'pn_closure_days';
    public const TEMPLATE = 'pn.not14.parent';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly SafetyMessenger $messenger,
        private readonly AuditLog $audit,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function declare(string $tenantId, string $establishmentId, string $day, string $reason, string $actorId): array
    {
        $reason = trim($reason);
        $errors = [];
        if ($reason === '' || mb_strlen($reason) > 300) {
            $errors['reason'][] = 'Motif de la fermeture requis (300 caractères au plus).';
        }
        if ($day < $this->clock->today()) {
            $errors['date'][] = 'Une fermeture se déclare pour aujourd’hui ou un jour à venir.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $existing = $this->find($tenantId, $establishmentId, $day);
        if ($existing !== null) {
            return self::resource($existing) + ['already_declared' => true];
        }
        $id = (string) new Ulid();
        try {
            $this->connections->pdo()->prepare('INSERT INTO ' . self::TABLE . ' (id, tenant_id, establishment_id, closure_on, closure_reason, declared_by, notified_count, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$id, $tenantId, $establishmentId, $day, $reason, $actorId, $this->clock->now()]);
        } catch (PDOException) {
            return self::resource($this->find($tenantId, $establishmentId, $day) ?? []) + ['already_declared' => true];
        }
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $notified = [];
        foreach ($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId) as $learnerId) {
            foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
                if (isset($notified[$guardian->id])) {
                    continue;
                }
                $result = $this->messenger->send($tenantId, $guardian->id, self::TEMPLATE, ['establishment_name' => $establishment->name ?? '', 'date' => $day, 'details' => $reason]);
                $notified[$guardian->id] = in_array($result->status, ['sent', 'queued'], true);
            }
        }
        $count = count(array_filter($notified));
        $this->connections->pdo()->prepare('UPDATE ' . self::TABLE . ' SET notified_count = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')->execute([$count, $tenantId, $establishmentId, $id]);
        $this->audit->append($tenantId, $actorId, 'public_nursery.closure.declared', 'closure_day', $id, null, ['day' => $day, 'parents' => count($notified), 'delivered' => $count]);

        return self::resource($this->find($tenantId, $establishmentId, $day) ?? []) + ['already_declared' => false, 'parents' => count($notified)];
    }

    public function isClosed(string $tenantId, string $establishmentId, string $day): bool
    {
        return $this->find($tenantId, $establishmentId, $day) !== null;
    }

    /** @return list<array<string, mixed>> fermetures déclarées à partir d'un jour, du plus proche au plus lointain */
    public function upcoming(string $tenantId, string $establishmentId, string $from): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND closure_on >= ? ORDER BY closure_on LIMIT 200');
        $query->execute([$tenantId, $establishmentId, $from]);

        return array_values(array_map(self::resource(...), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<string, mixed>|null */
    private function find(string $tenantId, string $establishmentId, string $day): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND closure_on = ?');
        $query->execute([$tenantId, $establishmentId, $day]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function resource(array $row): array
    {
        return $row === [] ? [] : [
            'id' => (string) $row['id'], 'date' => (string) $row['closure_on'], 'reason' => (string) $row['closure_reason'],
            'notified_count' => (int) $row['notified_count'], 'declared_at' => (string) $row['created_at'],
        ];
    }
}
