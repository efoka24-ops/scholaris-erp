<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Councils;

use Scholaris\Modules\Apps\Secondary\Councils\Contracts\PromotionPlans;
use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application\SecondaryPromotionPlanner;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentTypeCatalogue;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Secondary\Fees\FakePeople;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Restes d'E3-A : passage de fin d'année du secondaire (`organisation.closure.promotion.secondary`) branché sur le conseil de fin
 * d'année (admis, non admis), l'orientation (série décidée ou recours accepté) et l'exclusion définitive ; fiche d'orientation DOC-16.
 * Données fictives.
 */
final class PromotionAndOrientationSheetTest extends SecondaryTestCase
{
    private const ADVISER = 'conseiller-a';

    private InMemoryEnrollments $enrollments;
    private FakePeople $people;
    /** @var list<array{kind: string, person: string, values: array<string, scalar|null>}> */
    public array $issued = [];
    private string $school = '';

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new FakePeople();
        $test = $this;
        $admissions = new class () implements AdmissionDirectory {
            public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array
            {
                return $academicYearId === 'ay1' ? ['eleve-1', 'eleve-2', 'eleve-4'] : [];
            }
        };
        $classes = new class () implements ClassProfiles {
            private function all(): array
            {
                return [
                    'c-3e' => new ClassProfileInfo('p1', 'c-3e', 'ay1', '3e A', 'fr', 'general', 'first', 'l3', '3E', null, null, null),
                    'c-2a' => new ClassProfileInfo('p2', 'c-2a', 'ay2', '2nde A', 'fr', 'general', 'second', 'l2', '2NDE', 's-a', null, null),
                    'c-2c' => new ClassProfileInfo('p3', 'c-2c', 'ay2', '2nde C', 'fr', 'general', 'second', 'l2', '2NDE', 's-c', null, null),
                ];
            }

            public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassProfileInfo
            {
                return $this->all()[$structureId] ?? null;
            }

            public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array
            {
                return array_values(array_filter($this->all(), static fn (ClassProfileInfo $c): bool => $c->academicYearId === $academicYearId));
            }

            public function level(string $tenantId, string $establishmentId, string $levelId): ?array
            {
                return null;
            }
        };
        $documents = new class ($test) implements SecondaryDocuments {
            public function __construct(private readonly PromotionAndOrientationSheetTest $test)
            {
            }

            public function issue(string $tenantId, string $establishmentId, string $kind, string $personId, array $values, string $issuedBy, ?string $locale = null): IssuedDocument
            {
                $this->test->issued[] = ['kind' => $kind, 'person' => $personId, 'values' => $values];

                return new IssuedDocument('doc-' . count($this->test->issued), 'O-26-' . count($this->test->issued), 'jeton', IssuedDocument::VALID, 'sec_doc16_orientation_sheet', $personId);
            }
        };
        $this->bootSecondary([
            EnrollmentDirectory::class => $this->enrollments, PersonDirectory::class => $this->people, AdmissionDirectory::class => $admissions,
            ClassProfiles::class => $classes, SecondaryDocuments::class => $documents,
        ]);
        $this->school = $this->factory->opened(self::TA, 'lycee_prive');
        foreach (['eleve-1', 'eleve-2', 'eleve-3', 'eleve-4'] as $index => $person) {
            $this->enrollments->add(self::TA, 'ins-' . $index, $person, $this->school, 'c-3e');
            $this->people->person(self::TA, $person, 'Prénom' . $index, 'Fictif');
        }
        $now = '2026-10-23T08:00:00+01:00';
        $this->pdo()->prepare('INSERT INTO pedagogical_structures (id, tenant_id, establishment_id, org_unit_id, kind, name, capacity, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, NULL, ?, ?)')
            ->execute(['c-3e', self::TA, $this->school, 'class', '3e A', $now, $now]);
        $this->pdo()->exec("INSERT INTO se_streams (id, tenant_id, establishment_id, stream_ref_id, stream_kind, teaching_kind, code, label, cycle_code, section_code, active, created_at, updated_at) VALUES
            ('s-a', '" . self::TA . "', '{$this->school}', 'r-a', 'series', 'general', 'A', 'Lettres (fictif)', 'second', 'fr', 1, '$now', '$now'),
            ('s-c', '" . self::TA . "', '{$this->school}', 'r-c', 'series', 'general', 'C', 'Sciences (fictif)', 'second', 'fr', 1, '$now', '$now')");
        $this->pdo()->exec("INSERT INTO se_orientations (id, tenant_id, establishment_id, person_id, academic_year_id, wishes, counsellor_opinion, council_opinion, proposed_stream_id, final_stream_id, interview_on, created_at, updated_at) VALUES
            ('o-2', '" . self::TA . "', '{$this->school}', 'eleve-2', 'ay1', '[\"s-c\",\"s-a\"]', 'Profil scientifique (fictif)', 'Recours accepté (fictif)', 's-c', 's-c', '2026-10-20', '$now', '$now')");
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->school, 'people.promotion.next_structure', ['c-3e' => 'c-2a'], 'test');
        $this->grant(self::TA, self::ADVISER, $this->school, ['secondary.orientation.manage']);
    }

