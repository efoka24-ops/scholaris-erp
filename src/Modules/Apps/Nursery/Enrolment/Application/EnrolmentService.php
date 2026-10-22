<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;

/**
 * Inscription d'un enfant en section (EX-MAT-05, 06) : fiche, parents, inscription de l'année en cours, allergies déclarées et
 * accord photo. Tout passe par les contrats du socle (LearnerWriter : doublons, garant d'un mineur, gardes d'inscription). L'âge
 * minimal et l'effectif maximal ne bloquent pas : ils produisent des AVERTISSEMENTS (valeurs lues de /settings et de la classe).
 */
final class EnrolmentService
{
    public const GUARDIAN_KINDS = ['financial_guarantor', 'guardian', 'parent', 'emergency'];

    public function __construct(
        private readonly LearnerWriter $learners,
        private readonly SectionService $sections,
        private readonly ChildDirectory $children,
        private readonly CurrentPeriod $periods,
        private readonly OrgUnitTree $tree,
        private readonly FoodAllergies $allergies,
        private readonly ConsentRecorder $consents,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data first_name, last_name, birth_date, gender?, section_id, guardians[], allergies?, photo_consent?, confirm_different?
     * @return array<string, mixed>
     */
    public function enrol(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $principal->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $sectionId = (string) $data['section_id'];
        if (!$this->sections->exists($tenantId, $establishmentId, $sectionId)) {
            throw HttpException::validation(['section_id' => ['Section introuvable dans cet établissement.']]);
        }
        $this->access->assertWritable($principal, $perimeter, $sectionId, 'section', $sectionId);
        $today = $this->clock->today();
        $year = $this->periods->at($tenantId, $establishmentId, $today)?->academicYearId;
        if ($year === null) {
            throw HttpException::validation(['academic_year' => ['Aucune année scolaire en cours : créez l’année et ses trimestres avant d’inscrire.']]);
        }
        $draft = ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'birth_date' => $data['birth_date']];
        foreach (['gender', 'birth_place', 'nationality'] as $optional) {
            if (isset($data[$optional])) {
                $draft[$optional] = $data[$optional];
            }
        }
        /** @var list<array{kind: string, person_id?: string, identity?: array<string, mixed>}> $guardians */
        $guardians = $data['guardians'];
        $created = $this->learners->createLearner($tenantId, $draft, $guardians, $principal->userId, ($data['confirm_different'] ?? false) === true);
        $childId = $created['person_id'];
        $enrollmentId = $this->learners->enroll($tenantId, $establishmentId, $childId, $sectionId, $year, $today, $principal->userId);

        $allergy = isset($data['allergies']) ? trim((string) $data['allergies']) : '';
        if ($allergy !== '') {
            $this->allergies->declare($tenantId, $childId, $allergy, $principal->userId);
        }
        if (($data['photo_consent'] ?? false) === true) {
            $this->consents->grant($tenantId, $childId, ConsentChecker::PHOTO, $principal->userId, isset($data['consent_evidence']) ? (string) $data['consent_evidence'] : null);
        }
        $this->access->audit($principal, 'nursery.child.enrolled', 'enrollment', $enrollmentId, null, ['child_id' => $childId, 'section_id' => $sectionId]);

        return [
            'child_id' => $childId,
            'enrollment_id' => $enrollmentId,
            'section_id' => $sectionId,
            'academic_year_id' => $year,
            'guardian_ids' => $created['guardian_ids'],
            'warnings' => $this->warnings($tenantId, $establishmentId, $childId, $sectionId),
        ];
    }

    /** @return list<array{code: string, detail: string}> */
    private function warnings(string $tenantId, string $establishmentId, string $childId, string $sectionId): array
    {
        $warnings = [];
        $minimum = $this->settings->int($tenantId, $establishmentId, NurserySettings::ENROL_MIN_AGE_MONTHS);
        $age = $this->children->ageMonths($tenantId, $childId);
        if ($minimum !== null && $age !== null && $age < $minimum) {
            $warnings[] = ['code' => 'below_minimum_age', 'detail' => sprintf('Âge de %d mois, inférieur au minimum de l’école (%d mois).', $age, $minimum)];
        }
        $capacity = $this->tree->structure($tenantId, $establishmentId, $sectionId)?->capacity;
        if ($capacity !== null && count($this->sections->childIds($tenantId, $sectionId)) > $capacity) {
            $warnings[] = ['code' => 'over_capacity', 'detail' => sprintf('La section dépasse son effectif maximal (%d).', $capacity)];
        }

        return $warnings;
    }
}
