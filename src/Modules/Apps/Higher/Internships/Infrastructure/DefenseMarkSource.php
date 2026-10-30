<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkOverlaySource;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSheet;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Internships\Application\ConventionService;
use Scholaris\Modules\Apps\Higher\Internships\Application\DefenseService;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Note de soutenance offerte à la délibération : ajoutée comme épreuve d'examen (sur 20, poids 1) à l'EC « stage/mémoire » de la
 * maquette publiée du groupe, seulement quand le PV de soutenance est établi (soutenance clôturée). L'EC est désigné par le
 * paramètre d'école `higher.defense.element_code` (code d'EC de la maquette). Valeur par défaut : vide, « à confirmer » avec la
 * tutelle (decisions R35) ; tant qu'il est vide, la soutenance n'entre pas dans le calcul du jury. Soutenance reprise : la plus
 * récente compte. Source complémentaire : elle ne crée jamais de ligne pour un étudiant sans autre note.
 */
final class DefenseMarkSource implements MarkOverlaySource
{
    public const ELEMENT_KEY = 'higher.defense.element_code';

    public function __construct(
        private readonly InternshipStore $store,
        private readonly AcademicDirectory $academic,
        private readonly CurriculumDirectory $curricula,
        private readonly StudentDirectory $students,
        private readonly OrgUnitSettingsReader $settings,
    ) {
    }

    public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
    {
        if (preg_match('/^S(\d{1,2})$/i', $periodId, $m) !== 1) {
            return null;
        }
        $semester = (int) $m[1];
        $group = $this->academic->group($tenantId, $establishmentId, $structureId);
        if ($group === null) {
            return null;
        }
        $closed = [];
        foreach ($this->store->select(DefenseService::TABLE, $tenantId, $establishmentId, ['level_id' => $group->levelId, 'status' => DefenseService::CLOSED], 'held_on', 5000) as $defense) {
            $internship = $this->store->find(ConventionService::TABLE, $tenantId, $establishmentId, (string) $defense['internship_id']);
            if ($internship !== null) {
                $closed[] = [$defense, (string) $internship['academic_year_id']];
            }
        }
        $years = array_map(static fn (array $c): string => $c[1], $closed);
        rsort($years);
        $year = $academicYearId ?? ($years[0] ?? null);
        if ($year === null) {
            return null;
        }
        $schoolId = $closed === [] ? null : (string) $closed[0][0]['school_id'];
        $code = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, self::ELEMENT_KEY, '');
        $curriculum = is_string($code) && $code !== '' ? $this->curricula->publishedFor($tenantId, $establishmentId, $group->programId, $group->levelId, $year) : null;
        if ($curriculum === null) {
            return null;
        }
        $members = [];
        foreach ($this->students->enrolledIn($tenantId, $establishmentId, $structureId, $year) as $enrolment) {
            $members[$enrolment->personId] = true;
        }
        $grades = [];
        foreach ($closed as [$defense, $defenseYear]) {
            if ($defenseYear === $year && isset($members[(string) $defense['person_id']])) {
                $grades[(string) $defense['person_id']] = ((int) $defense['grade_hundredths']) / 100;
            }
        }
        $units = [];
        foreach ($this->curricula->units($tenantId, $establishmentId, $curriculum->id) as $unit) {
            foreach ($unit->elements as $element) {
                if ($unit->semesterOrdinal !== $semester || $element['code'] !== $code) {
                    continue;
                }
                foreach ($grades as $person => $grade) {
                    $units[$person][] = [
                        'subject_id' => $unit->id, 'code' => $unit->code, 'name' => $unit->title, 'kind' => $unit->unitType,
                        'coefficient' => $unit->coefficient, 'credits' => $unit->credits,
                        'groups' => [[
                            'element_id' => $element['id'], 'code' => $element['code'], 'coefficient' => $element['coefficient'], 'credits' => $element['credits'],
                            'entries' => [['value' => $grade, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'exam', 'session' => 'normal']],
                        ]],
                    ];
                }
            }
        }

        return $units === [] ? null : new MarkSheet($units);
    }
}
