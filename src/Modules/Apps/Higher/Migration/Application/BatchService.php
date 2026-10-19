<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Migration\Application\Processors\BalancesProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\Processors\CreditsProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\Processors\MarksProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\Processors\StaffProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\Processors\StudentsProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;
use Scholaris\Modules\Apps\Higher\Migration\Infrastructure\TableReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;

/**
 * Lots de reprise de données (lot I4). Cycle : SIMULATION (rapport ligne par ligne, rien d'écrit) puis APPLICATION (une transaction,
 * refusée s'il reste une erreur, double authentification récente) puis, au besoin, ANNULATION (tant qu'aucune donnée dépendante
 * n'existe). Idempotent : les clés de reprise des lots appliqués non annulés sont des doublons ignorés. Tout est journalisé.
 */
final class BatchService
{
    public const PERMISSION = 'higher.migration.manage';
    public const BATCHES = 'hi_import_batches';
    public const ITEMS = 'hi_import_items';
    public const SECOND_FACTOR_TYPE = 'https://api.scholaris.cm/errors/second-factor-required';
    public const SECOND_FACTOR_SECONDS = 900;
    public const SIMULATED = 'simulated';
    public const APPLIED = 'applied';
    public const REVERTED = 'reverted';
    private const LINES_LIMIT = 500;

    /** @var array<string, KindProcessor> */
    private array $processors = [];

    public function __construct(
        private readonly Store $store,
        private readonly ScopedBySchool $scoped,
        private readonly AuditLog $audit,
        private readonly TableReader $reader,
        private readonly RecentSecondFactor $secondFactor,
        private readonly MigrationClock $clock,
        StudentsProcessor $students,
        CreditsProcessor $credits,
        BalancesProcessor $balances,
        StaffProcessor $staff,
        MarksProcessor $marks,
    ) {
        foreach ([$students, $credits, $balances, $staff, $marks] as $processor) {
            $this->processors[$processor->kind()] = $processor;
        }
    }

    /** Périmètre de la permission `higher.migration.manage` ; 403 s'il est vide. */
    public function perimeter(Principal $principal, string $establishmentId): Perimeter
    {
        $perimeter = $this->scoped->perimeter($principal, self::PERMISSION, $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . self::PERMISSION . '.');
        }

