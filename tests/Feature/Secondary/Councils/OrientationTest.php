<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Councils;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;
use Tests\Support\Secondary\SecondaryTestCase;

/** C-108 E3-D : vœux, avis, décision et recours avec données entièrement fictives. */
final class OrientationTest extends SecondaryTestCase
{
    private const YEAR = 'annee-2026';
    private const PERSON = 'eleve-orientation-test';
    private const ADVISER = 'conseiller-test';
    private const COUNCIL = 'president-test';
    private const STUDENT = 'eleve-compte-test';
    private const GUARDIAN = 'parent-compte-test';

    /** @var array<string, list<EnrollmentInfo>> */
    private array $enrollments = [];
    /** @var array<string, PersonSummary> */
    private array $persons = [];
    /** @var array<string, string> */
    private array $links = [];
    private ?int $appealDays = null;
    private mixed $appealDaysRaw = null;
    private string $classId = '';
    private string $streamId = '';

    protected function setUp(): void
    {
        $test = $this;
        $enrolments = new class ($test) implements EnrollmentDirectory {
            public function __construct(private readonly OrientationTest $test)
            {
            }
            public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
            {
                return null;
            }
            public function forPerson(string $tenantId, string $personId): array
            {
                return $this->test->enrollmentRows($personId);
            }
            public function personIdsInStructure(string $tenantId, string $structureId): array
            {
                return $this->test->peopleForClass($structureId);
            }
            public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
            {
                return [];
            }
        };
        $people = new class ($test) implements PersonDirectory {
            public function __construct(private readonly OrientationTest $test)
            {
            }
            public function find(string $tenantId, string $personId): ?PersonSummary
            {
                return $this->test->personRow($personId);
            }
            public function findMany(string $tenantId, array $personIds): array
            {
                return array_values(array_filter(array_map($this->test->personRow(...), $personIds)));
            }
            public function guarantorsOf(string $tenantId, string $learnerId): array
            {
                return [];
            }
            public function learnersOf(string $tenantId, string $guarantorId): array
            {
                return $this->test->learnersForGuardian($guarantorId);
            }
        };
        $links = new class ($test) implements UserPersonLink {
            public function __construct(private readonly OrientationTest $test)
            {
            }
            public function personIdForUser(string $tenantId, string $userId): ?string
            {
                return $this->test->linkedPerson($userId);
            }
        };
        $rules = new class ($test) implements SchoolRules {
            public function __construct(private readonly OrientationTest $test)
            {
            }
            public function value(string $tenantId, string $establishmentId, string $key, mixed $default = null): mixed
            {
                return $this->test->configuredDaysRaw() ?? $default;
            }
        };
        $this->bootSecondary([
            EnrollmentDirectory::class => $enrolments,
            PersonDirectory::class => $people,
            UserPersonLink::class => $links,
            SchoolRules::class => $rules,
        ]);
    }

    /** @return list<EnrollmentInfo> */
    public function enrollmentRows(string $personId): array
    {
        return $this->enrollments[$personId] ?? [];
    }
    public function personRow(string $personId): ?PersonSummary
    {
        return $this->persons[$personId] ?? null;
    }
    public function linkedPerson(string $userId): ?string
    {
        return $this->links[$userId] ?? null;
    }
    public function configuredDays(): ?int
    {
        return $this->appealDays;
    }
    public function configuredDaysRaw(): mixed
    {
        return $this->appealDaysRaw ?? $this->appealDays;
    }
    /** @return list<string> */
    public function peopleForClass(string $classId): array
    {
        return $classId === $this->classId ? [self::PERSON] : [];
    }
    /** @return list<PersonSummary> */
    public function learnersForGuardian(string $guardianId): array
    {
        return $guardianId === self::GUARDIAN ? [$this->persons[self::PERSON]] : [];
    }

    /** @return array{school: string, level: string} */
    private function school(): array
    {
        $school = $this->factory->opened(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $school, ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.settings.read', 'secondary.enrol.read', 'secondary.orientation.manage', 'secondary.council.prepare', 'secondary.council.preside']);
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $school), null, 'code');
        $streamRef = null;
        foreach ($this->service(\Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory::class)->forEstablishment(self::TA, $school) as $stream) {
            if ($stream->cycleCode === 'second') {
                $streamRef = $stream->id;
                break;
            }
        }
        self::assertNotNull($streamRef);
        $stream = $this->ok('POST', '/secondary/setup/streams', ['stream_ref_id' => $streamRef], self::ADMIN, self::TA, $school);
        $class = $this->ok('POST', '/secondary/setup/classes', ['label' => 'Seconde test', 'level_id' => $levels['2NDE']['id'], 'academic_year_id' => self::YEAR, 'stream_id' => $stream['id']], self::ADMIN, self::TA, $school);
        $this->classId = (string) $class['structure_id'];
        $this->streamId = (string) $stream['id'];
        $this->grant(self::TA, self::STUDENT, $school, ['secondary.self.read']);
        $this->grant(self::TA, self::GUARDIAN, $school, ['secondary.family.read']);
        $this->setUpLearner($school, $this->classId);

