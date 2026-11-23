<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Bulletins (pp_report_cards) et banque d'appréciations (pp_appraisal_phrases), bornés par client et par école. */
final class PdoReportCards
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_report_cards WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function forScope(string $tenantId, string $personId, string $kind, string $ref): ?array
    {
        return $this->one('SELECT * FROM pp_report_cards WHERE tenant_id = ? AND person_id = ? AND card_kind = ? AND scope_ref = ?', [$tenantId, $personId, $kind, $ref]);
    }

    public function isFrozen(string $tenantId, string $personId, string $kind, string $ref): bool
    {
        $card = $this->forScope($tenantId, $personId, $kind, $ref);

        return $card !== null && $card['card_state'] !== 'draft';
    }

    /** @param array<string, mixed> $content */
    public function insert(string $tenantId, string $establishmentId, string $personId, string $structureId, string $kind, string $ref, string $yearLabel, array $content, string $actorId, string $at): string
    {
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare('INSERT INTO pp_report_cards (id, tenant_id, establishment_id, person_id, structure_id, card_kind, scope_ref, year_label, card_state, content, generated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $personId, $structureId, $kind, $ref, $yearLabel, 'draft', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $actorId, $at, $at]);

        return $id;
    }

    /** @param array<string, mixed> $content */
    public function refreshDraft(string $tenantId, string $id, array $content, string $at): void
    {
        $this->connections->pdo()->prepare("UPDATE pp_report_cards SET content = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND card_state = 'draft'")
            ->execute([json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $at, $tenantId, $id]);
    }

    public function setAppraisal(string $tenantId, string $id, ?string $appraisal, string $at): void
    {
        $this->connections->pdo()->prepare('UPDATE pp_report_cards SET appraisal = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$appraisal, $at, $tenantId, $id]);
    }

    /** @param array<string, mixed> $content */
    public function publish(string $tenantId, string $id, array $content, ?string $documentId, string $actorId, string $at): void
    {
        $this->connections->pdo()->prepare("UPDATE pp_report_cards SET card_state = 'published', content = ?, document_id = ?, published_by = ?, published_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?")
            ->execute([json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $documentId, $actorId, $at, $at, $tenantId, $id]);
    }

    /** @param array<string, mixed> $content */
    public function rectify(string $tenantId, string $id, array $content, ?string $documentId, string $reason, string $at): void
    {
        $this->connections->pdo()->prepare("UPDATE pp_report_cards SET card_state = 'rectified', content = ?, document_id = ?, rectified_reason = ?, updated_at = ? WHERE tenant_id = ? AND id = ?")
            ->execute([json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $documentId, $reason, $at, $tenantId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, string $structureId, ?string $kind, ?string $ref, ?string $afterId, int $limit): array
    {
        $sql = 'SELECT * FROM pp_report_cards WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND id > ?';
        $params = [$tenantId, $establishmentId, $structureId, $afterId ?? ''];
        if ($kind !== null) {
            $sql .= ' AND card_kind = ?';
            $params[] = $kind;
        }
        if ($ref !== null) {
            $sql .= ' AND scope_ref = ?';
            $params[] = $ref;
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute($params);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> bulletins publiés d'un élève (portail famille) */
    public function publishedOf(string $tenantId, string $personId): array
    {
        $query = $this->connections->pdo()->prepare("SELECT * FROM pp_report_cards WHERE tenant_id = ? AND person_id = ? AND card_state IN ('published', 'rectified') ORDER BY published_at DESC, id DESC");
        $query->execute([$tenantId, $personId]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addPhrase(string $tenantId, string $establishmentId, string $locale, string $phrase, string $actorId, string $at): string
    {
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare('INSERT INTO pp_appraisal_phrases (id, tenant_id, establishment_id, locale, phrase, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $locale, $phrase, $actorId, $at]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function phrases(string $tenantId, string $establishmentId, ?string $locale, ?string $afterId, int $limit): array
    {
        $sql = 'SELECT id, locale, phrase FROM pp_appraisal_phrases WHERE tenant_id = ? AND establishment_id = ? AND id > ?';
        $params = [$tenantId, $establishmentId, $afterId ?? ''];
        if ($locale !== null) {
            $sql .= ' AND locale = ?';
            $params[] = $locale;
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute($params);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $query = $this->connections->pdo()->prepare($sql);
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
