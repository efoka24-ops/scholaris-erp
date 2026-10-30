<?php

declare(strict_types=1);

namespace Tests\Support\Higher\NationalExams;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\TutelageInfo;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\NationalExamAttendance;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\DeliberationRecords;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Higher\Academic\FakeSecondFactor;
use Tests\Support\Higher\Academic\FakeSettings;
use Tests\Support\Higher\Academic\RecordingMessenger;
use Tests\Support\Higher\HigherTestCase;

/**
 * Base des tests EXN et TUT. Client A : filière CF (BTS, école EC) niveaux CF-L1 puis CF-L2 (sortie) ; filière DR (LMD, tutelle
 * « Université de Douala » en 2026) niveau DR-L1 (sortie). Client B : filière CF (BTS). Année Y2026.
 * Étudiants de A : p1 (CF-L2, inscrit), p2 (CF-L1, inscrit), p3 (CF-L2, en attente), p4 (CF-L2, assiduité 50 %), d1 (DR-L1).
 */
abstract class NationalExamsTestCase extends HigherTestCase
{
    protected const YEAR = 'Y2026';
    protected HigherWorld $world;
    protected RecordingMessenger $messenger;
    protected FakeSecondFactor $secondFactor;
    protected FakeSettings $settings;

    protected function setUp(): void
    {
        $this->world = new HigherWorld();
        $this->messenger = new RecordingMessenger();
        $this->secondFactor = new FakeSecondFactor();
        $this->settings = new FakeSettings();
        $this->seed();
        $overrides = [SettingsReader::class => $this->settings, Messenger::class => $this->messenger, RecentSecondFactor::class => $this->secondFactor];
        foreach ([AcademicDirectory::class, StudentDirectory::class, NationalExamAttendance::class, FinancialHolds::class, DeliberationRecords::class, PersonDirectory::class, RoleDirectory::class] as $contract) {
            $overrides[$contract] = $this->world;
        }
        $this->bootHigher($overrides, true);
    }

    protected function tearDown(): void
    {
        unset($this->world, $this->messenger, $this->secondFactor, $this->settings);
        parent::tearDown();
    }

    private function seed(): void
    {
        foreach ([Org::TENANT_A, Org::TENANT_B] as $tenant) {
            $this->world->programs[$tenant . '|CF'] = new ProgramInfo('CF', 'EC', 'EC', 'EC-CF', null, 'CF', 'Comptabilité et finance', 'bts', 'fr', 'day', 2, 'active');
            $this->world->levels[$tenant . '|CF-L1'] = new LevelInfo('CF-L1', 'CF', 1, 'BTS1', 'Première année', false);
            $this->world->levels[$tenant . '|CF-L2'] = new LevelInfo('CF-L2', 'CF', 2, 'BTS2', 'Deuxième année', true);
        }
        $this->world->programs[Org::TENANT_A . '|DR'] = new ProgramInfo('DR', 'EC', 'EC', 'EC-DR', null, 'DR', 'Droit', 'lmd', 'fr', 'day', 1, 'active');
        $this->world->levels[Org::TENANT_A . '|DR-L1'] = new LevelInfo('DR-L1', 'DR', 1, 'L1', 'Licence 1', true);
        $this->world->tutelages[Org::TENANT_A . '|DR|' . self::YEAR] = new TutelageInfo('tut-1', 'DR', self::YEAR, 'Université de Douala', 'CONV-12', '2026-11-30', 'PROG-7');
        $this->world->enrolments[Org::TENANT_A . '|' . Org::ESTABLISHMENT_A] = [
            self::enrolment('en-p1', 'p1', 'CF', 'CF-L2', 'enrolled'),
            self::enrolment('en-p2', 'p2', 'CF', 'CF-L1', 'enrolled'),
            self::enrolment('en-p3', 'p3', 'CF', 'CF-L2', 'pending'),
            self::enrolment('en-p4', 'p4', 'CF', 'CF-L2', 'enrolled'),
            self::enrolment('en-d1', 'd1', 'DR', 'DR-L1', 'enrolled'),
        ];
        $this->world->enrolments[Org::TENANT_B . '|' . Org::ESTABLISHMENT_B] = [self::enrolment('en-b1', 'b1', 'CF', 'CF-L2', 'enrolled')];
        $this->world->presence['p4'] = 50.0;
        $this->world->generalDirectors = ['dg-person'];
    }

    private static function enrolment(string $id, string $person, string $program, string $level, string $status): EnrolmentInfo
    {
        return new EnrolmentInfo($id, $person, 'M-' . strtoupper($person), self::YEAR, 'EC', $program, $level, null, 'day', $status, null, 1, null);
    }

    /**
     * Session BTS de CF (client A), créée par un utilisateur doté de la gestion.
     *
     * @param list<string> $pieces
     * @return array<string, mixed>
     */
    protected function createSession(string $user = 'scol', int $fee = 0, array $pieces = []): array
    {
        [$response, $body] = $this->api('POST', '/higher/national-sessions', $user, Org::TENANT_A, Org::ESTABLISHMENT_A, [
            'program_id' => 'CF', 'academic_year_id' => self::YEAR, 'exam_type' => 'bts', 'attendance_from' => '2025-10-01', 'attendance_to' => '2026-06-30',
            'fee_xaf' => $fee, 'required_pieces' => $pieces,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) json_encode($body));

        return $body;
    }
}
