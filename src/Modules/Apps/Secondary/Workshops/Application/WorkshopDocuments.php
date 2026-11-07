<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Documents de l'enseignement technique émis par Workshops, par le contrat SecondaryDocuments (E3-F) qui exige le module et
 * l'enseignement technique du profil, aux moments du parcours :
 *  - DOC-21 convention de stage : quand le stage est préparé (entreprise, tuteur, période), avant son début ;
 *  - DOC-22 fiche d'appréciation du stagiaire : une fois l'appréciation du tuteur saisie ;
 *  - DOC-23 fiche de travaux pratiques et grille : remise à un élève de la classe pour une fiche de l'atelier ;
 *  - DOC-24 état du stock de matière d'œuvre : établi par le chef des travaux, avec le coût par élève des séances.
 */
final class WorkshopDocuments
{
    public const INTERNSHIP_AGREEMENT = 'internship_agreement';
    public const TRAINEE_APPRAISAL = 'trainee_appraisal';
    public const WORKSHOP_SHEET = 'workshop_sheet';
    public const MATERIAL_STOCK = 'material_stock';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SecondaryClock $clock,
        private readonly SecondaryDocuments $documents,
        private readonly ClassProfiles $classes,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    /** @return array<string, mixed> */
    public function agreement(Principal $principal, string $establishmentId, Perimeter $perimeter, string $internshipId, ?string $locale): array
    {
        $internship = $this->store->findOrFail(InternshipService::INTERNSHIPS, $principal->tenantId, $establishmentId, $internshipId, 'Stage introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $internship['structure_id'], 'internship', $internshipId);
        if (in_array($internship['status'], ['completed', 'cancelled'], true)) {
            throw HttpException::conflict('La convention se prépare avant la fin du stage.');
        }
        $company = $this->store->findOrFail(InternshipService::COMPANIES, $principal->tenantId, $establishmentId, (string) $internship['company_id'], 'Entreprise introuvable.');
        $class = $this->class($principal->tenantId, $establishmentId, (string) $internship['structure_id']);
        $document = $this->documents->issue($principal->tenantId, $establishmentId, self::INTERNSHIP_AGREEMENT, (string) $internship['person_id'], [
            'academic_year' => $class->academicYearId, 'class_name' => $class->label, 'company_name' => (string) $company['label'],
            'company_address' => (string) ($company['address'] ?? '') !== '' ? (string) $company['address'] : '-', 'tutor_name' => (string) $internship['tutor_name'],
            'starts_on' => (string) $internship['starts_on'], 'ends_on' => (string) $internship['ends_on'], 'activities_block' => '-',
        ], $principal->userId, $locale);
        $this->store->update(InternshipService::INTERNSHIPS, $principal->tenantId, $establishmentId, ['agreement_document_id' => $document->id, 'updated_at' => $this->clock->now()], ['id' => $internshipId]);
        $this->access->audit($principal, 'secondary.internship.agreement.issued', 'internship', $internshipId, null, ['document_id' => $document->id, 'number' => $document->number]);

        return ['internship_id' => $internshipId, 'document_id' => $document->id, 'number' => $document->number, 'type' => $document->type];
    }

    /** @return array<string, mixed> */
    public function appraisal(Principal $principal, string $establishmentId, Perimeter $perimeter, string $internshipId, ?string $locale): array
    {
        $internship = $this->store->findOrFail(InternshipService::INTERNSHIPS, $principal->tenantId, $establishmentId, $internshipId, 'Stage introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $internship['structure_id'], 'internship', $internshipId);
        if (!is_string($internship['tutor_appraisal']) || trim($internship['tutor_appraisal']) === '') {
            throw HttpException::validation(['tutor_appraisal' => ['Saisissez d’abord l’appréciation du tuteur.']]);
        }
        $company = $this->store->findOrFail(InternshipService::COMPANIES, $principal->tenantId, $establishmentId, (string) $internship['company_id'], 'Entreprise introuvable.');
        $class = $this->class($principal->tenantId, $establishmentId, (string) $internship['structure_id']);
        $document = $this->documents->issue($principal->tenantId, $establishmentId, self::TRAINEE_APPRAISAL, (string) $internship['person_id'], [
            'academic_year' => $class->academicYearId, 'class_name' => $class->label, 'company_name' => (string) $company['label'],
            'tutor_name' => (string) $internship['tutor_name'], 'starts_on' => (string) $internship['starts_on'], 'ends_on' => (string) $internship['ends_on'],
            'appraisal_block' => (string) $internship['tutor_appraisal'],
        ], $principal->userId, $locale);
        $this->access->audit($principal, 'secondary.internship.appraisal.issued', 'internship', $internshipId, null, ['document_id' => $document->id, 'number' => $document->number]);

        return ['internship_id' => $internshipId, 'document_id' => $document->id, 'number' => $document->number, 'type' => $document->type];
    }

    /** @return array<string, mixed> */
    public function sheet(Principal $principal, string $establishmentId, Perimeter $perimeter, string $sheetId, string $structureId, string $personId, ?string $locale): array
    {
        $sheet = $this->store->findOrFail(WorkshopService::SHEETS, $principal->tenantId, $establishmentId, $sheetId, 'Fiche pratique introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, $structureId, 'practical_sheet', $sheetId);
        $class = $this->class($principal->tenantId, $establishmentId, $structureId);
        if (!$this->enrolled($principal->tenantId, $establishmentId, $class, $personId)) {
            throw HttpException::validation(['person_id' => ['Élève non inscrit dans cette classe.']]);
        }
        $workshop = $this->store->findOrFail(WorkshopService::WORKSHOPS, $principal->tenantId, $establishmentId, (string) $sheet['workshop_id'], 'Atelier introuvable.');
        $criteria = array_map(static fn (array $c): string => sprintf('%s : %s point(s)', (string) ($c['label'] ?? $c['code'] ?? ''), (string) ($c['points'] ?? '')), self::rows((string) $sheet['criteria']));
        $criteria[] = sprintf('Base : %s', (string) $sheet['base_score']);
        $document = $this->documents->issue($principal->tenantId, $establishmentId, self::WORKSHOP_SHEET, $personId, [
            'academic_year' => $class->academicYearId, 'class_name' => $class->label, 'workshop_name' => (string) $workshop['label'],
            'sheet_title' => (string) $sheet['title'] . ' (v' . (string) $sheet['version_no'] . ')', 'objectives_block' => (string) $sheet['objective'],
            'materials_block' => self::block(array_merge(self::strings((string) $sheet['materials']), self::strings((string) $sheet['tools']))),
            'safety_block' => self::block(self::strings((string) $sheet['safety_rules'])), 'criteria_block' => self::block($criteria),
        ], $principal->userId, $locale);
        $this->access->audit($principal, 'secondary.workshop.sheet.issued', 'practical_sheet', $sheetId, null, ['document_id' => $document->id, 'person_id' => $personId]);

        return ['sheet_id' => $sheetId, 'person_id' => $personId, 'document_id' => $document->id, 'number' => $document->number, 'type' => $document->type];
    }

    /**
     * Coût par élève : coût des sorties de stock rattachées à des séances de l'atelier, divisé par le nombre d'élèves distincts de
     * ces séances (arrondi au franc). Aucun frais n'est demandé aux familles (RG-ATL-2).
     *
     * @return array<string, mixed>
     */
    public function stock(Principal $principal, string $establishmentId, Perimeter $perimeter, string $workshopId, ?string $personId, ?string $locale): array
    {
        $this->access->assertWhole($principal, $perimeter, 'material_stock');
        $workshop = $this->store->findOrFail(WorkshopService::WORKSHOPS, $principal->tenantId, $establishmentId, $workshopId, 'Atelier introuvable.');
        $holder = $personId ?? (is_string($workshop['chief_person_id']) && $workshop['chief_person_id'] !== '' ? $workshop['chief_person_id'] : null);
        if ($holder === null) {
            throw HttpException::validation(['person_id' => ['Indiquez le chef des travaux qui établit l’état (ou renseignez le chef de l’atelier).']]);
        }
        $items = $this->store->select(WorkshopService::ITEMS, $principal->tenantId, $establishmentId, ['workshop_id' => $workshopId], 'label', 500);
        $lines = [];
        $cost = 0;
        foreach ($items as $item) {
            $lines[] = sprintf('%s : %s %s × %s FCFA', (string) $item['label'], (string) (float) $item['stock_qty'], (string) $item['unit_label'], number_format((int) $item['unit_cost'], 0, ',', ' '));
            foreach ($this->store->select(WorkshopService::MOVES, $principal->tenantId, $establishmentId, ['item_id' => (string) $item['id'], 'move_kind' => 'out'], 'created_at', 10000) as $move) {
                if (is_string($move['session_id']) && $move['session_id'] !== '') {
                    $cost += (int) round((float) $move['quantity'] * (int) $move['unit_cost']);
                }
            }
        }
        $learners = [];
        foreach ($this->store->select(WorkshopService::SESSIONS, $principal->tenantId, $establishmentId, ['workshop_id' => $workshopId], 'session_date', 10000) as $session) {
            foreach (self::rows((string) $session['attendance']) as $entry) {
                $learners[(string) ($entry['person_id'] ?? '')] = true;
            }
        }
        $perLearner = $learners === [] ? null : (int) round($cost / count($learners));
        $document = $this->documents->issue($principal->tenantId, $establishmentId, self::MATERIAL_STOCK, $holder, [
            'academic_year' => '-', 'workshop_name' => (string) $workshop['label'], 'as_of' => $this->clock->today(),
            'stock_block' => self::block($lines), 'cost_per_student' => $perLearner === null ? '-' : number_format($perLearner, 0, ',', ' ') . ' FCFA',
        ], $principal->userId, $locale);
        $this->access->audit($principal, 'secondary.workshop.stock.issued', 'workshop', $workshopId, null, ['document_id' => $document->id, 'cost_per_learner_xaf' => $perLearner]);

        return ['workshop_id' => $workshopId, 'document_id' => $document->id, 'number' => $document->number, 'type' => $document->type, 'cost_per_learner_xaf' => $perLearner];
    }

    private function class(string $tenantId, string $establishmentId, string $structureId): ClassProfileInfo
    {
        return $this->classes->find($tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Classe introuvable dans cet établissement.');
    }

    private function enrolled(string $tenantId, string $establishmentId, ClassProfileInfo $class, string $personId): bool
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId === $class->structureId
                && $enrollment->academicYearId === $class->academicYearId && $enrollment->status === EnrollmentInfo::ACTIVE) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $lines */
    private static function block(array $lines): string
    {
        return $lines === [] ? '-' : implode("\n", $lines);
    }

    /** @return list<string> */
    private static function strings(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