        return $perimeter;
    }

    /**
     * @param array{kind: string, academic_year_id: string, format: string, content: string, file_name: ?string} $input
     * @return array<string, mixed>
     */
    public function simulate(Principal $principal, string $establishmentId, array $input): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId);
        $kind = $input['kind'];
        $processor = $this->processors[$kind] ?? throw HttpException::validation(['kind' => ['Jeu inconnu : ' . implode(', ', ImportKind::all()) . '.']]);
        $table = $this->reader->read($kind, $input['format'], $input['content']);
        $batchId = (string) new Ulid();
        $context = $this->context($principal, $establishmentId, $input['academic_year_id'], $batchId, $perimeter, $kind);
        $verdicts = [];
        foreach ($table['rows'] as $line => $row) {
            $verdicts[] = $processor->check($context, $line, $row);
        }
        $counts = self::count($verdicts);
        $now = $this->clock->now();
        $this->store->transaction(function () use ($principal, $establishmentId, $input, $kind, $batchId, $table, $verdicts, $counts, $now): void {
            $this->store->insert(self::BATCHES, $principal->tenantId, $establishmentId, [
                'id' => $batchId, 'import_kind' => $kind, 'academic_year_id' => $input['academic_year_id'], 'source_format' => $input['format'],
                'file_name' => $input['file_name'] === null ? null : mb_substr($input['file_name'], 0, 160), 'file_sha256' => hash('sha256', $input['content']),
                'batch_status' => self::SIMULATED, 'rows_total' => $counts['total'], 'rows_ok' => $counts['ok'], 'rows_error' => $counts['error'],
                'rows_warning' => $counts['warning'], 'rows_duplicate' => $counts['duplicate'], 'rows_applied' => 0,
                'payload' => (string) json_encode(['rows' => $table['rows'], 'unknown' => $table['unknown']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_by' => $principal->userId, 'created_at' => $now,
            ]);
            foreach ($verdicts as $verdict) {
                $this->store->insert(self::ITEMS, $principal->tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'batch_id' => $batchId, 'import_kind' => $kind, 'academic_year_id' => $input['academic_year_id'],
                    'line_no' => $verdict->line, 'item_status' => $verdict->status(), 'key_ref' => mb_substr($verdict->key, 0, 160), 'person_id' => $verdict->personId,
                    'messages_json' => (string) json_encode(['errors' => $verdict->errors, 'warnings' => $verdict->warnings], JSON_UNESCAPED_UNICODE), 'object_ref' => null, 'live' => 0,
                ]);
            }
            $this->audit->append($principal->tenantId, $principal->userId, 'higher.migration.simulated', 'hi_import_batch', $batchId, null, ['kind' => $kind, 'counts' => $counts, 'academic_year_id' => $input['academic_year_id']]);
        });

        return $this->report($principal->tenantId, $establishmentId, $batchId, null);
    }

    /** @return array<string, mixed> */
    public function apply(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId);
        $batch = $this->batch($principal->tenantId, $establishmentId, $id);
        $this->assertRecentSecondFactor($principal);
        if ($batch['batch_status'] !== self::SIMULATED) {
            throw HttpException::conflict('Ce lot est déjà ' . ($batch['batch_status'] === self::APPLIED ? 'appliqué' : 'annulé') . ' : refaites une simulation.');
        }
        if ((int) $batch['rows_error'] > 0) {
            throw HttpException::conflict(sprintf('La simulation contient %d erreur(s) : corrigez le fichier puis refaites la simulation. Rien n\'est appliqué tant qu\'il reste une erreur.', (int) $batch['rows_error']));
        }
        $kind = (string) $batch['import_kind'];
        $processor = $this->processors[$kind] ?? throw HttpException::conflict('Jeu inconnu.');
        $context = $this->context($principal, $establishmentId, (string) $batch['academic_year_id'], $id, $perimeter, $kind);
        $payload = json_decode((string) $batch['payload'], true);
        /** @var array<int, array<string, string>> $rows */
        $rows = is_array($payload) && is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $verdicts = [];
        foreach ($rows as $line => $row) {
            $verdicts[] = $processor->check($context, (int) $line, $row);
        }
        $counts = self::count($verdicts);
        if ($counts['error'] > 0) {
            throw new HttpException(409, 'Simulation périmée', sprintf('Depuis la simulation, %d ligne(s) sont devenues invalides : refaites la simulation. Rien n\'a été écrit.', $counts['error']), 'about:blank', ['lines' => array_slice(array_map(
                static fn (RowVerdict $v): string => 'Ligne ' . $v->line . ' : ' . implode(' ', $v->errors),
                array_values(array_filter($verdicts, static fn (RowVerdict $v): bool => $v->errors !== [])),
            ), 0, 20)]);
        }
        $applicable = array_values(array_filter($verdicts, static fn (RowVerdict $v): bool => $v->applicable()));
        $this->store->transaction(function () use ($principal, $establishmentId, $id, $processor, $context, $applicable, $verdicts, $counts, $kind): void {
            $refs = $processor->apply($context, $applicable);
            $now = $this->clock->now();
            foreach ($verdicts as $verdict) {
                $applied = isset($refs[$verdict->line]);
                $ref = $refs[$verdict->line] ?? null;
                $person = is_string($ref['person_id'] ?? null) ? $ref['person_id'] : $verdict->personId;
                $this->store->update(self::ITEMS, $principal->tenantId, $establishmentId, [
                    'item_status' => $applied ? 'applied' : $verdict->status(), 'person_id' => $person,
                    'object_ref' => $ref === null ? null : (string) json_encode($ref, JSON_UNESCAPED_UNICODE), 'live' => $applied ? 1 : 0,
                ], ['batch_id' => $id, 'line_no' => $verdict->line]);
            }
            $this->store->update(self::BATCHES, $principal->tenantId, $establishmentId, [
                'batch_status' => self::APPLIED, 'rows_applied' => count($refs), 'rows_duplicate' => $counts['duplicate'], 'applied_by' => $principal->userId, 'applied_at' => $now,
            ], ['id' => $id]);
            $this->audit->append($principal->tenantId, $principal->userId, 'higher.migration.applied', 'hi_import_batch', $id, null, ['kind' => $kind, 'applied' => count($refs), 'skipped_duplicates' => $counts['duplicate']]);
        });

        return $this->report($principal->tenantId, $establishmentId, $id, null);
    }

    /** @return array<string, mixed> */
    public function revert(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId);
        $batch = $this->batch($principal->tenantId, $establishmentId, $id);
        $this->assertRecentSecondFactor($principal);
        if ($batch['batch_status'] !== self::APPLIED) {
            throw HttpException::conflict('Seul un lot appliqué peut être annulé.');
        }
        $kind = (string) $batch['import_kind'];
        $processor = $this->processors[$kind] ?? throw HttpException::conflict('Jeu inconnu.');
        $refusal = $processor->revertRefusal();
        if ($refusal !== null) {
            throw HttpException::conflict($refusal);
        }
        $items = $this->store->select(self::ITEMS, $principal->tenantId, $establishmentId, ['batch_id' => $id, 'live' => 1], 'line_no DESC', 6000);
        if ($kind === ImportKind::STUDENTS) {
            $this->assertNoDependentBatch($principal->tenantId, $establishmentId, $id, $items);
        }
        $context = $this->context($principal, $establishmentId, (string) $batch['academic_year_id'], $id, $perimeter, $kind);
        $this->store->transaction(function () use ($principal, $establishmentId, $id, $processor, $context, $items, $reason, $kind): void {
            foreach ($items as $item) {
                $refs = json_decode((string) $item['object_ref'], true);
                $processor->revert($context, is_array($refs) ? $refs : [], $reason);
                $this->store->update(self::ITEMS, $principal->tenantId, $establishmentId, ['live' => 0, 'item_status' => 'reverted'], ['id' => $item['id']]);
            }
            $this->store->update(self::BATCHES, $principal->tenantId, $establishmentId, [
                'batch_status' => self::REVERTED, 'reverted_by' => $principal->userId, 'reverted_at' => $this->clock->now(), 'revert_reason' => mb_substr($reason, 0, 500),
            ], ['id' => $id]);
            $this->audit->append($principal->tenantId, $principal->userId, 'higher.migration.reverted', 'hi_import_batch', $id, ['status' => self::APPLIED], ['kind' => $kind, 'reason' => $reason, 'items' => count($items)]);
        });

        return $this->report($principal->tenantId, $establishmentId, $id, null);
    }

    /** @return list<array<string, mixed>> */
    public function index(Principal $principal, string $establishmentId, ?string $kind): array
    {
        $this->perimeter($principal, $establishmentId);
        $where = $kind === null ? [] : ['import_kind' => $kind];
        $list = [];
        foreach ($this->store->select(self::BATCHES, $principal->tenantId, $establishmentId, $where, 'created_at DESC', 200) as $row) {
            $list[] = self::summary($row);
        }

        return $list;
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id, ?string $status): array
    {
        $this->perimeter($principal, $establishmentId);

        return $this->report($principal->tenantId, $establishmentId, $id, $status);
    }

    /** @return array<string, mixed> */
    private function report(string $tenantId, string $establishmentId, string $id, ?string $status): array
    {
        $batch = $this->batch($tenantId, $establishmentId, $id);
        $where = ['batch_id' => $id];
        if ($status !== null) {
            $where['item_status'] = $status;
        }
        $lines = [];
        $truncated = false;
        foreach ($this->store->select(self::ITEMS, $tenantId, $establishmentId, $where, 'line_no', 6000) as $item) {
            if ($status === null && $item['item_status'] === 'ok') {
                continue;
            }
            if (count($lines) >= self::LINES_LIMIT) {
                $truncated = true;
                break;
            }
            $messages = json_decode((string) $item['messages_json'], true);
            $lines[] = [
                'line' => (int) $item['line_no'], 'status' => (string) $item['item_status'], 'key' => (string) $item['key_ref'],
                'errors' => is_array($messages) ? array_values((array) ($messages['errors'] ?? [])) : [],
                'warnings' => is_array($messages) ? array_values((array) ($messages['warnings'] ?? [])) : [],
            ];
        }
        $payload = json_decode((string) $batch['payload'], true);

        return self::summary($batch) + [
            'unknown_columns' => is_array($payload) ? array_values((array) ($payload['unknown'] ?? [])) : [],
            'lines' => $lines, 'lines_truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function summary(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'kind' => (string) $row['import_kind'], 'kind_label' => ImportKind::label((string) $row['import_kind']),
            'academic_year_id' => (string) $row['academic_year_id'], 'status' => (string) $row['batch_status'], 'source_format' => (string) $row['source_format'],
            'file_name' => $row['file_name'] === null ? null : (string) $row['file_name'], 'file_sha256' => (string) $row['file_sha256'],
            'counts' => [
                'total' => (int) $row['rows_total'], 'ok' => (int) $row['rows_ok'], 'warning' => (int) $row['rows_warning'],
                'duplicate' => (int) $row['rows_duplicate'], 'error' => (int) $row['rows_error'], 'applied' => (int) $row['rows_applied'],
            ],
            'applicable' => $row['batch_status'] === self::SIMULATED && (int) $row['rows_error'] === 0,
            'created_by' => (string) $row['created_by'], 'created_at' => (string) $row['created_at'],
            'applied_at' => $row['applied_at'] === null ? null : (string) $row['applied_at'], 'reverted_at' => $row['reverted_at'] === null ? null : (string) $row['reverted_at'],
            'revert_reason' => $row['revert_reason'] === null ? null : (string) $row['revert_reason'],
        ];
    }

    /** @return array<string, mixed> */
    private function batch(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::BATCHES, $tenantId, $establishmentId, $id, 'Lot de reprise introuvable.');
    }

    private function context(Principal $principal, string $establishmentId, string $yearId, string $batchId, Perimeter $perimeter, string $kind): RunContext
    {
        $prior = [];
        foreach ($this->store->select(self::ITEMS, $principal->tenantId, $establishmentId, ['import_kind' => $kind, 'academic_year_id' => $yearId, 'live' => 1], 'id', 20000) as $item) {
            $prior[(string) $item['key_ref']] = true;
        }

        return new RunContext($principal->tenantId, $establishmentId, $principal->userId, $yearId, $batchId, $perimeter, $prior);
    }

    private function assertRecentSecondFactor(Principal $principal): void
    {
        if (!$this->secondFactor->hasRecent($principal->tenantId, $principal->userId, self::SECOND_FACTOR_SECONDS)) {
            throw new HttpException(403, 'Double authentification requise', 'Ressaisissez votre code de double authentification pour appliquer ou annuler une reprise de données.', self::SECOND_FACTOR_TYPE);
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function assertNoDependentBatch(string $tenantId, string $establishmentId, string $batchId, array $items): void
    {
        $persons = array_flip(array_filter(array_map(static fn (array $i): ?string => is_string($i['person_id']) ? $i['person_id'] : null, $items)));
        $statement = $this->store->pdo()->prepare('SELECT DISTINCT person_id, import_kind FROM hi_import_items WHERE tenant_id = ? AND establishment_id = ? AND live = 1 AND batch_id <> ? AND import_kind <> ?');
        $statement->execute([$tenantId, $establishmentId, $batchId, ImportKind::STUDENTS]);
        $kinds = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($persons[(string) $row['person_id']])) {
                $kinds[(string) $row['import_kind']] = true;
            }
        }
        if ($kinds !== []) {
            throw HttpException::conflict('Annulation impossible : des lots appliqués dépendent de ces étudiants (' . implode(', ', array_map(ImportKind::label(...), array_keys($kinds))) . '). Annulez-les d\'abord.');
        }
    }

    /**
     * @param list<RowVerdict> $verdicts
     * @return array{total: int, ok: int, warning: int, duplicate: int, error: int}
     */
    private static function count(array $verdicts): array
    {
        $ok = $warning = $duplicate = $error = 0;
        foreach ($verdicts as $verdict) {
            match ($verdict->status()) {
                RowVerdict::OK => ++$ok,
                RowVerdict::WARNING => ++$warning,
                RowVerdict::DUPLICATE => ++$duplicate,
                default => ++$error,
            };
        }

        return ['total' => count($verdicts), 'ok' => $ok, 'warning' => $warning, 'duplicate' => $duplicate, 'error' => $error];
    }
}
