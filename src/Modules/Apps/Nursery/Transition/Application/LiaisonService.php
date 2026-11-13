<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Transition\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\Nursery\Development\Application\TermsGuard;
use Scholaris\Modules\Apps\Nursery\Documents\Application\NurseryDocuments;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;

/**
 * Fiche de liaison vers l'école primaire (EX-MAT-22, MQ-24, DOC-MAT-02) : préparée par l'éducatrice pour un enfant proposé ou décidé
 * « primaire », signée par la direction puis émise par le module Documents (numéro, QR, contenu figé) ; l'école primaire la vérifie
 * par le QR. Points d'appui : seuls les items dont le dernier niveau observé figure dans `nursery.transition.strength_levels`.
 * MINIMISATION : aucune restriction de garde, aucune allergie, aucun besoin particulier n'y est copié ; les termes à éviter bloquent.
 */
final class LiaisonService
{
    public const TABLE = 'nu_liaison_sheets';

    public function __construct(
        private readonly Store $store,
        private readonly TransitionService $transitions,
        private readonly ObservationService $observations,
        private readonly ChildDirectory $children,
        private readonly SectionService $sections,
        private readonly NurseryDocuments $documents,
        private readonly TermsGuard $terms,
        private readonly CurrentPeriod $periods,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> brouillon prérempli (points d'appui tirés des observations) */
    public function create(Principal $principal, Perimeter $perimeter, string $learnerId): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $enrollment = $this->children->currentEnrollment($tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');
        $section = (string) $enrollment->structureId;
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $decision = $this->transitions->forLearner($tenantId, $establishmentId, $learnerId, $enrollment->academicYearId);
        $goesToPrimary = $decision !== null && (($decision['decision'] ?? null) === TransitionService::TO_PRIMARY || ($decision['decision'] === null && $decision['proposal'] === TransitionService::TO_PRIMARY));
        if (!$goesToPrimary) {
            throw HttpException::conflict('La fiche de liaison concerne un enfant proposé ou décidé pour le primaire : préparez d’abord la décision de passage.');
        }
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'academic_year_id' => $enrollment->academicYearId]) > 0) {
            throw HttpException::conflict('Une fiche de liaison existe déjà pour cet enfant cette année.');
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $label = (string) $this->sections->findRaw($tenantId, $establishmentId, $section)['label'];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'academic_year_id' => $enrollment->academicYearId, 'structure_id' => $section,
            'path_summary' => 'Section ' . $label . '.', 'strengths' => $this->strengths($tenantId, $establishmentId, $learnerId), 'needs' => null, 'destination' => null,
            'status' => 'draft', 'document_id' => null, 'signed_by' => null, 'signed_at' => null, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.liaison.created', 'liaison_sheet', $id, null, ['learner_id' => $learnerId]);

        return $this->get($perimeter, $id);
    }

    /**
     * @param array<string, mixed> $changes path_summary?, strengths?, needs?, destination?
     * @return array<string, mixed>
     */
    public function update(Principal $principal, Perimeter $perimeter, string $id, array $changes): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if ((string) $row['status'] !== 'draft') {
            throw HttpException::conflict('Une fiche signée est figée.');
        }
        $values = array_intersect_key($changes, array_flip(['path_summary', 'strengths', 'needs', 'destination']));
        foreach (['path_summary', 'strengths', 'needs'] as $field) {
            if (isset($values[$field])) {
                $this->terms->assertClean($perimeter->tenantId, $perimeter->establishmentId, $field, (string) $values[$field]);
            }
        }
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $values + ['updated_at' => $this->clock->now()], ['id' => $id]);

        return $this->get($perimeter, $id);
    }

    /** @return array<string, mixed> signature de la direction et émission du document (une seule fois) */
    public function sign(Principal $principal, Perimeter $perimeter, string $id, string $locale): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if ((string) $row['status'] !== 'draft') {
            throw HttpException::conflict('Fiche déjà signée.');
        }
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $text = trim((string) $row['path_summary'] . ' ' . (string) $row['strengths'] . ' ' . (string) $row['needs']);
        $this->terms->assertClean($tenantId, $establishmentId, 'content', $text);
        $label = (string) $this->sections->findRaw($tenantId, $establishmentId, (string) $row['structure_id'])['label'];
        $year = (string) $row['academic_year_id'];
        foreach ($this->periods->allAt($tenantId, $establishmentId, $this->clock->today()) as $period) {
            if ($period->academicYearId === $year && $period->academicYearLabel !== null) {
                $year = $period->academicYearLabel;
                break;
            }
        }
        $document = $this->documents->issue($principal, $establishmentId, NurseryDocuments::LIAISON_SHEET, $id, (string) $row['learner_id'], (string) $row['learner_id'], [
            'academic_year' => $year, 'section_label' => $label, 'path_summary' => (string) $row['path_summary'], 'strengths' => (string) $row['strengths'],
            'needs' => (string) $row['needs'], 'destination' => (string) $row['destination'],
        ], $locale);
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => 'signed', 'document_id' => (string) $document['document_id'], 'signed_by' => $principal->userId, 'signed_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $this->access->audit($principal, 'nursery.liaison.signed', 'liaison_sheet', $id, null, ['document_id' => $document['document_id']]);

        return $this->get($perimeter, $id) + ['document' => $document];
    }

    /** @return array<string, mixed> */
    public function get(Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Fiche introuvable.');
        $this->access->assertReadable($perimeter, (string) $row['structure_id']);

        return self::resource($row);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function page(Perimeter $perimeter, Paging $paging, ?string $sectionId): array
    {
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $sectionId !== null ? ['structure_id' => $sectionId] : [], 'created_at DESC, id', 5000) as $row) {
            if ($perimeter->coversSection((string) $row['structure_id'])) {
                $rows[] = self::resource($row);
            }
        }

        return $paging->slice($rows);
    }

    private function strengths(string $tenantId, string $establishmentId, string $learnerId): string
    {
        $levels = $this->settings->list($tenantId, $establishmentId, NurserySettings::TRANSITION_STRENGTH_LEVELS);
        $labels = [];
        foreach ($this->store->select('nu_dev_items', $tenantId, $establishmentId, [], 'sort_order, id', 5000) as $item) {
            $labels[(string) $item['id']] = (string) $item['label_fr'];
        }
        $lines = [];
        foreach ($this->observations->latestByItem($tenantId, $establishmentId, $learnerId, '0000-01-01', $this->clock->today()) as $row) {
            if (in_array($row['level_code'], $levels, true)) {
                $lines[] = $labels[$row['item_id']] ?? $row['item_id'];
            }
        }

        return mb_substr(implode("\n", $lines), 0, 2000);
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Fiche introuvable.');
        $this->access->assertWritable($principal, $perimeter, (string) $row['structure_id'], 'liaison_sheet', $id);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function resource(array $row): array
    {
        $text = static fn (string $k): ?string => $row[$k] !== null ? (string) $row[$k] : null;

        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'academic_year_id' => (string) $row['academic_year_id'], 'section_id' => (string) $row['structure_id'],
            'path_summary' => $text('path_summary'), 'strengths' => $text('strengths'), 'needs' => $text('needs'), 'destination' => $text('destination'),
            'status' => (string) $row['status'], 'document_id' => $text('document_id'), 'signed_at' => $text('signed_at'),
        ];
    }
}
