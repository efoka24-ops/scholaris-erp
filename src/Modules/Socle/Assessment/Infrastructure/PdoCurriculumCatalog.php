<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentClock;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumEntry;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Catalogue de programme (D9) sur les tables subjects et subject_elements. Idempotent par clé externe : une projection
 * rejouée à l'identique n'écrit rien ; un code déjà pris par une autre matière est refusé (409), jamais écrasé.
 */
final class PdoCurriculumCatalog implements CurriculumCatalog
{
    private const KIND = '/^[a-z][a-z0-9_]{1,19}$/';

    public function __construct(
        private readonly Db $db,
        private readonly CatalogRepository $catalog,
        private readonly AssessmentClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function upsertSubject(string $tenantId, string $establishmentId, CurriculumItem $subject): string
    {
        $this->assertItem($subject, true);
        $existing = $this->catalog->subjectByExternalKey($tenantId, $establishmentId, $subject->externalKey);
        $sameCode = $this->catalog->subjectByCode($tenantId, $establishmentId, $subject->code);
        if ($sameCode !== null && ($existing === null || $sameCode['id'] !== $existing['id'])) {
            throw HttpException::conflict('Le code « ' . $subject->code . ' » est déjà utilisé par une autre matière. Choisissez un autre code.');
        }
        $values = [
            'code' => $subject->code, 'name' => $subject->name, 'kind' => $subject->kind,
            'coefficient' => $subject->coefficient, 'credits' => $subject->credits, 'active' => 1,
        ];
        $now = $this->clock->now();

        if ($existing !== null) {
            $changed = $this->changes($existing, $values);
            if ($changed !== []) {
                $this->catalog->updateSubject($tenantId, (string) $existing['id'], $changed + ['updated_at' => $now]);
                $this->audit->append($tenantId, 'system', 'assessment.catalog.subject_updated', 'subject', (string) $existing['id'], null, ['external_key' => $subject->externalKey] + $changed);
            }

            return (string) $existing['id'];
        }

        $id = (string) new Ulid();
        try {
            $this->catalog->insert('subjects', $values + [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'external_key' => $subject->externalKey,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                throw HttpException::conflict('Cette matière vient d\'être enregistrée par une autre opération. Recommencez.');
            }
            throw $e;
        }
        $this->audit->append($tenantId, 'system', 'assessment.catalog.subject_created', 'subject', $id, null, ['external_key' => $subject->externalKey, 'code' => $subject->code]);

        return $id;
    }

    public function upsertElement(string $tenantId, string $establishmentId, string $subjectExternalKey, CurriculumItem $element): string
    {
        $this->assertItem($element, false);
        $subject = $this->catalog->subjectByExternalKey($tenantId, $establishmentId, $subjectExternalKey)
            ?? throw HttpException::notFound('Matière « ' . $subjectExternalKey . ' » introuvable : enregistrez l\'unité d\'enseignement avant ses éléments.');
        $existing = $this->catalog->elementByExternalKey($tenantId, $establishmentId, $element->externalKey);
        if ($existing !== null && $existing['subject_id'] !== $subject['id']) {
            throw HttpException::conflict('L\'élément « ' . $element->externalKey . ' » appartient déjà à une autre matière. Désactivez-le et créez-en un nouveau.');
        }
        $values = ['code' => $element->code, 'name' => $element->name, 'coefficient' => $element->coefficient, 'credits' => $element->credits, 'active' => 1];

        if ($existing !== null) {
            $changed = $this->changes($existing, $values);
            if ($changed !== []) {
                $this->catalog->updateElement($tenantId, (string) $existing['id'], $changed);
            }

            return (string) $existing['id'];
        }

        $id = (string) new Ulid();
        $this->catalog->insert('subject_elements', $values + [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'subject_id' => $subject['id'],
            'external_key' => $element->externalKey, 'created_at' => $this->clock->now(),
        ]);

        return $id;
    }

    public function deactivate(string $tenantId, string $establishmentId, string $externalKey): bool
    {
        $subject = $this->catalog->subjectByExternalKey($tenantId, $establishmentId, $externalKey);
        if ($subject !== null) {
            if ((int) $subject['active'] === 1) {
                $this->catalog->updateSubject($tenantId, (string) $subject['id'], ['active' => 0, 'updated_at' => $this->clock->now()]);
                $this->audit->append($tenantId, 'system', 'assessment.catalog.subject_deactivated', 'subject', (string) $subject['id'], null, ['external_key' => $externalKey]);
            }

            return true;
        }
        $element = $this->catalog->elementByExternalKey($tenantId, $establishmentId, $externalKey);
        if ($element === null) {
            return false;
        }
        if ((int) $element['active'] === 1) {
            $this->catalog->updateElement($tenantId, (string) $element['id'], ['active' => 0]);
        }

        return true;
    }

    public function find(string $tenantId, string $establishmentId, string $externalKey): ?CurriculumEntry
    {
        $subject = $this->catalog->subjectByExternalKey($tenantId, $establishmentId, $externalKey);
        if ($subject !== null) {
            return $this->entry($subject, null);
        }
        $element = $this->catalog->elementByExternalKey($tenantId, $establishmentId, $externalKey);

        return $element === null ? null : $this->entry($element, (string) $element['subject_id']);
    }

    public function elementsOf(string $tenantId, string $establishmentId, string $subjectExternalKey): array
    {
        $subject = $this->catalog->subjectByExternalKey($tenantId, $establishmentId, $subjectExternalKey);
        if ($subject === null) {
            return [];
        }

        return array_map(
            fn (array $row): CurriculumEntry => $this->entry($row, (string) $subject['id']),
            $this->catalog->elementsOf($tenantId, (string) $subject['id']),
        );
    }

    private function assertItem(CurriculumItem $item, bool $subject): void
    {
        $errors = [];
        if (preg_match('/^[A-Za-z0-9_.:\-]{1,80}$/', $item->externalKey) !== 1) {
            $errors['external_key'] = ['Clé externe invalide (1 à 80 caractères : lettres, chiffres, « _ », « . », « : », « - »).'];
        }
        if (trim($item->code) === '' || mb_strlen($item->code) > 40) {
            $errors['code'] = ['Le code est obligatoire (40 caractères au plus).'];
        }
        if (trim($item->name) === '' || mb_strlen($item->name) > 160) {
            $errors['name'] = ['L\'intitulé est obligatoire (160 caractères au plus).'];
        }
        if ($subject && preg_match(self::KIND, $item->kind) !== 1) {
            $errors['kind'] = ['Type de matière invalide (minuscules, chiffres, « _ », 20 caractères au plus).'];
        }
        if ($item->coefficient < 0 || $item->coefficient > 100 || $item->credits < 0 || $item->credits > 100) {
            $errors['credits'] = ['Coefficient et crédits doivent être compris entre 0 et 100.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, scalar> $values
     * @return array<string, scalar>
     */
    private function changes(array $row, array $values): array
    {
        $changed = [];
        foreach ($values as $column => $value) {
            $current = $row[$column] ?? null;
            $same = is_float($value) ? $current !== null && abs((float) $current - $value) < 1e-9 : (string) $current === (string) $value;
            if (!$same) {
                $changed[$column] = $value;
            }
        }

        return $changed;
    }

    /** @param array<string, mixed> $row */
    private function entry(array $row, ?string $subjectId): CurriculumEntry
    {
        return new CurriculumEntry(
            (string) $row['id'],
            (string) ($row['external_key'] ?? ''),
            $subjectId,
            (string) ($row['code'] ?? ''),
            (string) $row['name'],
            (string) ($row['kind'] ?? 'element'),
            (float) $row['coefficient'],
            (int) $row['credits'],
            (int) ($row['active'] ?? 1) === 1,
        );
    }
}
