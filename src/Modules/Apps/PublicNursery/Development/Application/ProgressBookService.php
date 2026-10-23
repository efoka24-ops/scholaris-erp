<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;

/** Carnet de suivi trimestriel DOC-18 : brouillon, validation, publication et réédition en duplicata/rectificatif. */
final class ProgressBookService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly PdfRenderer $pdf,
    ) {
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function saveDraft(
        string $tenantId,
        string $establishmentId,
        string $learnerId,
        string $structureId,
        string $periodId,
        string $schoolYear,
        array $items,
        string $synthesis,
    ): array {
        $snapshot = $this->snapshot($items);
        $pdo = $this->connections->pdo();
        $existing = $this->findByPeriod($tenantId, $learnerId, $periodId);
        $now = self::now();
        if ($existing !== null && $existing['status'] !== 'draft') {
            throw HttpException::conflict('Le carnet est déjà verrouillé ; créez une réédition.');
        }
        if ($existing === null) {
            $pdo->prepare(
                'INSERT INTO pn_progress_books (id, tenant_id, establishment_id, learner_id, structure_id, period_id, school_year, status, synthesis, snapshot, edition, edition_label, validated_by, validated_at, published_at, document_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, NULL, NULL, NULL, NULL, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $learnerId, $structureId, $periodId, $schoolYear, 'draft', trim($synthesis), $snapshot, $now, $now]);
        } else {
            $pdo->prepare(
                'UPDATE pn_progress_books SET establishment_id = ?, structure_id = ?, school_year = ?, synthesis = ?, snapshot = ?, updated_at = ? WHERE tenant_id = ? AND learner_id = ? AND period_id = ? AND status = \'' . 'draft' . '\'',
            )->execute([$establishmentId, $structureId, $schoolYear, trim($synthesis), $snapshot, $now, $tenantId, $learnerId, $periodId]);
        }

        return $this->findByPeriod($tenantId, $learnerId, $periodId) ?? throw new \LogicException('Carnet introuvable après écriture.');
    }

    /** @param list<string> $forbiddenTerms
     * @return array<string, mixed>
     */
    public function validate(string $tenantId, string $establishmentId, string $id, string $validatorId, array $forbiddenTerms = []): array
    {
        $book = $this->requireBook($tenantId, $establishmentId, $id);
        if ($book['status'] !== 'draft' && $book['status'] !== 'submitted') {
            throw HttpException::conflict('Seul un brouillon peut être validé.');
        }
        $this->assertPositiveTerms((string) $book['synthesis'], $forbiddenTerms);
        $now = self::now();
        $this->connections->pdo()->prepare(
            'UPDATE pn_progress_books SET status = ?, validated_by = ?, validated_at = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute(['validated', $validatorId, $now, $now, $tenantId, $establishmentId, $id]);

        return $this->requireBook($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function publish(string $tenantId, string $establishmentId, string $id, string $publisherId): array
    {
        $book = $this->requireBook($tenantId, $establishmentId, $id);
        if ($book['status'] !== 'validated' && $book['status'] !== 'published') {
            throw HttpException::conflict('Le carnet doit être validé avant publication.');
        }
        $now = self::now();
        $documentId = $book['document_id'] ?? (string) new Ulid();
        $this->connections->pdo()->prepare(
            'UPDATE pn_progress_books SET status = ?, document_id = ?, published_at = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute(['published', $documentId, $now, $now, $tenantId, $establishmentId, $id]);

        return $this->requireBook($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function reissue(string $tenantId, string $establishmentId, string $id, string $actorId, string $label): array
    {
        $book = $this->requireBook($tenantId, $establishmentId, $id);
        if ($book['status'] !== 'validated' && $book['status'] !== 'published') {
            throw HttpException::conflict('Une réédition exige un carnet validé ou publié.');
        }
        if (!in_array($label, ['duplicate', 'rectified'], true)) {
            throw HttpException::validation(['edition_label' => ['Valeur attendue : duplicate ou rectified.']]);
        }
        $now = self::now();
        $this->connections->pdo()->prepare(
            'UPDATE pn_progress_books SET status = ?, edition = edition + 1, edition_label = ?, published_at = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute(['published', $label, $now, $now, $tenantId, $establishmentId, $id]);

        return $this->requireBook($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_progress_books WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @param list<string> $establishmentIds
     * @return array<string, mixed>|null
     */
    public function latestPublishedForLearner(string $tenantId, string $learnerId, array $establishmentIds = []): ?array
    {
        $sql = 'SELECT * FROM pn_progress_books WHERE tenant_id = ? AND learner_id = ? AND status = \'published\'';
        $params = [$tenantId, $learnerId];
        if ($establishmentIds !== []) {
            $sql .= ' AND establishment_id IN (' . implode(', ', array_fill(0, count($establishmentIds), '?')) . ')';
            array_push($params, ...$establishmentIds);
        }
        $sql .= ' ORDER BY published_at DESC, updated_at DESC, id DESC LIMIT 1';

        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    public function pdf(string $tenantId, string $establishmentId, string $id, string $locale = 'fr'): string
    {
        $book = $this->requireBook($tenantId, $establishmentId, $id);
        if ($book['status'] !== 'published') {
            throw HttpException::notFound('Carnet non publié.');
        }
        $html = $this->html($book, $locale);

        return $this->pdf->render($html);
    }

    /** @param array<string, mixed> $book
     * @return string HTML du DOC-18 pour vérification et rendu PDF.
     */
    public function html(array $book, string $locale = 'fr'): string
    {
        $template = $this->template($locale);
        $data = [
            'learner_name' => $book['learner_name'] ?? $book['learner_id'],
            'period_label' => $book['period_label'] ?? $book['period_id'],
            'school_year' => $book['school_year'],
            'synthesis' => $book['synthesis'] ?? '',
            'validated_by_name' => $book['validated_by_name'] ?? $book['validated_by'] ?? 'directeur',
            'items' => array_values($book['items'] ?? []),
        ];

        return $template($data, $book['edition_label'] ?? null);
    }

    /** @return callable(array<string, mixed>, ?string): string */
    private function template(string $locale): callable
    {
        $path = dirname(__DIR__, 2) . '/Documents/Templates/doc18_' . (in_array($locale, ['en'], true) ? 'en' : 'fr') . '.php';
        $template = require $path;

        return $template;
    }

    /** @param list<array<string, mixed>> $items */
    private function snapshot(array $items): string
    {
        return json_encode(array_map(static fn (array $item): array => [
            'domain' => (string) ($item['domain'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'level' => (string) ($item['level'] ?? ''),
            'comment' => (string) ($item['comment'] ?? ''),
        ], $items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $forbiddenTerms */
    private function assertPositiveTerms(string $synthesis, array $forbiddenTerms): void
    {
        foreach ($forbiddenTerms as $term) {
            $needle = trim(mb_strtolower($term));
            if ($needle !== '' && str_contains(mb_strtolower($synthesis), $needle)) {
                throw HttpException::validation(['synthesis' => ['Terme déconseillé détecté : ' . $term . '.']]);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function findByPeriod(string $tenantId, string $learnerId, string $periodId): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_progress_books WHERE tenant_id = ? AND learner_id = ? AND period_id = ?');
        $statement->execute([$tenantId, $learnerId, $periodId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string, mixed> */
    private function requireBook(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Carnet introuvable.');
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
            'period_id' => (string) $row['period_id'],
            'school_year' => (string) $row['school_year'],
            'status' => (string) $row['status'],
            'synthesis' => (string) ($row['synthesis'] ?? ''),
            'snapshot' => $row['snapshot'] !== null ? json_decode((string) $row['snapshot'], true, flags: JSON_THROW_ON_ERROR) : [],
            'edition' => (int) $row['edition'],
            'edition_label' => $row['edition_label'] !== null ? (string) $row['edition_label'] : null,
            'validated_by' => $row['validated_by'] !== null ? (string) $row['validated_by'] : null,
            'validated_at' => $row['validated_at'] !== null ? (string) $row['validated_at'] : null,
            'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
            'document_id' => $row['document_id'] !== null ? (string) $row['document_id'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
