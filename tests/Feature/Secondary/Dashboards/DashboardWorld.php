<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Dashboards;

use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageLine;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Base des tests du pilotage : les contrats du socle (inscriptions, sexe et âge, assiduité, factures, périodes, personnel, résultats
 * enregistrés) et la couverture du cahier de textes sont des doublures en mémoire ; Setup, Councils, CarteScolaire, Fees et
 * OfficialExams sont réels (tables se_* alimentées directement). Année fictive : T1 du 7 septembre au 31 octobre 2026 (séquences
 * finissant le 10 et le 24 octobre), T2 du 1er novembre 2026 au 31 janvier 2027 ; aujourd'hui = 10 décembre 2026.
 */
abstract class DashboardWorld extends SecondaryTestCase implements StructureEnrollments, PersonDemographics, AttendanceTotals, LearnerInvoices, InvoiceTotals, PeriodTree, CurrentPeriod, EstablishmentStaffRoster, CoverageReader
{
    protected const YEAR = 'annee-2026';
    protected const TODAY = '2026-12-10';
    protected const T1 = 'per-t1';
    protected const T2 = 'per-t2';

    /** @var list<EnrollmentInfo> */
    protected array $enrolments = [];
    /** @var array<string, array{gender: ?string, birth_date: ?string}> */
    protected array $people = [];
    /** @var array<string, array<string, int>> début du trimestre => heures injustifiées par élève */
    protected array $hours = [];
    /** @var array<string, list<string>> */
    protected array $invoices = [];
    /** @var array<string, array{net_xaf: int, paid_xaf: int, balance_xaf: int, overdue_xaf: int}> */
    protected array $totals = [];
    /** @var array<string, list<StaffMemberInfo>> */
    protected array $staff = [];
    /** @var array<string, float|null> classe => taux de couverture (null : aucune progression) */
    protected array $coverageRates = [];
    /** @var array<string, array<string, array{person_id: string, average: float|null, rank: int|null, class_size: int, mention: string|null, proposal: string|null, decision: string|null, details: list<array<string, mixed>>, extras: array<string, mixed>}>> classe|période => résultats */
    protected array $stored = [];

    protected function setUp(): void
    {
        $this->bootSecondary([
            StructureEnrollments::class => $this, PersonDemographics::class => $this, AttendanceTotals::class => $this, LearnerInvoices::class => $this,
            InvoiceTotals::class => $this, PeriodTree::class => $this, CurrentPeriod::class => $this, EstablishmentStaffRoster::class => $this,
            CouncilDirectory::class => $this->councils(), CoverageReader::class => $this, SecondaryClock::class => new SecondaryClock(self::TODAY),
        ]);
    }