        return ['school' => $school, 'level' => (string) $levels['2NDE']['id']];
    }

    private function setUpLearner(string $school, string $classId): void
    {
        $this->enrollments[self::PERSON] = [new EnrollmentInfo('enrol-orientation-test', self::TA, self::PERSON, $school, $classId, self::YEAR, EnrollmentInfo::ACTIVE)];
        $this->persons[self::PERSON] = new PersonSummary(self::PERSON, self::TA, 'Aïcha', 'Essai');
        $this->links[self::STUDENT] = self::PERSON;
        $this->links[self::GUARDIAN] = self::GUARDIAN;
    }

    public function testVoeuxAvisEtDecisionConseilAvecIsolationDePermissions(): void
    {
        $s = $this->school();
        $this->grant(self::TA, self::ADVISER, $s['school'], ['secondary.orientation.manage'], Scope::ESTABLISHMENT);
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $wishes = $this->ok('PUT', '/secondary/orientation/me/wishes', ['academic_year_id' => self::YEAR, 'wishes' => [$this->streamId]], self::STUDENT, self::TA, $s['school']);
        self::assertSame([$this->streamId], $wishes['wishes']);
        self::assertSame(403, $this->call('PUT', '/secondary/orientation/students/' . self::PERSON . '/advice', ['academic_year_id' => self::YEAR, 'counsellor_opinion' => 'Avis fictif'], self::STUDENT, self::TA, $s['school'])[0]);
        $advice = $this->ok('PUT', '/secondary/orientation/students/' . self::PERSON . '/advice', ['academic_year_id' => self::YEAR, 'counsellor_opinion' => 'Avis fictif du conseiller', 'proposed_stream_id' => $this->streamId, 'interview_on' => '2026-10-20'], self::ADVISER, self::TA, $s['school']);
        self::assertSame('2026-10-20', $advice['interview_on']);
        self::assertSame(422, $this->call('PUT', '/secondary/orientation/students/' . self::PERSON . '/advice', ['counsellor_opinion' => 'Année absente'], self::ADVISER, self::TA, $s['school'])[0]);
        self::assertSame(403, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Conseil fictif'], self::ADVISER, self::TA, $s['school'])[0]);
        $decision = $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Décision fictive motivée'], self::COUNCIL, self::TA, $s['school']);
        self::assertSame($this->streamId, $decision['stream_id']);
        self::assertSame(409, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Deuxième décision fictive'], self::COUNCIL, self::TA, $s['school'])[0]);
        $this->assertSame($this->streamId, $this->pdo()->query('SELECT final_stream_id FROM se_orientations')->fetchColumn());
    }

    public function testEleveEnregistreSesPropresVoeuxSurLaRouteMe(): void
    {
        $s = $this->school();
        $wishes = $this->ok('PUT', '/secondary/orientation/me/wishes', ['academic_year_id' => self::YEAR, 'wishes' => [$this->streamId]], self::STUDENT, self::TA, $s['school']);
        self::assertSame(self::PERSON, $wishes['person_id']);
        self::assertSame([$this->streamId], $wishes['wishes']);
        self::assertSame(403, $this->call('PUT', '/secondary/orientation/students/' . self::PERSON . '/wishes', ['academic_year_id' => self::YEAR, 'wishes' => []], self::STUDENT, self::TA, $s['school'])[0]);
    }

    public function testUnParentNeLitQueLesElevesLiesEtPeutDeposerUnRecours(): void
    {
        $s = $this->school();
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Décision fictive liée'], self::COUNCIL, self::TA, $s['school']);
        $this->appealDays = 8;
        $this->appealDaysRaw = null;
        $family = $this->ok('GET', '/secondary/orientation/family?academic_year_id=' . self::YEAR, null, self::GUARDIAN, self::TA, $s['school']);
        self::assertCount(1, $family);
        self::assertSame(self::PERSON, $family[0]['person_id']);
        $ownAppeals = $this->ok('GET', '/secondary/orientation/me/appeals?academic_year_id=' . self::YEAR, null, self::GUARDIAN, self::TA, $s['school']);
        self::assertCount(0, $ownAppeals);
        self::assertSame(403, $this->call('GET', '/secondary/orientation?class_id=' . $this->classId . '&academic_year_id=' . self::YEAR, null, self::GUARDIAN, self::TA, $s['school'])[0]);
        self::assertSame(201, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/appeals', ['academic_year_id' => self::YEAR, 'reason' => 'Recours du parent lié'], self::GUARDIAN, self::TA, $s['school'])[0]);
    }

    public function testRecoursRespecteReglageObligatoireEtDelaiParametre(): void
    {
        $s = $this->school();
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $decision = $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Décision initiale fictive'], self::COUNCIL, self::TA, $s['school']);
        self::assertSame(409, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/appeals', ['academic_year_id' => self::YEAR, 'reason' => 'Motif fictif'], self::STUDENT, self::TA, $s['school'])[0], 'Aucune valeur par défaut si appel_days non renseigné.');
        $this->appealDays = 8;
        $this->appealDaysRaw = null;
        $this->pdo()->prepare('UPDATE se_orientation_decisions SET decision_on = ? WHERE id = ?')->execute(['2026-10-20', $decision['id']]);
        $appeal = $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/appeals', ['academic_year_id' => self::YEAR, 'reason' => 'Demande de réexamen fictive'], self::GUARDIAN, self::TA, $s['school']);
        self::assertSame('pending', $appeal['status']);
        $this->assertSame(409, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/appeals', ['academic_year_id' => self::YEAR, 'reason' => 'Recours en double fictif'], self::STUDENT, self::TA, $s['school'])[0]);
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $review = $this->ok('POST', '/secondary/orientation/appeals/' . $appeal['id'] . '/decide', ['outcome' => 'accepted', 'reason' => 'Révision motivée fictive', 'revised_stream_id' => $this->streamId], self::COUNCIL, self::TA, $s['school']);
        self::assertSame('accepted', $review['status']);
        self::assertSame($this->streamId, $this->pdo()->query('SELECT final_stream_id FROM se_orientations')->fetchColumn());
        self::assertSame((string) $decision['id'], $appeal['decision_id']);
    }

    public function testModuleOrientationEtIsolementEtablissement(): void
    {
        $school = $this->factory->opened(self::TA, 'ces');
        $this->grant(self::TA, self::ADMIN, $school, ['secondary.enrol.read']);
        self::assertSame(409, $this->call('GET', '/secondary/orientation?class_id=absente&academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $school)[0]);
    }

    public function testRecoursTardifEstRefuseEn422(): void
    {
        $s = $this->school();
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $decision = $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Décision initiale fictive'], self::COUNCIL, self::TA, $s['school']);
        $this->appealDays = 8;
        $this->appealDaysRaw = null;
        $this->pdo()->prepare('UPDATE se_orientation_decisions SET decision_on = ? WHERE id = ?')->execute(['2026-10-01', $decision['id']]);
        self::assertSame(422, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/appeals', ['academic_year_id' => self::YEAR, 'reason' => 'Motif déposé après délai fictif'], self::STUDENT, self::TA, $s['school'])[0]);
    }

    public function testRecoursLeDernierJourEstEncoreAccepte(): void
    {
        $s = $this->school();
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $decision = $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Décision fictive à la limite'], self::COUNCIL, self::TA, $s['school']);
        $this->appealDays = 8;
        $this->pdo()->prepare('UPDATE se_orientation_decisions SET decision_on = ? WHERE id = ?')->execute(['2026-10-15', $decision['id']]);
        self::assertSame(201, $this->call('POST', '/secondary/orientation/students/' . self::PERSON . '/appeals', ['academic_year_id' => self::YEAR, 'reason' => 'Recours au dernier jour permis'], self::STUDENT, self::TA, $s['school'])[0]);
    }

    public function testDecisionsEtRecoursSontCloisonnesParEtablissement(): void
    {
        $s = $this->school();
        $this->grant(self::TA, self::COUNCIL, $s['school'], ['secondary.council.preside'], Scope::ESTABLISHMENT);
        $decision = $this->ok('POST', '/secondary/orientation/students/' . self::PERSON . '/decisions', ['academic_year_id' => self::YEAR, 'stream_id' => $this->streamId, 'reason' => 'Décision fictive établissement A'], self::COUNCIL, self::TA, $s['school']);
        $other = $this->factory->opened(self::TB, 'leg');
        $this->grant(self::TB, self::ADMIN, $other, ['secondary.council.preside'], Scope::ESTABLISHMENT);
        self::assertSame(404, $this->call('POST', '/secondary/orientation/appeals/' . $decision['id'] . '/decide', ['outcome' => 'rejected', 'reason' => 'Test isolation fictif'], self::ADMIN, self::TB, $other)[0]);
    }
}
