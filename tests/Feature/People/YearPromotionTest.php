<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Application\EnrollmentService;
use Scholaris\Modules\Socle\People\Application\YearPromotion;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;
use Tests\Support\People\PeopleTestCase;

/** Passage en classe supérieure à la clôture d'une année : admis seulement, classe supérieure paramétrée, idempotent. */
final class YearPromotionTest extends PeopleTestCase
{
    /** @var list<string> */
    private array $admitted = [];

    /** @var array<string, string> */
    private array $nextClass = ['s1' => 's2', 's3' => 's4'];

    /** @var array<string, string> */
    private array $learners = [];

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->structures->open = ['s1', 's2'];
        foreach (['ada' => 'Ada', 'boris' => 'Boris', 'chloe' => 'Chloé', 'dani' => 'Dani', 'eve' => 'Eve', 'frank' => 'Frank'] as $key => $first) {
            $this->learners[$key] = (string) $this->person($first, 'Test', ['birth_date' => '2000-01-01'])['id'];
        }
        $this->enrol('ada', 's1');
        $this->enrol('boris', 's1');
        $this->enrol('chloe', 's1');
        $this->enrol('dani', 's3');
        $this->enrol('eve', 's1', 'withdrawn');
        $this->enrol('frank', 's9');
        $this->admitted = [$this->learners['ada'], $this->learners['boris'], $this->learners['dani'], $this->learners['eve'], $this->learners['frank']];
    }

    private function enrol(string $learner, string $structure, string $status = 'active', string $year = 'y2025', string $establishment = 'e1', string $tenant = 't1'): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)',
        )->execute([(string) new Ulid(), $tenant, $this->learners[$learner], $establishment, $structure, $year, $status, '2025-09-01T08:00:00+01:00', '2025-09-01T08:00:00+01:00']);
    }

    private function planner(): YearPromotion
    {
        $admitted = &$this->admitted;
        $next = &$this->nextClass;
        $container = $this->kernel->container();

        return new YearPromotion(
            $container->get(PdoStore::class),
            $container->get(EnrollmentService::class),
            new class ($admitted) implements AdmissionDirectory {
                /** @param list<string> $ids */
                public function __construct(private array &$ids)
                {
                }

                public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array
                {
                    return $tenantId === 't1' && $establishmentId === 'e1' && $academicYearId === 'y2025' ? $this->ids : [];
                }
            },
            new class ($next) implements SettingsReader {
                /** @param array<string, string> $map */
                public function __construct(private array &$map)
                {
                }

                public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
                {
                    return $key === 'people.promotion.next_structure' ? $this->map : $default;
                }
            },
        );
    }

    /** @return list<array<string, mixed>> */
    private function nextYear(): array
    {
        return $this->connections()->pdo()->query("SELECT * FROM enrollments WHERE academic_year_id = 'y2026' ORDER BY person_id")->fetchAll();
    }

    public function testLeFournisseurEstDecouvertParLOrganisation(): void
    {
        $promoters = array_map(static fn (object $p): string => $p::class, $this->kernel->container()->get(ClosureProviders::class)->promoters());

        self::assertContains(YearPromotion::class, $promoters);
    }

    public function testSeulsLesAdmisAvecUneClasseSuperieureSontPromusEnBrouillon(): void
    {
        $result = $this->planner()->promote('t1', 'e1', 'y2025', 'y2026');

        self::assertSame(ClosureStepResult::DONE, $result->status);
        self::assertSame(['promoted' => 2, 'repeating' => 1, 'no_next_class' => 1, 'already_enrolled' => 0, 'blocked' => 1], $result->counts);
        $rows = $this->nextYear();
        self::assertCount(2, $rows);
        $byPerson = array_column($rows, null, 'person_id');
        self::assertSame(['s2', 'draft', 'e1', 't1'], [$byPerson[$this->learners['ada']]['structure_id'], $byPerson[$this->learners['ada']]['status'], $byPerson[$this->learners['ada']]['establishment_id'], $byPerson[$this->learners['ada']]['tenant_id']]);
        self::assertArrayHasKey($this->learners['boris'], $byPerson);
        self::assertStringContainsString('2 apprenant(s) promu(s)', $result->summary);
        self::assertStringContainsString('1 non admis', $result->summary);
    }

    public function testRejouerNeCreeRien(): void
    {
        $this->planner()->promote('t1', 'e1', 'y2025', 'y2026');

        $again = $this->planner()->promote('t1', 'e1', 'y2025', 'y2026');

        self::assertSame(0, $again->counts['promoted']);
        self::assertSame(2, $again->counts['already_enrolled']);
        self::assertCount(2, $this->nextYear());
    }

    public function testRienNEstPromuSansClasseSuperieureParametree(): void
    {
        $this->nextClass = [];

        $result = $this->planner()->promote('t1', 'e1', 'y2025', 'y2026');

        self::assertSame(0, $result->counts['promoted']);
        self::assertSame(5, $result->counts['no_next_class'] + $result->counts['repeating']);
        self::assertSame([], $this->nextYear());
    }

    public function testAutreEtablissementEtAutreClientNeSontPasTouches(): void
    {
        $this->planner()->promote('t1', 'e2', 'y2025', 'y2026');
        $this->planner()->promote('t2', 'e1', 'y2025', 'y2026');

        self::assertSame([], $this->nextYear());
    }
}
