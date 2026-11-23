<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Migration\Domain\MigrationDefaults;
use Scholaris\Modules\Apps\Secondary\Migration\Infrastructure\SheetReader;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Reprise de données d'un ancien logiciel (T977, E4-C) : lots par fichier joint (CSV ou XLSX), un jeu par fichier.
 *  1. SIMULATION (POST /secondary/migration/batches) : rapport ligne par ligne (valide, doublon, erreur et messages), rien n'est
 *     écrit hors du lot ; un jeu atomique est rejoué dans une transaction annulée.
 *  2. APPLICATION (POST /secondary/migration/batches/{id}/apply) : le fichier est recontrôlé ; s'il reste UNE ligne en erreur,
 *     rien n'est écrit (422) ; sinon les lignes valides sont écrites par le service qui possède les données, les doublons ignorés.
 * Idempotent : une ligne reprise porte sa clé (`live`) ; un nouveau lot du même fichier n'a plus que des doublons. Cloisonné par
 * client et établissement (Store). Droits : ceux du jeu (paramétrage pour les classes, scolarité pour les élèves, intendance pour
 * les soldes), sur tout l'établissement.
 */
final class MigrationService
{
    public const STATUS_SIMULATED = 'simulated';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_PARTIAL = 'partial';
    private const BATCHES = 'se_migration_batches';
    private const ITEMS = 'se_migration_items';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SheetReader $reader,
        private readonly SecondaryClock $clock,
        private readonly EstablishmentProfiles $profiles,
        private readonly ClassRows $classRows,
        private readonly StudentRows $studentRows,
        private readonly BalanceRows $balanceRows,
    ) {
    }

    /** @return list<string> l'une suffit pour consulter les lots */
    public function readPermissions(): array
    {
        return array_values(array_unique([...$this->classRows->permissions(), ...$this->studentRows->permissions(), ...$this->balanceRows->permissions()]));
    }

    /** @return list<array<string, mixed>> jeux, colonnes, et droit de l'utilisateur sur chacun */
    public function kinds(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, $this->readPermissions());
        $out = [];
        foreach (MigrationDefaults::KINDS as $kind) {
            $out[] = [
                'kind' => $kind, 'label' => MigrationDefaults::LABELS[$kind], 'columns' => MigrationDefaults::COLUMNS[$kind], 'required' => MigrationDefaults::REQUIRED[$kind],
                'allowed' => !$this->access->perimeter($principal, $establishmentId, $this->rows($kind)->permissions())->isEmpty(),
            ];
        }

        return $out;
    }

    /** @return array{columns: list<string>, sample: list<list<string>>} */
    public function template(Principal $principal, string $establishmentId, string $kind): array
    {
        $this->access->require($principal, $establishmentId, $this->readPermissions());
        if (!MigrationDefaults::exists($kind)) {
            throw HttpException::notFound('Jeu de reprise inconnu.');
        }

        return ['columns' => MigrationDefaults::COLUMNS[$kind], 'sample' => MigrationDefaults::SAMPLES[$kind]];
    }

    /** @return list<array<string, mixed>> lots de l'établissement, du plus récent au plus ancien */
    public function index(Principal $principal, string $establishmentId, ?string $kind): array
    {
        $this->access->require($principal, $establishmentId, $this->readPermissions());
        $where = $kind !== null && MigrationDefaults::exists($kind) ? ['import_kind' => $kind] : [];

        return array_map(self::batchView(...), $this->store->select(self::BATCHES, $principal->tenantId, $establishmentId, $where, 'created_at DESC', 100));
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        $this->access->require($principal, $establishmentId, $this->readPermissions());
        $batch = $this->store->findOrFail(self::BATCHES, $principal->tenantId, $establishmentId, $id, 'Lot de reprise introuvable.');

        return $this->withLines($principal->tenantId, $establishmentId, $batch);
    }

    /**
     * @param array{kind: string, academic_year_id: string, entry_date: ?string, format: string, content: string, file_name: ?string} $input
     * @return array<string, mixed> lot simulé avec son rapport
     */
    public function simulate(Principal $principal, string $establishmentId, array $input): array
    {
        $kind = $input['kind'];
        $rows = $this->rows($kind);
        $this->writable($principal, $establishmentId, $rows);
        $table = $this->reader->read($input['format'], $input['content'], MigrationDefaults::COLUMNS[$kind], MigrationDefaults::REQUIRED[$kind]);
        $lines = $this->verdicts($principal, $establishmentId, $kind, $input['academic_year_id'], $input['entry_date'], $table['rows'], true);
        $counts = self::counts($lines);
        $tenantId = $principal->tenantId;
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $kind, $input, $table, $lines, $counts, $now, $principal): void {
            $this->store->insert(self::BATCHES, $tenantId, $establishmentId, [
                'id' => $id, 'import_kind' => $kind, 'academic_year_id' => $input['academic_year_id'], 'entry_date' => $input['entry_date'],
                'source_format' => $input['format'], 'file_name' => $input['file_name'] === null ? null : mb_substr($input['file_name'], 0, 160),
                'file_sha256' => hash('sha256', $input['content']), 'batch_status' => self::STATUS_SIMULATED, 'rows_total' => $counts['total'],
                'rows_ok' => $counts['valid'], 'rows_error' => $counts['error'], 'rows_duplicate' => $counts['duplicate'], 'rows_applied' => 0,
                'unknown_columns' => json_encode($table['unknown'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'payload' => json_encode($table['rows'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by' => $principal->userId, 'created_at' => $now,
            ]);
            foreach ($lines as $line) {
                $this->store->insert(self::ITEMS, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'batch_id' => $id, 'import_kind' => $kind, 'academic_year_id' => $input['academic_year_id'], 'line_no' => $line['line'],
                    'item_status' => $line['status'], 'key_ref' => mb_substr($line['key'], 0, 160), 'label' => mb_substr($line['label'], 0, 200),
                    'messages_json' => json_encode($line['messages'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'object_ref' => null, 'live' => 0,
                ]);
            }
        });
        $this->access->audit($principal, 'secondary.migration.simulated', 'se_migration_batch', $id, null, ['kind' => $kind] + $counts);

        return $this->show($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> lot appliqué */
    public function apply(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $batch = $this->store->findOrFail(self::BATCHES, $tenantId, $establishmentId, $id, 'Lot de reprise introuvable.');
        $kind = (string) $batch['import_kind'];
        $rows = $this->rows($kind);
        $this->writable($principal, $establishmentId, $rows);
        if ($batch['batch_status'] !== self::STATUS_SIMULATED && $batch['batch_status'] !== self::STATUS_PARTIAL) {
            throw HttpException::conflict('Ce lot est déjà appliqué : simulez un nouveau fichier pour reprendre d\'autres lignes.');
        }
        $year = (string) $batch['academic_year_id'];
        $entryDate = is_string($batch['entry_date']) ? $batch['entry_date'] : null;
        /** @var array<int, array<string, string>> $payload */
        $payload = json_decode((string) $batch['payload'], true, 512, JSON_THROW_ON_ERROR);
        $lines = $this->verdicts($principal, $establishmentId, $kind, $year, $entryDate, $payload, $rows->atomic());
        $counts = self::counts($lines);
        if ($counts['error'] > 0) {
            $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $lines, $counts): void {
                $this->saveVerdicts($tenantId, $establishmentId, $id, $lines);
                $this->store->update(self::BATCHES, $tenantId, $establishmentId, ['rows_ok' => $counts['valid'], 'rows_error' => $counts['error'], 'rows_duplicate' => $counts['duplicate']], ['id' => $id]);
            });

            throw new HttpException(422, 'Reprise refusée', sprintf('Rien n\'a été écrit : %d ligne(s) en erreur. Corrigez le fichier, simulez-le de nouveau, puis appliquez.', $counts['error']), 'https://api.scholaris.cm/errors/import-rejected', ['file' => [sprintf('%d ligne(s) en erreur (voir le rapport du lot).', $counts['error'])]]);
        }
        $applied = 0;
        $failed = 0;
        if ($rows->atomic()) {
            $this->store->transaction(function () use ($principal, $establishmentId, $id, $year, $rows, &$lines, &$applied): void {
                foreach ($lines as $index => $line) {
                    if ($line['status'] === 'valid' && $line['plan'] !== null) {
                        $lines[$index]['object_ref'] = $rows->apply($principal, $establishmentId, $year, $line['plan']);
                        $lines[$index]['status'] = 'applied';
                        ++$applied;
                    }
                }
                $this->saveVerdicts($principal->tenantId, $establishmentId, $id, $lines);
            });
        } else {
            foreach ($lines as $index => $line) {
                if ($line['status'] !== 'valid' || $line['plan'] === null) {
                    continue;
                }
                try {
                    $lines[$index]['object_ref'] = $rows->apply($principal, $establishmentId, $year, $line['plan']);
                    $lines[$index]['status'] = 'applied';
                    ++$applied;
                } catch (HttpException $e) {
                    $lines[$index]['status'] = 'error';
                    $lines[$index]['messages'][] = Cells::explain($e);
                    ++$failed;
                }
                $this->saveVerdicts($tenantId, $establishmentId, $id, [$lines[$index]]);
            }
        }
        $status = $failed > 0 ? self::STATUS_PARTIAL : self::STATUS_APPLIED;
        $this->store->update(self::BATCHES, $tenantId, $establishmentId, [
            'batch_status' => $status, 'rows_applied' => (int) $batch['rows_applied'] + $applied, 'rows_ok' => $counts['valid'] - $failed, 'rows_error' => $failed, 'rows_duplicate' => $counts['duplicate'],
            'applied_by' => $principal->userId, 'applied_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->access->audit($principal, 'secondary.migration.applied', 'se_migration_batch', $id, null, ['kind' => $kind, 'applied' => $applied, 'failed' => $failed, 'duplicate' => $counts['duplicate']]);

        return $this->show($principal, $establishmentId, $id);
    }

    private function rows(string $kind): KindRows
    {
        return match ($kind) {
            MigrationDefaults::CLASSES => $this->classRows,
            MigrationDefaults::STUDENTS => $this->studentRows,
            MigrationDefaults::BALANCES => $this->balanceRows,
            default => throw HttpException::validation(['kind' => ['Jeu attendu parmi : ' . implode(', ', MigrationDefaults::KINDS) . '.']]),
        };
    }

    private function writable(Principal $principal, string $establishmentId, KindRows $rows): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, $rows->permissions());
        $this->access->assertWhole($principal, $perimeter, 'se_migration_batch');
        if (!$this->profiles->forEstablishment($principal->tenantId, $establishmentId)->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }
    }

    /**
     * Verdicts du jeu, puis règles communes : ligne d'une clé déjà reprise = doublon ; même clé deux fois dans le fichier = erreur ;
     * jeu atomique (si `$trial`) rejoué dans une transaction annulée pour remonter les refus de l'application.
     *
     * @param array<int, array<string, string>> $rows
     * @return list<array<string, mixed>>
     */
    private function verdicts(Principal $principal, string $establishmentId, string $kind, string $year, ?string $entryDate, array $rows, bool $trial): array
    {
        $kindRows = $this->rows($kind);
        $lines = $kindRows->plan($principal, $establishmentId, $year, $entryDate, $rows);
        $seen = [];
        foreach ($lines as $index => $line) {
            if ($line['status'] === 'error') {
                continue;
            }
            if (isset($seen[$line['key']])) {
                $lines[$index] = ['status' => 'error', 'plan' => null, 'messages' => ['Même ligne que la ligne ' . $seen[$line['key']] . ' (doublon dans le fichier).']] + $line;
                continue;
            }
            $seen[$line['key']] = $line['line'];
            if ($line['status'] === 'valid' && $this->store->count(self::ITEMS, $principal->tenantId, $establishmentId, ['import_kind' => $kind, 'academic_year_id' => $year, 'key_ref' => mb_substr($line['key'], 0, 160), 'live' => 1]) > 0) {
                $lines[$index] = ['status' => 'duplicate', 'plan' => null, 'messages' => ['Ligne déjà reprise par un lot précédent : ignorée.']] + $line;
            }
        }
        if ($trial && $kindRows->atomic()) {
            $lines = $this->trial($principal, $establishmentId, $year, $kindRows, $lines);
        }

        return $lines;
    }

    /**
     * Rejoue l'application des lignes valides dans une transaction TOUJOURS annulée : chaque refus devient une erreur de sa ligne.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function trial(Principal $principal, string $establishmentId, string $year, KindRows $rows, array $lines): array
    {
        $pdo = $this->store->pdo();
        if ($pdo->inTransaction()) {
            return $lines;
        }
        $pdo->beginTransaction();
        try {
            foreach ($lines as $index => $line) {
                if ($line['status'] !== 'valid' || $line['plan'] === null) {
                    continue;
                }
                $savepoint = 'mig_' . $index;
                $pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    $rows->apply($principal, $establishmentId, $year, $line['plan']);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (HttpException $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $lines[$index]['status'] = 'error';
                    $lines[$index]['plan'] = null;
                    $lines[$index]['messages'][] = Cells::explain($e);
                }
            }
        } finally {
            $pdo->rollBack();
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines */
    private function saveVerdicts(string $tenantId, string $establishmentId, string $batchId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->store->update(self::ITEMS, $tenantId, $establishmentId, [
                'item_status' => (string) $line['status'], 'messages_json' => json_encode($line['messages'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'object_ref' => isset($line['object_ref']) ? (string) $line['object_ref'] : null, 'live' => $line['status'] === 'applied' ? 1 : 0,
            ], ['batch_id' => $batchId, 'line_no' => (int) $line['line']]);
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{total: int, valid: int, duplicate: int, error: int}
     */
    private static function counts(array $lines): array
    {
        $count = static fn (string $status): int => count(array_filter($lines, static fn (array $l): bool => $l['status'] === $status));

        return ['total' => count($lines), 'valid' => $count('valid'), 'duplicate' => $count('duplicate'), 'error' => $count('error')];
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function withLines(string $tenantId, string $establishmentId, array $batch): array
    {
        $lines = array_map(static fn (array $item): array => [
            'line' => (int) $item['line_no'], 'status' => (string) $item['item_status'], 'label' => (string) ($item['label'] ?? ''),
            'messages' => is_string($item['messages_json']) ? (array) json_decode($item['messages_json'], true) : [], 'object_ref' => $item['object_ref'],
        ], $this->store->select(self::ITEMS, $tenantId, $establishmentId, ['batch_id' => (string) $batch['id']], 'line_no', MigrationDefaults::MAX_ROWS));

        return self::batchView($batch) + ['lines' => $lines];
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private static function batchView(array $batch): array
    {
        return [
            'id' => (string) $batch['id'], 'kind' => (string) $batch['import_kind'], 'academic_year_id' => (string) $batch['academic_year_id'],
            'entry_date' => $batch['entry_date'], 'format' => (string) $batch['source_format'], 'file_name' => $batch['file_name'], 'status' => (string) $batch['batch_status'],
            'summary' => [
                'total' => (int) $batch['rows_total'], 'valid' => (int) $batch['rows_ok'], 'duplicate' => (int) $batch['rows_duplicate'],
                'error' => (int) $batch['rows_error'], 'applied' => (int) $batch['rows_applied'],
            ],
            'unknown_columns' => is_string($batch['unknown_columns']) ? (array) json_decode($batch['unknown_columns'], true) : [],
            'created_by' => (string) $batch['created_by'], 'created_at' => (string) $batch['created_at'], 'applied_at' => $batch['applied_at'],
        ];
    }
}
