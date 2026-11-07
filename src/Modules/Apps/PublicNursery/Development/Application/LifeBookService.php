<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/** Cahier de vie : photos et activités visibles uniquement au rythme du consentement courant. */
final class LifeBookService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly MediaStore $mediaStore,
        private readonly ConsentRecorder $consents,
    ) {
    }

    /**
     * @param ?list<string> $onlyStructures restreint aux sections d'un périmètre (null : tout l'établissement)
     * @param ?int $limit nombre d'entrées à lire (null : toutes), à partir de $offset
     * @return list<array<string, mixed>>
     */
    public function all(string $tenantId, string $establishmentId, ?string $structureId = null, ?int $limit = null, int $offset = 0, ?array $onlyStructures = null): array
    {
        $sql = 'SELECT * FROM pn_lifebook_entries WHERE tenant_id = ? AND establishment_id = ? AND status = \'published\'';
        $params = [$tenantId, $establishmentId];
        if ($structureId !== null && $structureId !== '') {
            $sql .= ' AND structure_id = ?';
            $params[] = $structureId;
        }
        if ($onlyStructures !== null) {
            if ($onlyStructures === []) {
                return [];
            }
            $sql .= ' AND structure_id IN (' . implode(', ', array_fill(0, count($onlyStructures), '?')) . ')';
            array_push($params, ...$onlyStructures);
        }
        $sql .= ' ORDER BY activity_on DESC, published_at DESC, created_at DESC, id DESC';
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));
        }
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values(array_map(fn (array $row): array => $this->presentEntry($tenantId, $establishmentId, self::castEntry($row)), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_lifebook_entries WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND status = \'published\' LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->presentEntry($tenantId, $establishmentId, self::castEntry($row)) : null;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveDraft(string $tenantId, string $establishmentId, string $structureId, string $authorId, array $data): array
    {
        $payload = $this->validateEntry($data);
        $id = (string) new Ulid();
        $now = self::now();
        $this->connections->pdo()->prepare(
            'INSERT INTO pn_lifebook_entries (id, tenant_id, establishment_id, structure_id, title, body, activity_on, author_id, status, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'draft\', NULL, ?, ?)',
        )->execute([$id, $tenantId, $establishmentId, $structureId, $payload['title'], $payload['body'], $payload['activity_on'], $authorId, $now, $now]);

        return $this->entry($tenantId, $establishmentId, $id) ?? throw new \LogicException('Cahier de vie indisponible après écriture.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function attachMedia(string $tenantId, string $establishmentId, string $entryId, array $data): array
    {
        $entry = $this->requireEntryForWrite($tenantId, $establishmentId, $entryId);
        $content = $this->decodeContent($data);
        $mimeType = $this->mimeType($data);
        $taggingDone = ($data['tagging_done'] ?? false) === true;
        $noChildVisible = ($data['no_child_visible'] ?? false) === true;
        $childIds = $this->childIds($data);
        $maskedFromId = isset($data['masked_from_id']) && is_string($data['masked_from_id']) && trim($data['masked_from_id']) !== '' ? trim($data['masked_from_id']) : null;

        if (!$taggingDone && $noChildVisible) {
            throw HttpException::validation(['no_child_visible' => ['Un média non étiqueté ne peut pas être déclaré sans enfant visible.']]);
        }
        if ($taggingDone && !$noChildVisible && $childIds === []) {
            throw HttpException::validation(['child_ids' => ['Au moins un enfant visible ou la mention aucun enfant reconnaissable est requise.']]);
        }

        $mediaId = (string) new Ulid();
        $ref = $this->mediaStore->put($tenantId, 'public-nursery', $entryId, $content);
        $status = $this->computeMediaStatus($tenantId, $childIds, $noChildVisible, $taggingDone);
        $now = self::now();
        $this->connections->pdo()->prepare(
            'INSERT INTO pn_lifebook_media (id, tenant_id, establishment_id, entry_id, media_ref, mime_type, size_bytes, masked_from_id, no_child_visible, tagging_done, status, purge_after, created_at, purged_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
        )->execute([$mediaId, $tenantId, $establishmentId, $entry['id'], (string) $ref, $mimeType, strlen($content), $maskedFromId, $noChildVisible ? 1 : 0, $taggingDone ? 1 : 0, $status, $this->purgeAfter($entry['activity_on']), $now]);
        $this->storeChildren($tenantId, $mediaId, $childIds);

        return $this->mediaRow($tenantId, $establishmentId, $entry['id'], $mediaId, true) ?? throw new \LogicException('Média indisponible après écriture.');
    }

    /** @return array<string, mixed> */
    public function publish(string $tenantId, string $establishmentId, string $entryId): array
    {
        $entry = $this->requireEntryForWrite($tenantId, $establishmentId, $entryId);
        $mediaRows = $this->mediaRows($tenantId, $entryId);
        foreach ($mediaRows as $media) {
            if ((int) $media['tagging_done'] !== 1) {
                throw HttpException::validation(['media' => ['Une photo non étiquetée ne peut pas être publiée.']]);
            }
            $childIds = $this->children($tenantId, (string) $media['id']);
            if ((int) $media['no_child_visible'] === 0 && $childIds === []) {
                throw HttpException::validation(['media' => ['Une photo sans enfant visible doit être marquée comme aucun enfant reconnaissable.']]);
            }
            $status = $this->computeMediaStatus($tenantId, $childIds, (int) $media['no_child_visible'] === 1, true);
            $this->connections->pdo()->prepare('UPDATE pn_lifebook_media SET status = ? WHERE tenant_id = ? AND id = ?')->execute([$status, $tenantId, $media['id']]);
        }

        $now = self::now();
        $this->connections->pdo()->prepare('UPDATE pn_lifebook_entries SET status = \'published\', published_at = COALESCE(published_at, ?), updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([$now, $now, $tenantId, $establishmentId, $entryId]);

        return $this->find($tenantId, $establishmentId, $entryId) ?? throw new \LogicException('Cahier de vie indisponible après publication.');
    }

    /** Section (structure) d'une entrée, publiée ou non ; null si l'entrée n'existe pas dans cet établissement. */
    public function structureOf(string $tenantId, string $establishmentId, string $entryId): ?string
    {
        $entry = $this->entry($tenantId, $establishmentId, $entryId);

        return $entry === null ? null : (string) $entry['structure_id'];
    }

    public function mediaUrl(string $tenantId, string $establishmentId, string $entryId, string $mediaId, int $ttl = 300): string
    {
        $media = $this->visibleMedia($tenantId, $establishmentId, $entryId, $mediaId) ?? throw HttpException::notFound('Média introuvable.');

        return $this->mediaStore->signedUrl($tenantId, new MediaRef((string) $media['media_ref']), $ttl, '/api/v1/public-nursery/development/lifebook/' . $entryId . '/media/' . $mediaId);
    }

    public function readMedia(string $tenantId, string $establishmentId, string $entryId, string $mediaId, int $expires, string $signature): MediaContent
    {
        $media = $this->visibleMedia($tenantId, $establishmentId, $entryId, $mediaId) ?? throw HttpException::notFound('Média introuvable.');

        return $this->mediaStore->readSigned($tenantId, new MediaRef((string) $media['media_ref']), $expires, $signature);
    }

    /** @return array<string, mixed>|null */
    private function visibleMedia(string $tenantId, string $establishmentId, string $entryId, string $mediaId): ?array
    {
        $row = $this->mediaRow($tenantId, $establishmentId, $entryId, $mediaId, false);
        if ($row === null || (string) $row['status'] !== 'publishable') {
            return null;
        }

        $childIds = $this->children($tenantId, $mediaId);
        if ((int) $row['no_child_visible'] === 1) {
            return $row;
        }
        if ($childIds === []) {
            return null;
        }
        foreach ($childIds as $childId) {
            if (!$this->consents->hasWrittenConsent($tenantId, $childId, 'photo')) {
                return null;
            }
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function mediaRow(string $tenantId, string $establishmentId, string $entryId, string $mediaId, bool $withUrl): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_lifebook_media WHERE tenant_id = ? AND establishment_id = ? AND entry_id = ? AND id = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $entryId, $mediaId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $media = self::castMedia($row);
        if ($withUrl) {
            $media['media_url'] = $this->mediaStore->signedUrl($tenantId, new MediaRef((string) $media['media_ref']), 300, '/api/v1/public-nursery/development/lifebook/' . $entryId . '/media/' . $mediaId);
        }

        return $media;
    }

    /** @return list<array<string, mixed>> */
    private function mediaRows(string $tenantId, string $entryId): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_lifebook_media WHERE tenant_id = ? AND entry_id = ? ORDER BY created_at, id');
        $statement->execute([$tenantId, $entryId]);

        return array_values(array_map(self::castMedia(...), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return list<string> */
    private function children(string $tenantId, string $mediaId): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT learner_id FROM pn_lifebook_media_children WHERE tenant_id = ? AND media_id = ? ORDER BY learner_id');
        $statement->execute([$tenantId, $mediaId]);

        return array_values(array_map(static fn (array $row): string => (string) $row['learner_id'], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<string, mixed> */
    private function requireEntryForWrite(string $tenantId, string $establishmentId, string $entryId): array
    {
        $row = $this->entry($tenantId, $establishmentId, $entryId);

        return $row ?? throw HttpException::notFound('Cahier de vie introuvable.');
    }

    /** @return array<string, mixed>|null */
    private function entry(string $tenantId, string $establishmentId, string $entryId): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_lifebook_entries WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $entryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::castEntry($row) : null;
    }

    /** @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function presentEntry(string $tenantId, string $establishmentId, array $entry): array
    {
        $media = [];
        foreach ($this->mediaRows($tenantId, (string) $entry['id']) as $row) {
            $visible = $this->visibleMedia($tenantId, $establishmentId, (string) $entry['id'], (string) $row['id']);
            if ($visible === null) {
                continue;
            }
            $media[] = [
                'id' => $visible['id'],
                'mime_type' => $visible['mime_type'],
                'size_bytes' => $visible['size_bytes'],
                'status' => $visible['status'],
                'no_child_visible' => $visible['no_child_visible'],
                'masked_from_id' => $visible['masked_from_id'],
                'media_url' => $this->mediaStore->signedUrl($tenantId, new MediaRef((string) $visible['media_ref']), 300, '/api/v1/public-nursery/development/lifebook/' . $entry['id'] . '/media/' . $visible['id']),
            ];
        }

        return [
            'id' => $entry['id'],
            'tenant_id' => $entry['tenant_id'],
            'establishment_id' => $entry['establishment_id'],
            'structure_id' => $entry['structure_id'],
            'title' => $entry['title'],
            'body' => $entry['body'],
            'activity_on' => $entry['activity_on'],
            'author_id' => $entry['author_id'],
            'status' => $entry['status'],
            'published_at' => $entry['published_at'],
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['updated_at'],
            'media' => $media,
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{title: string, body: ?string, activity_on: string}
     */
    private function validateEntry(array $data): array
    {
        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'][] = 'Titre requis (160 caractères max).';
        }
        $body = isset($data['body']) && is_string($data['body']) && trim($data['body']) !== '' ? trim($data['body']) : null;
        $activityOn = trim((string) ($data['activity_on'] ?? ''));
        if ($activityOn === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityOn) !== 1) {
            $errors['activity_on'][] = 'Date d’activité attendue (YYYY-MM-DD).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return ['title' => $title, 'body' => $body, 'activity_on' => $activityOn];
    }

    /** @param array<string, mixed> $data */
    private function decodeContent(array $data): string
    {
        $content = $data['content'] ?? $data['content_base64'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw HttpException::validation(['content' => ['Contenu du média requis.']]);
        }
        $decoded = isset($data['content_base64']) ? base64_decode($content, true) : $content;
        if ($decoded === false || $decoded === '') {
            throw HttpException::validation(['content' => ['Contenu du média invalide.']]);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function mimeType(array $data): string
    {
        $mimeType = strtolower(trim((string) ($data['mime_type'] ?? '')));
        if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw HttpException::validation(['mime_type' => ['Mime attendu : image/jpeg ou image/png.']]);
        }

        return $mimeType;
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    private function childIds(array $data): array
    {
        $ids = $data['child_ids'] ?? [];
        if (!is_array($ids)) {
            throw HttpException::validation(['child_ids' => ['Tableau de personnes attendu.']]);
        }

        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $ids), static fn (string $value): bool => $value !== ''));
    }

    /** @param list<string> $childIds */
    private function storeChildren(string $tenantId, string $mediaId, array $childIds): void
    {
        foreach ($childIds as $childId) {
            $this->connections->pdo()->prepare('INSERT INTO pn_lifebook_media_children (id, tenant_id, media_id, learner_id) VALUES (?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $mediaId, $childId]);
        }
    }

    /** @param list<string> $childIds */
    private function computeMediaStatus(string $tenantId, array $childIds, bool $noChildVisible, bool $taggingDone): string
    {
        if (!$taggingDone) {
            return 'pending_tags';
        }
        if ($noChildVisible) {
            return 'publishable';
        }
        if ($childIds === []) {
            return 'withheld';
        }
        foreach ($childIds as $childId) {
            if (!$this->consents->hasWrittenConsent($tenantId, $childId, 'photo')) {
                return 'withheld';
            }
        }

        return 'publishable';
    }

    private function purgeAfter(string $activityOn): string
    {
        return (new \DateTimeImmutable($activityOn))->modify('+1 year')->format('Y-m-d');
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castEntry(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'establishment_id' => (string) $row['establishment_id'],
            'structure_id' => (string) $row['structure_id'],
            'title' => (string) $row['title'],
            'body' => $row['body'] !== null ? (string) $row['body'] : null,
            'activity_on' => (string) $row['activity_on'],
            'author_id' => (string) $row['author_id'],
            'status' => (string) $row['status'],
            'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castMedia(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'establishment_id' => (string) $row['establishment_id'],
            'entry_id' => (string) $row['entry_id'],
            'media_ref' => (string) $row['media_ref'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'masked_from_id' => $row['masked_from_id'] !== null ? (string) $row['masked_from_id'] : null,
            'no_child_visible' => (int) $row['no_child_visible'],
            'tagging_done' => (int) $row['tagging_done'],
            'status' => (string) $row['status'],
            'purge_after' => (string) $row['purge_after'],
            'created_at' => (string) $row['created_at'],
            'purged_at' => $row['purged_at'] !== null ? (string) $row['purged_at'] : null,
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
