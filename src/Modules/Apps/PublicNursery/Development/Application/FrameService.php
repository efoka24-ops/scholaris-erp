<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;

/**
 * Référentiel national de la Maternelle publique : uniquement des versions signées, jamais modifiées par les établissements.
 *
 * @phpstan-type FrameData array{frame_code: string, version: int, subsystem: string, label_fr: string, label_en: string, status: string, validation_ref: ?string}
 * @phpstan-type FrameImportError array{line: int, field: ?string, message: string}
 */
final class FrameService
{
    private const STATUSES = ['draft', 'active', 'retired'];
    private const COLUMNS = ['frame_code', 'version', 'subsystem', 'label_fr', 'label_en', 'status', 'validation_ref'];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param ?int $limit nombre de lignes à lire (null : toutes), à partir de $offset
     * @return list<array<string, mixed>>
     */
    public function all(string $tenantId, ?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM pn_dev_frames WHERE tenant_id = ? ORDER BY frame_code, version, id';
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));
        }
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute([$tenantId]);

        return array_values(array_map(self::cast(...), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<string, mixed>|null */
    public function show(string $tenantId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_dev_frames WHERE tenant_id = ? AND id = ?');
        $statement->execute([$tenantId, $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function latest(string $tenantId, string $frameCode): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_dev_frames WHERE tenant_id = ? AND frame_code = ? ORDER BY version DESC LIMIT 1');
        $statement->execute([$tenantId, $frameCode]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * @param array<string, mixed> $input corps JSON de la requête : validé ici, jamais supposé correct
     * @return array<string, mixed>
     */
    public function save(string $tenantId, array $input): array
    {
        return $this->persist($tenantId, $this->normalize($input));
    }

    /**
     * @param FrameData $data
     * @return array<string, mixed>
     */
    private function persist(string $tenantId, array $data): array
    {
        $pdo = $this->connections->pdo();
        $existing = $pdo->prepare('SELECT id FROM pn_dev_frames WHERE tenant_id = ? AND frame_code = ? AND version = ? LIMIT 1');
        $existing->execute([$tenantId, $data['frame_code'], $data['version']]);
        $now = self::now();

        if (($id = $existing->fetchColumn()) !== false) {
            $pdo->prepare('UPDATE pn_dev_frames SET subsystem = ?, label_fr = ?, label_en = ?, status = ?, validation_ref = ?, created_at = created_at WHERE tenant_id = ? AND id = ?')
                ->execute([$data['subsystem'], $data['label_fr'], $data['label_en'], $data['status'], $data['validation_ref'], $tenantId, $id]);
        } else {
            $pdo->prepare('INSERT INTO pn_dev_frames (id, tenant_id, frame_code, version, subsystem, label_fr, label_en, status, validation_ref, created_at, activated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)')
                ->execute([(string) new Ulid(), $tenantId, $data['frame_code'], $data['version'], $data['subsystem'], $data['label_fr'], $data['label_en'], $data['status'], $data['validation_ref'], $now]);
        }

        return $this->latest($tenantId, $data['frame_code']) ?? throw new \LogicException('Cadre indisponible après écriture.');
    }

    /** @return array{total: int, valid: int, created: int, validate_only: bool, errors: list<FrameImportError>} */
    public function importCsv(string $tenantId, string $csv, bool $validateOnly = false): array
    {
        if (strlen($csv) > 1_048_576) {
            throw HttpException::validation(['csv' => ['Fichier trop volumineux.']]);
        }
        [$records, $errors] = $this->parse($csv);
        $valid = [];
        foreach ($records as $line => $record) {
            try {
                $valid[] = $this->normalize($record);
            } catch (HttpException $e) {
                foreach ($e->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['line' => $line, 'field' => $field, 'message' => $message];
                    }
                }
            }
        }

        $created = 0;
        if ($errors === [] && !$validateOnly) {
            $pdo = $this->connections->pdo();
            $pdo->beginTransaction();
            try {
                foreach ($valid as $row) {
                    $this->persist($tenantId, $row);
                    ++$created;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return ['total' => count($records), 'valid' => count($valid), 'created' => $created, 'validate_only' => $validateOnly, 'errors' => $errors];
    }

    /** @return array{0: array<int, array<string, string>>, 1: list<FrameImportError>} */
    private function parse(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $first = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Flux mémoire indisponible.');
        }
        fwrite($stream, $csv);
        rewind($stream);

        $header = [];
        $records = [];
        $errors = [];
        $line = 0;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            ++$line;
            if ($cells === [null] || implode('', array_map(static fn (?string $c): string => trim((string) $c), $cells)) === '') {
                continue;
            }
            if ($header === []) {
                $header = array_map(static fn (?string $c): string => mb_strtolower(trim((string) $c)), $cells);
                foreach (self::COLUMNS as $column) {
                    if (!in_array($column, $header, true)) {
                        $errors[] = ['line' => $line, 'field' => $column, 'message' => 'Colonne obligatoire absente.'];
                    }
                }
                continue;
            }
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($cells[$index] ?? ''));
            }
            $records[$line] = $row;
        }
        fclose($stream);

        return [$records, $errors];
    }

    /**
     * Valide une ligne (corps JSON ou ligne CSV) : toute valeur absente ou d'un autre type que texte ou entier est refusée en 422.
     *
     * @param array<string, mixed> $row
     * @return FrameData
     */
    private function normalize(array $row): array
    {
        $errors = [];
        $frameCode = self::text($row['frame_code'] ?? null);
        if (preg_match('/^[A-Z0-9_.-]{2,40}$/i', $frameCode) !== 1) {
            $errors['frame_code'][] = 'Code de cadre attendu.';
        }
        $rawVersion = $row['version'] ?? null;
        $version = is_int($rawVersion) || is_string($rawVersion) ? filter_var($rawVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($version === false) {
            $errors['version'][] = 'Version entière positive attendue.';
        }
        $subsystem = strtolower(self::text($row['subsystem'] ?? null));
        if (!in_array($subsystem, ['fr', 'en'], true)) {
            $errors['subsystem'][] = 'Sous-système attendu : fr ou en.';
        }
        $labels = [];
        foreach (['label_fr', 'label_en'] as $field) {
            $labels[$field] = self::text($row[$field] ?? null);
            if ($labels[$field] === '') {
                $errors[$field][] = 'Libellé requis.';
            }
        }
        $status = strtolower(self::text($row['status'] ?? null));
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'][] = 'Statut attendu : draft, active ou retired.';
        }
        $validationRef = self::text($row['validation_ref'] ?? null);
        if ($errors !== [] || $version === false) {
            throw HttpException::validation($errors);
        }

        return [
            'frame_code' => $frameCode,
            'version' => $version,
            'subsystem' => $subsystem,
            'label_fr' => $labels['label_fr'],
            'label_en' => $labels['label_en'],
            'status' => $status,
            'validation_ref' => $validationRef !== '' ? $validationRef : null,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'frame_code' => (string) $row['frame_code'],
            'version' => (int) $row['version'],
            'subsystem' => (string) $row['subsystem'],
            'label_fr' => (string) $row['label_fr'],
            'label_en' => (string) $row['label_en'],
            'status' => (string) $row['status'],
            'validation_ref' => $row['validation_ref'] !== null ? (string) $row['validation_ref'] : null,
            'created_at' => (string) $row['created_at'],
            'activated_at' => $row['activated_at'] !== null ? (string) $row['activated_at'] : null,
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
