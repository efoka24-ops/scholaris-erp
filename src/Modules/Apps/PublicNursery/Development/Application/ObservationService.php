<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;

/** Observations DEV : le socle enregistre la donnée telle quelle, sans calcul ni enrichissement. */
final class ObservationService
{
    private const LEVELS = ['acquired', 'in_progress', 'not_yet'];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function record(string $tenantId, string $establishmentId, array $data): array
    {
        $row = $this->validate($tenantId, $establishmentId, $data);
        $pdo = $this->connections->pdo();
        if ($row['client_uuid'] !== null) {
            $existing = $pdo->prepare('SELECT * FROM pn_observations WHERE tenant_id = ? AND client_uuid = ? LIMIT 1');
            $existing->execute([$tenantId, $row['client_uuid']]);
            $found = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($found)) {
                return self::cast($found);
            }
        }
        $id = (string) new Ulid();
        $now = self::now();
        $pdo->prepare(
            'INSERT INTO pn_observations (id, tenant_id, establishment_id, learner_id, structure_id, frame_id, domain_id, item_id, level_code, remark, observed_on, observer_id, client_uuid, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $tenantId, $establishmentId, $row['learner_id'], $row['structure_id'], $row['frame_id'], $row['domain_id'], $row['item_id'], $row['level_code'], $row['remark'], $row['observed_on'], $row['observer_id'], $row['client_uuid'], $now, $now]);

        return $this->find($tenantId, $id) ?? throw new \LogicException('Observation indisponible après écriture.');
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_observations WHERE tenant_id = ? AND id = ?');
        $statement->execute([$tenantId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @param array<string, mixed> $data
     * @return array{learner_id: string, structure_id: string, frame_id: string, domain_id: string, item_id: string, level_code: string, remark: ?string, observed_on: string, observer_id: string, client_uuid: ?string}
     */
    private function validate(string $tenantId, string $establishmentId, array $data): array
    {
        $errors = [];
        foreach (['learner_id', 'structure_id', 'frame_id', 'domain_id', 'item_id', 'observer_id', 'observed_on'] as $field) {
            if (!is_string($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                $errors[$field][] = 'Champ requis.';
            }
        }
        $level = strtolower(trim((string) ($data['level_code'] ?? '')));
        if (!in_array($level, self::LEVELS, true)) {
            $errors['level_code'][] = 'Niveau attendu : acquired, in_progress ou not_yet.';
        }
        $observedOn = (string) ($data['observed_on'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $observedOn) !== 1) {
            $errors['observed_on'][] = 'Date AAAA-MM-JJ attendue.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'learner_id' => trim((string) $data['learner_id']),
            'structure_id' => trim((string) $data['structure_id']),
            'frame_id' => trim((string) $data['frame_id']),
            'domain_id' => trim((string) $data['domain_id']),
            'item_id' => trim((string) $data['item_id']),
            'level_code' => $level,
            'remark' => isset($data['remark']) && is_string($data['remark']) && trim($data['remark']) !== '' ? trim($data['remark']) : null,
            'observed_on' => $observedOn,
            'observer_id' => trim((string) $data['observer_id']),
            'client_uuid' => isset($data['client_uuid']) && is_string($data['client_uuid']) && trim($data['client_uuid']) !== '' ? trim($data['client_uuid']) : null,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'establishment_id' => (string) $row['establishment_id'],
            'learner_id' => (string) $row['learner_id'],
            'structure_id' => (string) $row['structure_id'],
            'frame_id' => (string) $row['frame_id'],
            'domain_id' => (string) $row['domain_id'],
            'item_id' => (string) $row['item_id'],
            'level_code' => (string) $row['level_code'],
            'remark' => $row['remark'] !== null ? (string) $row['remark'] : null,
            'observed_on' => (string) $row['observed_on'],
            'observer_id' => (string) $row['observer_id'],
            'client_uuid' => $row['client_uuid'] !== null ? (string) $row['client_uuid'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
