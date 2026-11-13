<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;

/** Besoins particuliers : aucune donnée médicale, seulement un besoin fonctionnel et une orientation. */
final class NeedReportService
{
    private const AREAS = ['language', 'vision', 'hearing', 'other'];
    private const STATUSES = ['open', 'oriented', 'closed'];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param ?int $limit nombre de lignes à lire (null : toutes), à partir de $offset
     * @return list<array<string, mixed>>
     */
    public function all(string $tenantId, string $establishmentId, ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM pn_need_reports WHERE tenant_id = ? AND establishment_id = ? ORDER BY reported_at DESC, id DESC';
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));
        }
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute([$tenantId, $establishmentId]);

        return array_values(array_map(self::cast(...), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, string $actorId, array $data): array
    {
        $row = $this->validate($data);
        $id = (string) new Ulid();
        $now = self::now();
        $this->connections->pdo()->prepare(
            'INSERT INTO pn_need_reports (id, tenant_id, establishment_id, learner_id, need_area, summary, status, orientation_note, reported_by, reported_at, parents_notified_at, closed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)',
        )->execute([$id, $tenantId, $establishmentId, $row['learner_id'], $row['need_area'], $row['summary'], $row['status'], $row['orientation_note'], $actorId, $now]);

        return $this->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Besoin indisponible après écriture.');
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_need_reports WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @param array<string, mixed> $data
     * @return array{learner_id: string, need_area: string, summary: string, status: string, orientation_note: ?string}
     */
    private function validate(array $data): array
    {
        $errors = [];
        if (!is_string($data['learner_id'] ?? null) || trim((string) $data['learner_id']) === '') {
            $errors['learner_id'][] = 'Élève requis.';
        }
        $area = strtolower(trim((string) ($data['need_area'] ?? '')));
        if (!in_array($area, self::AREAS, true)) {
            $errors['need_area'][] = 'Besoin attendu : language, vision, hearing ou other.';
        }
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '' || mb_strlen($summary) > 500) {
            $errors['summary'][] = 'Résumé requis (500 caractères max).';
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'open')));
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'][] = 'Statut attendu : open, oriented ou closed.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'learner_id' => trim((string) $data['learner_id']),
            'need_area' => $area,
            'summary' => $summary,
            'status' => $status,
            'orientation_note' => isset($data['orientation_note']) && is_string($data['orientation_note']) && trim($data['orientation_note']) !== '' ? trim($data['orientation_note']) : null,
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
            'need_area' => (string) $row['need_area'],
            'summary' => (string) $row['summary'],
            'status' => (string) $row['status'],
            'orientation_note' => $row['orientation_note'] !== null ? (string) $row['orientation_note'] : null,
            'reported_by' => (string) $row['reported_by'],
            'reported_at' => (string) $row['reported_at'],
            'parents_notified_at' => $row['parents_notified_at'] !== null ? (string) $row['parents_notified_at'] : null,
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
