<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Vacations;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradeBook;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationClock;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\HigherTestCase;

/**
 * Base des tests des vacations : base migrée (tables hi_* réelles de l'enseignement, vacation_rates du socle), séances du cahier de textes
 * insérées directement, conditions de payabilité réelles (HigherPayabilityCheck), doublures pour comptes, personnel, notes, messagerie,
 * documents et paramètres. TST-11 : assistant, L2, novembre 2026, 6 000 FCFA/HETD, 18 h CM, 12 h TD, 6 h TP.
 */
abstract class VacationsTestCase extends HigherTestCase
{
    public const T = HigherOrgFixture::TENANT_A;
    public const E = HigherOrgFixture::ESTABLISHMENT_A;
    public const TEACHER = 'teacher-v';
    public const OTHER_TEACHER = 'teacher-w';
    public const CHEF = 'chef-dept';
    public const DIRECTOR = 'dir-ecole';
    public const DG = 'dg';
    public const ACCOUNTANT = 'comptable';
    public const PERSON = 'p-teacher';
    public const OTHER_PERSON = 'p-other';
    public const ELEMENT = 'ec-mf';
    public const MONTH = '2026-11';

    protected VacationFakes $fakes;
    private int $sequence = 0;

    protected function bootVacations(): void
    {
        $this->fakes = new VacationFakes();
        $overrides = [];
        foreach ([UserPersonLink::class, StaffDirectory::class, GradeBook::class, Messenger::class, HigherDocumentIssuer::class, SettingsReader::class] as $contract) {
            $overrides[$contract] = $this->fakes;
        }
        $this->bootHigher($overrides + [VacationClock::class => new VacationClock('2026-12-05')], true);
        $this->tree->addStructure(self::T, self::E, 'L2', 'level', 'CF', null);
        $this->tree->addStructure(self::T, self::E, 'L2-G1', 'group', 'L2', null);

        $this->fakes->links = [self::T . ':' . self::TEACHER => self::PERSON, self::T . ':' . self::OTHER_TEACHER => self::OTHER_PERSON];
        foreach ([self::PERSON => 'st-1', self::OTHER_PERSON => 'st-2'] as $person => $staffId) {
            $this->fakes->staff[self::T . ':' . $person] = new StaffInfo($person, self::T, StaffInfo::VACATAIRE, 'assistant', 'active', $staffId);
        }
        foreach ([self::TEACHER, self::OTHER_TEACHER] as $teacher) {
            $this->assign(self::T, $teacher, self::E, 'higher.vacation.read_own', 'establishment', null, 'enseignant');
        }
        foreach (['higher.hours.validate', 'higher.vacation.read'] as $permission) {
            $this->assign(self::T, self::CHEF, self::E, $permission, 'org_unit', 'EC-CF', 'higher_chef_dept');
        }
        foreach (['higher.vacation.validate_school', 'higher.vacation.read'] as $permission) {
            $this->assign(self::T, self::DIRECTOR, self::E, $permission, 'org_unit', 'EC', 'higher_dir_ecole');
        }
        foreach (['higher.vacation.validate', 'higher.vacation.read'] as $permission) {
            $this->assign(self::T, self::DG, self::E, $permission, 'establishment', null, 'direction_groupe');
        }
        foreach (['higher.vacation.pay', 'higher.vacation.read'] as $permission) {
            $this->assign(self::T, self::ACCOUNTANT, self::E, $permission, 'establishment', null, 'comptable');
        }
        $this->rate('assistant', '', 6000);
    }

    protected function tearDown(): void
    {
        unset($this->fakes);
        parent::tearDown();
    }

    protected function pdo(): PDO
    {
        $connections = $this->kernel->container()->get(ConnectionFactory::class);
        self::assertInstanceOf(ConnectionFactory::class, $connections);

        return $connections->pdo();
    }

