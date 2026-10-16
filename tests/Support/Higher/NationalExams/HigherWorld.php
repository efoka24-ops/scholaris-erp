<?php

declare(strict_types=1);

namespace Tests\Support\Higher\NationalExams;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\GroupInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\SchoolInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\TutelageInfo;
use Scholaris\Modules\Apps\Higher\Attendance\Contracts\NationalExamAttendance;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\DeliberationRecords;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentInfo;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Doublure unique des Contracts lus par NationalExams et Tutelage (Academic, Enrolment, Attendance, Billing, Deliberation, People,
 * Identity) : données par « client|établissement ». Aucune base.
 */
final class HigherWorld implements AcademicDirectory, StudentDirectory, NationalExamAttendance, FinancialHolds, DeliberationRecords, PersonDirectory, RoleDirectory
{
    /** @var array<string, ProgramInfo> */
    public array $programs = [];
    /** @var array<string, LevelInfo> */
    public array $levels = [];
    /** @var array<string, TutelageInfo> clé « programme|année » */
    public array $tutelages = [];
    /** @var array<string, list<EnrolmentInfo>> clé « client|établissement » */
    public array $enrolments = [];
    /** @var array<string, float> présence par personne */
    public array $presence = [];
    /** @var array<string, int> */
    public array $balances = [];
    /** @var array<string, array{decision_code: string, decision_label: string, credits_earned: int, cumulative_credits: int, diploma_file: bool, mention: ?string}> */
    public array $decisions = [];
    /** @var array<string, true> clé « structure|période » */
    public array $signed = [];
    /** @var list<string> */
    public array $generalDirectors = [];

    public function school(string $tenantId, string $establishmentId, string $schoolId): ?SchoolInfo
    {
        return null;
    }

    public function program(string $tenantId, string $establishmentId, string $programId): ?ProgramInfo
    {
        return $this->programs[$tenantId . '|' . $programId] ?? null;
    }

    public function level(string $tenantId, string $establishmentId, string $levelId): ?LevelInfo
    {
        return $this->levels[$tenantId . '|' . $levelId] ?? null;
    }

    public function levelsOf(string $tenantId, string $establishmentId, string $programId): array
    {
        $levels = [];
        foreach ($this->levels as $key => $level) {
            if (str_starts_with($key, $tenantId . '|') && $level->programId === $programId) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    public function group(string $tenantId, string $establishmentId, string $groupId): ?GroupInfo
    {
        return null;
    }

    public function groupsOf(string $tenantId, string $establishmentId, string $levelId): array
    {
        return [];
    }

    public function tutelageFor(string $tenantId, string $establishmentId, string $programId, string $academicYearId): ?TutelageInfo
    {
        return $this->tutelages[$tenantId . '|' . $programId . '|' . $academicYearId] ?? null;
    }

    public function student(string $tenantId, string $establishmentId, string $personId): ?StudentInfo
    {
        return null;
    }

    public function byMatricule(string $tenantId, string $establishmentId, string $matricule): ?StudentInfo
    {
        return null;
    }

    public function enrolmentsOf(string $tenantId, string $establishmentId, string $personId): array
    {
        return array_values(array_filter($this->enrolments[$tenantId . '|' . $establishmentId] ?? [], static fn (EnrolmentInfo $e): bool => $e->personId === $personId));
    }

    public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo
    {
        foreach ($this->enrolments[$tenantId . '|' . $establishmentId] ?? [] as $e) {
            if ($e->id === $enrolmentId) {
                return $e;
            }
        }

        return null;
    }

    public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array
    {
        return array_values(array_filter(
            $this->enrolments[$tenantId . '|' . $establishmentId] ?? [],
            static fn (EnrolmentInfo $e): bool => $e->isValidated() && $e->academicYearId === $academicYearId && ($e->levelId === $structureId || $e->groupId === $structureId),
        ));
    }

    public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool
    {
        return false;
    }

    public function presenceOf(string $tenantId, string $establishmentId, string $personId, string $from, string $to): array
    {
        $percent = $this->presence[$personId] ?? 100.0;

        return ['sessions' => 100, 'unjustified' => (int) (100 - $percent), 'presence_percent' => $percent, 'threshold_percent' => 70.0, 'eligible' => $percent >= 70.0];
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $personId, string $scope): bool
    {
        return false;
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $personId): int
    {
        return $this->balances[$personId] ?? 0;
    }

    public function minutesSigned(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session = 'normal'): bool
    {
        return isset($this->signed[$structureId . '|' . $periodId]);
    }

    public function yearDecision(string $tenantId, string $establishmentId, string $personId, string $programId, string $academicYearId): ?array
    {
        return $this->decisions[$personId] ?? null;
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return new PersonSummary($personId, $tenantId, 'Prénom ' . $personId, 'NOM-' . strtoupper($personId), null, null, 'fr', 'female');
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?PersonSummary => $this->find($tenantId, $id), $personIds)));
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return [];
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return [];
    }

    public function personIdsWithRole(string $tenantId, string $establishmentId, array $roles): array
    {
        return in_array('direction_groupe', $roles, true) ? $this->generalDirectors : [];
    }
}
