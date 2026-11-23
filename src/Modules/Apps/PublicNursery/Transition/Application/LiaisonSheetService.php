<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Transition\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

final class LiaisonSheetService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly PersonDirectory $people,
        private readonly TransitionService $transitions,
    ) {
    }

    /**
     * Lit la base : deux appels successifs peuvent rendre des résultats différents (avant et après une écriture).
     *
     * @phpstan-impure
     * @return array<string, mixed>|null
     */
    public function findByLearnerYear(string $tenantId, string $establishmentId, string $learnerId, string $schoolYear): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_liaison_sheets WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND school_year = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $learnerId, $schoolYear]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string, mixed> */
    public function saveDraft(string $tenantId, string $establishmentId, string $learnerId, string $schoolYear): array
    {
        $person = $this->people->find($tenantId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');
        $decision = $this->transitions->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear)
            ?? throw HttpException::notFound('Décision de passage introuvable pour cette année.');
        $content = $this->compose($tenantId, $establishmentId, $learnerId, $schoolYear, $person->fullName(), $decision);
        $existing = $this->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear);
        $now = self::now();

        if ($existing === null) {
            $this->connections->pdo()->prepare(
                'INSERT INTO pn_liaison_sheets (id, tenant_id, establishment_id, learner_id, school_year, path_summary, strengths, needs, status, signed_by, signed_at, document_id, target_establishment_id, sent_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $learnerId, $schoolYear, $content['path_summary'], $content['strengths'], $content['needs'], 'draft', $now, $now]);
        } else {
            $this->connections->pdo()->prepare(
                'UPDATE pn_liaison_sheets SET path_summary = ?, strengths = ?, needs = ?, status = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            )->execute([$content['path_summary'], $content['strengths'], $content['needs'], 'draft', $now, $tenantId, $establishmentId, $existing['id']]);
        }

        return $this->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear) ?? throw new \LogicException('Fiche DOC-20 introuvable après écriture.');
    }

    /** @return array<string, mixed> */
    public function sign(string $tenantId, string $establishmentId, string $learnerId, string $schoolYear, string $signerId): array
    {
        $row = $this->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear)
            ?? throw HttpException::notFound('Fiche DOC-20 introuvable.');
        $now = self::now();
        $this->connections->pdo()->prepare(
            'UPDATE pn_liaison_sheets SET status = ?, signed_by = ?, signed_at = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute(['signed', $signerId, $now, $now, $tenantId, $establishmentId, $row['id']]);

        return $this->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear) ?? throw new \LogicException('Fiche DOC-20 introuvable après signature.');
    }

    /** @param array<string, mixed> $sheet */
    public function html(array $sheet, string $learnerName, string $locale = 'fr'): string
    {
        $template = $this->template($locale);

        return $template([
            'learner_name' => $learnerName,
            'school_year' => $sheet['school_year'],
            'path_summary' => $sheet['path_summary'] ?? '',
            'strengths' => $this->explodeLines((string) ($sheet['strengths'] ?? '')),
            'needs' => $this->explodeLines((string) ($sheet['needs'] ?? '')),
            'status' => $sheet['status'] ?? 'draft',
            'signed_by' => $sheet['signed_by'] ?? null,
            'signed_at' => $sheet['signed_at'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $decision
     * @return array{path_summary: string, strengths: string, needs: string}
     */
    private function compose(string $tenantId, string $establishmentId, string $learnerId, string $schoolYear, string $learnerName, array $decision): array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT o.level_code, o.remark, COALESCE(d.label_fr, o.domain_id) AS domain_label, COALESCE(i.label_fr, o.item_id) AS item_label
             FROM pn_observations o
             LEFT JOIN pn_dev_domains d ON d.tenant_id = o.tenant_id AND d.id = o.domain_id
             LEFT JOIN pn_dev_items i ON i.tenant_id = o.tenant_id AND i.id = o.item_id
             WHERE o.tenant_id = ? AND o.establishment_id = ? AND o.learner_id = ?
             ORDER BY o.observed_on DESC, o.created_at DESC, o.id DESC',
        );
        $statement->execute([$tenantId, $establishmentId, $learnerId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $strengths = [];
        $needs = [];
        foreach ($rows as $row) {
            $line = trim((string) $row['domain_label']) . ' : ' . trim((string) $row['item_label']);
            $remark = isset($row['remark']) && is_string($row['remark']) && trim($row['remark']) !== '' ? ' - ' . trim((string) $row['remark']) : '';
            if (in_array((string) $row['level_code'], ['acquired', 'in_progress'], true)) {
                $strengths[$line] = $line . $remark;
                continue;
            }
            if ((string) $row['level_code'] === 'not_yet') {
                $needs[$line] = 'Poursuivre : ' . $line . $remark;
            }
        }

        $target = ($decision['decision'] ?? $decision['proposal']) === 'to_primary' ? 'primaire' : 'section supérieure';

        return [
            'path_summary' => sprintf('%s termine l\'année %s en %s. Proposition : %s.', $learnerName, $schoolYear, strtoupper((string) $decision['from_level']), $target),
            'strengths' => implode("\n", array_values($strengths)),
            'needs' => implode("\n", array_values($needs)),
        ];
    }

    /** @return callable(array<string, mixed>): string */
    private function template(string $locale): callable
    {
        $path = dirname(__DIR__, 2) . '/Documents/Templates/doc20_' . (in_array($locale, ['en'], true) ? 'en' : 'fr') . '.php';
        $template = require $path;

        return $template;
    }

    /** @return list<string> */
    private function explodeLines(string $value): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), static fn (string $line): bool => $line !== ''));

        return $lines;
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
            'school_year' => (string) $row['school_year'],
            'path_summary' => $row['path_summary'] !== null ? (string) $row['path_summary'] : null,
            'strengths' => $row['strengths'] !== null ? (string) $row['strengths'] : null,
            'needs' => $row['needs'] !== null ? (string) $row['needs'] : null,
            'status' => (string) $row['status'],
            'signed_by' => $row['signed_by'] !== null ? (string) $row['signed_by'] : null,
            'signed_at' => $row['signed_at'] !== null ? (string) $row['signed_at'] : null,
            'document_id' => $row['document_id'] !== null ? (string) $row['document_id'] : null,
            'target_establishment_id' => $row['target_establishment_id'] !== null ? (string) $row['target_establishment_id'] : null,
            'sent_at' => $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