    /**
     * Établissement ouvert d'un type, niveau 6E, classes « 6e A », « 6e B »… de l'année.
     *
     * @param list<string> $extraModules modules ajoutés au profil (ex. network_indicators)
     * @return array{school: string, classes: list<string>}
     */
    protected function school(string $code, int $classCount = 2, array $extraModules = [], string $tenantId = self::TA, ?string $id = null): array
    {
        $school = $this->factory->opened($tenantId, $code, null, $id);
        $this->grant($tenantId, self::ADMIN, $school, ['secondary.settings.manage', 'secondary.profile.manage', 'secondary.profile.read']);
        if ($extraModules !== []) {
            $statement = $this->pdo()->prepare('SELECT modules FROM se_establishment_profiles WHERE tenant_id = ? AND establishment_id = ?');
            $statement->execute([$tenantId, $school]);
            $modules = json_decode((string) $statement->fetchColumn(), true);
            $this->pdo()->prepare('UPDATE se_establishment_profiles SET modules = ? WHERE tenant_id = ? AND establishment_id = ?')
                ->execute([json_encode(array_values(array_unique([...(array) $modules, ...$extraModules]))), $tenantId, $school]);
        }
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, $tenantId, $school), null, 'code');
        $classes = [];
        for ($i = 0; $i < $classCount; ++$i) {
            $class = $this->ok('POST', '/secondary/setup/classes', ['label' => '6e ' . chr(65 + $i), 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, $tenantId, $school);
            $classes[] = (string) $class['structure_id'];
        }

        return ['school' => $school, 'classes' => $classes];
    }

    protected function student(string $tenantId, string $school, string $class, string $person, ?string $gender, ?string $birthDate = '2014-03-01'): void
    {
        $this->enrolments[] = new EnrollmentInfo('enr-' . $person, $tenantId, $person, $school, $class, self::YEAR, EnrollmentInfo::ACTIVE);
        $this->people[$tenantId . '|' . $person] = ['gender' => $gender, 'birth_date' => $birthDate];
    }

    /** @param array<string, mixed> $values */
    protected function row(string $table, array $values): void
    {
        $columns = array_keys($values);
        $this->pdo()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($values));
    }

    public function inStructure(string $tenantId, string $structureId): array
    {
        return array_values(array_filter($this->enrolments, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->structureId === $structureId));
    }

    public function ofPersons(string $tenantId, array $personIds): array
    {
        $out = [];
        foreach ($personIds as $id) {
            if (isset($this->people[$tenantId . '|' . $id])) {
                $out[$id] = $this->people[$tenantId . '|' . $id];
            }
        }

        return $out;
    }

    public function byLearner(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->hours[$from] ?? [] as $person => $hours) {
            $out[$person] = new AttendanceSummary(20, $hours, $hours, 0);
        }

        return $out;
    }

    public function idsFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        return $this->invoices[$learnerId] ?? [];
    }

    public function totals(string $tenantId, string $establishmentId, array $invoiceIds, string $today): array
    {
        return array_intersect_key($this->totals, array_flip($invoiceIds));
    }

    public function find(string $tenantId, string $establishmentId, string $id): ?PeriodInfo
    {
        foreach ($this->ofYear($tenantId, $establishmentId, self::YEAR) as $period) {
            if ($period->id === $id) {
                return $period;
            }
        }

        return null;
    }

    public function ofYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        if ($academicYearId !== self::YEAR) {
            return [];
        }

        return [
            new PeriodInfo('per-year', self::YEAR, '2026-2027', 'year', 1, '2026-09-07', '2027-06-30'),
            new PeriodInfo(self::T1, self::YEAR, '2026-2027', 'trimester', 1, '2026-09-07', '2026-10-31', 'per-year'),
            new PeriodInfo('per-s1', self::YEAR, '2026-2027', 'sequence', 1, '2026-09-07', '2026-10-10', self::T1),
            new PeriodInfo('per-s2', self::YEAR, '2026-2027', 'sequence', 2, '2026-10-11', '2026-10-24', self::T1),
            new PeriodInfo(self::T2, self::YEAR, '2026-2027', 'trimester', 2, '2026-11-01', '2027-01-31', 'per-year'),
        ];
    }

    public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo
    {
        return $this->find($tenantId, $establishmentId, self::T2);
    }

    public function allAt(string $tenantId, string $establishmentId, string $date): array
    {
        $period = $this->at($tenantId, $establishmentId, $date);

        return $period === null ? [] : [$period];
    }

    public function membersOf(string $tenantId, string $establishmentId): array
    {
        return $this->staff[$establishmentId] ?? [];
    }

    /** Résultats enregistrés lus dans $this->stored (classe|période). */
    private function councils(): CouncilDirectory
    {
        return new class ($this) implements CouncilDirectory {
            public function __construct(private readonly DashboardWorld $world)
            {
            }

            public function find(string $tenantId, string $establishmentId, string $councilId): ?array
            {
                return null;
            }

            public function storedResults(string $tenantId, string $establishmentId, string $structureId, string $periodId): array
            {
                return $this->world->storedFor($structureId, $periodId);
            }
        };
    }

    /** @return array<string, array{person_id: string, average: float|null, rank: int|null, class_size: int, mention: string|null, proposal: string|null, decision: string|null, details: list<array<string, mixed>>, extras: array<string, mixed>}> */
    public function storedFor(string $structureId, string $periodId): array
    {
        return $this->stored[$structureId . '|' . $periodId] ?? [];
    }

    public function coverage(string $tenantId, string $establishmentId, string $academicYearId, string $classId): array
    {
        if (!array_key_exists($classId, $this->coverageRates)) {
            return [];
        }
        $rate = $this->coverageRates[$classId];

        return [new CoverageLine('aff-' . $classId, $classId, null, 'sub-math', 'Mathématiques', 'prof-1', $rate === null ? null : 10.0, $rate === null ? 2.0 : 10.0 * $rate, $rate, 4, 2, '2026-10-20')];
    }
}
