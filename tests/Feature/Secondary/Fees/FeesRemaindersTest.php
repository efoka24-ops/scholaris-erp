<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Fees;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FeeNotices;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamCandidateDirectory;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Secondary\Fees\FakeLedger;
use Tests\Support\Secondary\Fees\FakePeople;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Restes du lot E1 (E4-B) : situation financière par classe, niveau et établissement avec prévisions (EX-FIN-07), fichier des conseils
 * de fin d'année pour cartescolaire.cm (DOC-33, API-04) et relances NOT-07, NOT-08, NOT-10 sans doublon ni double SMS avec le socle.
 * Horloge du secondaire figée au 2026-10-23. Données fictives.
 */
final class FeesRemaindersTest extends SecondaryTestCase
{
    private const BURSAR = 'intendant-a';
    private const REGISTRAR = 'scolarite-a';

    private InMemoryEnrollments $enrollments;
    private FakeLedger $ledger;
    private FakePeople $people;
    private RecordingMessenger $messenger;
    /** @var array<string, bool> */
    private array $examFeesSeen = [];
    private string $school = '';

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->ledger = new FakeLedger();
        $this->people = new FakePeople();
        $this->messenger = new RecordingMessenger();
        $test = $this;
        $classes = new class ($test) implements ClassProfiles {
            public function __construct(private readonly FeesRemaindersTest $test)
            {
            }

            public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassProfileInfo
            {
                return null;
            }

            public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array
            {
                if ($establishmentId !== $this->test->schoolId() || $academicYearId !== 'ay1') {
                    return [];
                }

                return [
                    new ClassProfileInfo('cp1', 'c-6a', 'ay1', '6e A', 'fr', 'general', 'first', 'l6', '6E', null, null, null),
                    new ClassProfileInfo('cp2', 'c-6b', 'ay1', '6e B', 'fr', 'general', 'first', 'l6', '6E', null, null, null),
                    new ClassProfileInfo('cp3', 'c-3a', 'ay1', '3e A', 'fr', 'general', 'first', 'l3', '3E', null, 'BEPC', null),
                ];
            }

            public function level(string $tenantId, string $establishmentId, string $levelId): ?array
            {
                return null;
            }
        };
        $periods = new class () implements PeriodTree {
            public function find(string $tenantId, string $establishmentId, string $periodId): ?PeriodInfo
            {
                return null;
            }

            public function ofYear(string $tenantId, string $establishmentId, string $academicYearId): array
            {
                return [new PeriodInfo('p-year', 'ay1', '2026-2027', 'year', 1, '2026-09-01', '2027-07-31'), new PeriodInfo('p-t1', 'ay1', '2026-2027', 'trimester', 1, '2026-09-01', '2026-12-20', 'p-year')];
            }
        };
        $councils = new class () implements CouncilDirectory {
            public function find(string $tenantId, string $establishmentId, string $councilId): ?array
            {
                return null;
            }

            public function storedResults(string $tenantId, string $establishmentId, string $structureId, string $periodId): array
            {
                if ($structureId !== 'c-6a' || $periodId !== 'p-year') {
                    return [];
                }

                return ['eleve-1' => ['person_id' => 'eleve-1', 'average' => 11.523, 'rank' => 1, 'class_size' => 2, 'mention' => null, 'proposal' => null, 'decision' => 'Admis en classe supérieure', 'details' => [], 'extras' => []]];
            }
        };
        $nationalIds = new class () implements NationalIds {
            public function of(string $tenantId, string $establishmentId, string $personId): ?string
            {
                return $personId === 'eleve-1' ? 'MAT-0001-FICTIF' : null;
            }

            public function statusOf(string $tenantId, string $establishmentId, string $personId): string
            {
                return 'pending';
            }

            public function personIdByNationalId(string $tenantId, string $establishmentId, string $nationalId): ?string
            {
                return null;
            }

            public function normalize(string $tenantId, string $establishmentId, string $nationalId): ?string
            {
                return $nationalId;
            }

            public function record(string $tenantId, string $establishmentId, string $personId, string $nationalId, string $origin, string $actorId): string
            {
                return $nationalId;
            }
        };
        $exams = new class ($test) implements ExamCandidateDirectory {
            public function __construct(private readonly FeesRemaindersTest $test)
            {
            }

            public function sessionsWithDeadlineBetween(string $tenantId, string $establishmentId, string $from, string $to): array
            {
                $deadline = '2026-11-07';

                return $establishmentId === $this->test->schoolId() && $deadline >= $from && $deadline <= $to
                    ? [['session_id' => 'sess-bepc', 'exam_code' => 'BEPC', 'exam_label' => 'BEPC (fictif)', 'registration_deadline' => $deadline, 'person_ids' => ['eleve-3', 'eleve-4']]]
                    : [];
            }
        };
        $examFees = new class ($test) implements ExamFeeStatus {
            public function __construct(private readonly FeesRemaindersTest $test)
            {
            }

            public function seen(string $tenantId, string $establishmentId, string $personId, ?string $examCode = null): bool
            {
                return $this->test->examFeeSeen($personId);
            }
        };
        $this->bootSecondary([
            EnrollmentDirectory::class => $this->enrollments, InvoiceIssuer::class => $this->ledger, LearnerInvoices::class => $this->ledger,
            InvoiceTotals::class => $this->ledger, PersonDirectory::class => $this->people, PersonDemographics::class => $this->people,
            Messenger::class => $this->messenger, ClassProfiles::class => $classes, PeriodTree::class => $periods, CouncilDirectory::class => $councils,
            NationalIds::class => $nationalIds, ExamCandidateDirectory::class => $exams, ExamFeeStatus::class => $examFees,
        ]);
        $this->school = $this->factory->opened(self::TA, 'college_prive');
        foreach ([['ins-1', 'eleve-1', 'c-6a'], ['ins-2', 'eleve-2', 'c-6a'], ['ins-3', 'eleve-3', 'c-3a'], ['ins-4', 'eleve-4', 'c-3a']] as [$enrollment, $person, $class]) {
            $this->enrollments->add(self::TA, $enrollment, $person, $this->school, $class);
        }
        $this->people->person(self::TA, 'eleve-1', 'Awa', 'Fictive', '2013-04-02');
        $this->people->person(self::TA, 'eleve-2', 'Bello', 'Exemple', '2013-07-19');
        $this->people->person(self::TA, 'eleve-3', 'Chantal', 'Modele');
        $this->people->person(self::TA, 'eleve-4', 'Daouda', 'Essai');
        $this->people->person(self::TA, 'parent-1', 'Parent', 'Fictif');
        $this->people->guarantor(self::TA, 'eleve-1', 'parent-1');
        $this->people->guarantor(self::TA, 'eleve-3', 'parent-1');
        $this->ledger->invoice(self::TA, $this->school, 'f-1', 'eleve-1', [
            ['due_on' => '2026-09-30', 'amount_xaf' => 60000, 'paid_xaf' => 60000], ['due_on' => '2026-10-30', 'amount_xaf' => 50000, 'paid_xaf' => 10000], ['due_on' => '2027-01-15', 'amount_xaf' => 40000, 'paid_xaf' => 0],
        ]);
        $this->ledger->invoice(self::TA, $this->school, 'f-2', 'eleve-2', [['due_on' => '2026-10-22', 'amount_xaf' => 30000, 'paid_xaf' => 0]]);
        $this->ledger->invoice(self::TA, $this->school, 'f-3', 'eleve-3', [['due_on' => '2026-10-08', 'amount_xaf' => 20000, 'paid_xaf' => 5000]]);
        $this->grant(self::TA, self::BURSAR, $this->school, ['secondary.billing.manage']);
        $this->grant(self::TA, self::REGISTRAR, $this->school, ['secondary.enrol.manage']);
    }

    public function schoolId(): string
    {
        return $this->school;
    }

    public function examFeeSeen(string $personId): bool
    {
        return $this->examFeesSeen[$personId] ?? false;
    }

    public function testSituationFinanciereParClasseNiveauEtablissementEtPrevisions(): void
    {
        $situation = $this->ok('GET', '/secondary/fees/situation?academic_year_id=ay1', null, self::BURSAR, self::TA, $this->school);
        $classes = array_column($situation['classes'], null, 'class_id');
        self::assertSame(['learners' => 2, 'debtors' => 2, 'invoiced_xaf' => 180000, 'paid_xaf' => 70000, 'balance_xaf' => 110000, 'overdue_xaf' => 30000], array_intersect_key($classes['c-6a'], array_flip(['learners', 'debtors', 'invoiced_xaf', 'paid_xaf', 'balance_xaf', 'overdue_xaf'])));
        self::assertSame(38.9, $classes['c-6a']['recovery_rate']);
        self::assertSame([0, null], [$classes['c-6b']['learners'], $classes['c-6b']['recovery_rate']]);
        self::assertSame(15000, $classes['c-3a']['overdue_xaf']);
        self::assertSame(['6E', '3E'], array_column($situation['levels'], 'level_code'));
        self::assertSame(200000, $situation['establishment']['invoiced_xaf']);
        self::assertSame([['month' => '2026-10', 'expected_xaf' => 40000], ['month' => '2027-01', 'expected_xaf' => 40000]], $situation['forecast']);
        self::assertStringNotContainsString('eleve-', json_encode($situation, JSON_THROW_ON_ERROR), 'Agrégats seulement.');

        [$denied] = $this->call('GET', '/secondary/fees/situation?academic_year_id=ay1', null, self::REGISTRAR, self::TA, $this->school);
        self::assertSame(403, $denied);
    }

    public function testFichierDesConseilsDeFinDAnnee(): void
    {
        $response = $this->raw('GET', '/secondary/carte-scolaire/year-end-export?academic_year_id=ay1', null, self::REGISTRAR, self::TA, $this->school);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        $lines = explode("\r\n", trim((string) $response->getBody()));
        self::assertSame("\u{FEFF}matricule;nom;prenoms;date_naissance;classe;moyenne_annuelle;rang;decision", $lines[0]);
        self::assertContains('MAT-0001-FICTIF;Fictive;Awa;2013-04-02;6e A;11,52;1;Admis en classe supérieure', $lines);
        self::assertContains(';Exemple;Bello;2013-07-19;6e A;;;', $lines, 'Élève sans matricule ni résultat : ligne présente, champs vides.');
        self::assertSame(['4', '3'], [$response->getHeaderLine('X-Rows'), $response->getHeaderLine('X-Missing-National-Ids')]);
        self::assertContains('secondary.carte_scolaire.year_end_exported', $this->audit->actions());

        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->school, 'secondary.carte_scolaire.columns.year_end', ['matricule', 'decision', 'inconnue'], 'test');
        $custom = $this->raw('GET', '/secondary/carte-scolaire/year-end-export?academic_year_id=ay1', null, self::REGISTRAR, self::TA, $this->school);
        self::assertStringStartsWith("\u{FEFF}matricule;decision\r\n", (string) $custom->getBody());

        self::assertSame(422, $this->call('GET', '/secondary/carte-scolaire/year-end-export', null, self::REGISTRAR, self::TA, $this->school)[0]);
        self::assertSame(403, $this->call('GET', '/secondary/carte-scolaire/year-end-export?academic_year_id=ay1', null, self::BURSAR, self::TA, $this->school)[0]);
    }

    public function testRelancesNot07Not08Not10SansDoublonNiDoubleSms(): void
    {
        $notices = $this->service(FeeNotices::class);
        $first = $notices->run('2026-10-23');
        self::assertSame(1, $first['skipped_socle_reminders'], 'Relances du socle actives : rien du secondaire pour les tranches.');
        self::assertSame(0, $first['not07'] + $first['not08']);
        self::assertSame(3, $first['not10'], 'J-15 du BEPC : garant d\'eleve-3, eleve-3 et eleve-4 (élèves prévenus eux-mêmes).');

        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->school, 'billing.reminder_offsets', [], 'test');
        $result = $notices->run('2026-10-23');
        self::assertSame(1, $result['not07'], 'J-7 de la tranche du 30/10 (eleve-1).');
        self::assertSame(1, $result['not08'], 'J+15 de la tranche du 08/10 (eleve-3) ; eleve-2 sans garant.');
        self::assertSame(0, $result['not10'], 'NOT-10 déjà envoyée : jamais deux fois.');
        $due = $this->messenger->of('sec.not07.fees_due');
        self::assertSame(['parent-1', '40 000', 7, '2026-10-30'], [$due[0]['person'], $due[0]['variables']['amount'], $due[0]['variables']['offset'], $due[0]['variables']['due_on']]);
        self::assertSame('15 000', $this->messenger->of('sec.not08.fees_overdue')[0]['variables']['amount']);
        $exam = $this->messenger->of('sec.not10.exam_fees_unpaid');
        self::assertSame(['parent-1', 'eleve-3', 'eleve-4'], array_column($exam, 'person'));
        self::assertSame([15, 'BEPC (fictif)'], [$exam[0]['variables']['offset'], $exam[0]['variables']['exam_label']]);
        self::assertArrayNotHasKey('amount', $exam[0]['variables'], 'Aucun montant de frais d\'examen.');

        $count = count($this->messenger->sent);
        $notices->run('2026-10-24');
        self::assertCount($count, $this->messenger->sent, 'Rattrapage sans doublon.');

        $this->applications->set(self::TA, 'suspended');
        self::assertSame(1, $notices->run('2026-10-24')['skipped_tenants']);
    }

    public function testFraisDExamenConstatesPasDeRelance(): void
    {
        $this->examFeesSeen = ['eleve-3' => true, 'eleve-4' => true];
        self::assertSame(0, $this->service(FeeNotices::class)->run('2026-10-23')['not10']);
        self::assertSame([], $this->messenger->of('sec.not10.exam_fees_unpaid'));
    }
}