    protected function rate(string $category, string $level, int $rate, string $tenantId = self::T, string $establishmentId = self::E): void
    {
        $this->pdo()->prepare('INSERT INTO vacation_rates (id, tenant_id, establishment_id, category, level, hourly_rate, monthly_cap_minutes, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)')
            ->execute([sprintf('01RATE%020d', ++$this->sequence), $tenantId, $establishmentId, $category, $level, $rate, '2026-10-15T00:00:00+01:00']);
    }

    /** Affectation IPES (hi_assignments) de l'EC de mathématiques financières, groupe L2-G1 ; retourne son identifiant. */
    protected function assignment(string $kind, int $plannedMinutes, string $person = self::PERSON, string $tenantId = self::T, string $establishmentId = self::E): string
    {
        $id = sprintf('01ASSG%020d', ++$this->sequence);
        $this->insert('hi_assignments', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'school_id' => 'EC', 'department_id' => 'EC-CF',
            'program_id' => 'CF', 'level_id' => 'L2', 'group_id' => 'L2-G1', 'academic_year_id' => 'y2026', 'curriculum_id' => 'cur-1',
            'element_id' => self::ELEMENT, 'element_code' => 'MF', 'element_title' => 'Mathématiques financières', 'person_id' => $person,
            'staff_id' => $person === self::PERSON ? 'st-1' : 'st-2', 'session_kind' => $kind, 'planned_minutes' => $plannedMinutes,
            'staff_assignment_id' => null, 'status' => 'active', 'assigned_by' => self::CHEF, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01',
        ]);

        return $id;
    }

    /** $count séances de $minutes minutes, une par jour à partir du $firstDay novembre, au statut donné. */
    protected function sessions(string $assignmentId, string $kind, int $count, int $minutes, int $firstDay, string $status = 'validated', string $person = self::PERSON, string $tenantId = self::T, string $establishmentId = self::E): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->insert('hi_session_logs', [
                'id' => sprintf('01SLOG%020d', ++$this->sequence), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId,
                'assignment_id' => $assignmentId, 'school_id' => 'EC', 'department_id' => 'EC-CF', 'program_id' => 'CF', 'level_id' => 'L2',
                'group_id' => 'L2-G1', 'element_id' => self::ELEMENT, 'person_id' => $person, 'session_kind' => $kind,
                'held_on' => sprintf('2026-11-%02d', $firstDay + $i), 'start_time' => '08:00', 'end_time' => '10:00', 'minutes' => $minutes,
                'content' => 'Chapitre ' . ($i + 1), 'teacher_signed_at' => '2026-11-01T10:00:00+01:00',
                'countersigned_at' => in_array($status, ['countersigned', 'validated'], true) ? '2026-11-01T11:00:00+01:00' : null,
                'status' => $status, 'created_at' => '2026-11-01', 'updated_at' => '2026-11-01',
            ]);
        }
    }

    /** Jeu TST-11 : 6 × 3 h CM, 6 × 2 h TD, 3 × 2 h TP validées en novembre. */
    protected function tst11(string $status = 'validated'): void
    {
        $this->sessions($this->assignment('cm', 1800), 'cm', 6, 180, 2, $status);
        $this->sessions($this->assignment('td', 1200), 'td', 6, 120, 9, $status);
        $this->sessions($this->assignment('tp', 600), 'tp', 3, 120, 16, $status);
    }

    /** @param array<string, scalar|null> $row */
    protected function insert(string $table, array $row): void
    {
        $this->pdo()->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))))
            ->execute(array_values($row));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function call(string $method, string $path, string $user, ?array $body = null, string $tenantId = self::T, string $establishmentId = self::E): array
    {
        [$response, $json] = $this->api($method, '/higher' . $path, $user, $tenantId, $establishmentId, $body);

        return [$response->getStatusCode(), $json];
    }

    /** @return array<string, mixed> premier état établi */
    protected function prepare(string $user = self::CHEF): array
    {
        [$status, $json] = $this->call('POST', '/vacations/statements', $user, ['person_id' => self::PERSON, 'period_month' => self::MONTH]);
        self::assertSame(201, $status, json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data'][0];
    }
}