    public function testPassageBrancheSurLeConseilLOrientationEtIdempotent(): void
    {
        $planners = $this->service(ClosureProviders::class)->promotersFor(self::TA, $this->school, 'secondary');
        self::assertCount(1, $planners, 'Le passage du secondaire remplace le passage générique.');
        self::assertInstanceOf(SecondaryPromotionPlanner::class, $planners[0]);
        self::assertSame([], array_filter($this->service(ClosureProviders::class)->promotersFor(self::TA, $this->school, 'primary'), static fn (object $p): bool => $p instanceof SecondaryPromotionPlanner));

        $result = $planners[0]->promote(self::TA, $this->school, 'ay1', 'ay2');
        self::assertSame(ClosureStepResult::DONE, $result->status);
        $plans = array_column($this->service(PromotionPlans::class)->forYear(self::TA, $this->school, 'ay1'), null, 'person_id');
        self::assertSame(['promote', 'c-2a', null, 'council'], [$plans['eleve-1']['outcome'], $plans['eleve-1']['target_class_id'], $plans['eleve-1']['stream_id'], $plans['eleve-1']['basis']]);
        self::assertSame(['promote', 'c-2c', 's-c', 'orientation'], [$plans['eleve-2']['outcome'], $plans['eleve-2']['target_class_id'], $plans['eleve-2']['stream_id'], $plans['eleve-2']['basis']], 'Série décidée : classe du même niveau qui la porte.');
        self::assertSame(['repeat', 'c-3e'], [$plans['eleve-3']['outcome'], $plans['eleve-3']['target_class_id']]);

        $this->pdo()->exec("UPDATE se_promotion_plans SET plan_status = 'applied' WHERE person_id = 'eleve-1'");
        $again = $planners[0]->promote(self::TA, $this->school, 'ay1', 'ay2');
        self::assertSame(1, $again->counts['already_applied'] ?? null);
        self::assertSame(4, (int) $this->pdo()->query('SELECT COUNT(*) FROM se_promotion_plans')->fetchColumn(), 'Une ligne par élève.');

        $this->applications->set(self::TA, 'suspended');
        self::assertSame(ClosureStepResult::SKIPPED, $planners[0]->promote(self::TA, $this->school, 'ay1', 'ay2')->status);
    }

    public function testFicheDOrientationDoc16(): void
    {
        self::assertNotNull($this->service(DocumentTypeCatalogue::class)->find('orientation_sheet'), 'DOC-16 au catalogue du secondaire.');
        $sheet = $this->ok('POST', '/secondary/orientation/students/eleve-2/sheet', ['academic_year_id' => 'ay1', 'locale' => 'fr'], self::ADVISER, self::TA, $this->school);
        self::assertSame('doc-1', $sheet['document_id']);
        $values = $this->issued[0]['values'];
        self::assertSame(['orientation_sheet', 'eleve-2'], [$this->issued[0]['kind'], $this->issued[0]['person']]);
        self::assertSame("1. C Sciences (fictif)\n2. A Lettres (fictif)", $values['wishes_block']);
        self::assertSame(['C Sciences (fictif)', 'Recours accepté (fictif)', '2026-10-20'], [$values['decision_label'], $values['decision_reason'], $values['interview_on']]);
        self::assertContains('secondary.orientation.sheet.issued', $this->audit->actions());

        $this->grant(self::TA, 'eleve-compte', $this->school, ['secondary.self.read']);
        self::assertSame(403, $this->call('POST', '/secondary/orientation/students/eleve-2/sheet', ['academic_year_id' => 'ay1'], 'eleve-compte', self::TA, $this->school)[0]);
        self::assertSame(404, $this->call('POST', '/secondary/orientation/students/inconnu/sheet', ['academic_year_id' => 'ay1'], self::ADVISER, self::TA, $this->school)[0]);
    }
}
