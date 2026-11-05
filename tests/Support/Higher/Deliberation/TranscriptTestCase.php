<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Deliberation;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\GroupInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\SchoolInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\TutelageInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentInfo;
use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/**
 * Base des tests de relevés IPES (C-103) : vrais services (jurys, décisions, documents du socle) sur une base SQLite migrée ;
 * seuls les résultats publiés, les inscriptions et le paramétrage académique sont des doublures. Données fictives.
 *
 * Marie (groupe GRP1, école EC) a trois semestres publiés : S1 (3 UE), S2 (2 UE, dont une non acquise) et S9 (12 UE) ; Yann n'a rien de publié.
 * Les jurys S1, S2 et S9 sont signés. Utilisateur `jury-prep` : préparation des délibérations et émission de documents dans l'école EC.
 */
abstract class TranscriptTestCase extends HigherFinanceTestCase
{
    protected const YEAR_ID = 'ay-2026';

    protected function bootTranscripts(): void
    {
        $this->bootFinance([], [
            ResultsDirectory::class => new class () implements ResultsDirectory {
                public function publishedFor(string $tenantId, string $personId): array
                {
                    if ($personId !== 'marie') {
                        return [];
                    }
                    $unit = static fn (string $code, float $average, string $status = 'acquired'): array => [
                        'code' => $code, 'name' => 'Unité ' . $code, 'credits' => 5, 'average' => $average, 'letter' => $average >= 10 ? 'C' : 'F',
                        'points' => $average >= 10 ? 2.0 : 0.0, 'status' => $status, 'session' => 'normal',
                        'elements' => [['code' => $code . '-A', 'credits' => 5, 'note' => $average]],
                    ];
                    $twelve = [];
                    for ($i = 1; $i <= 12; ++$i) {
                        $twelve[] = $unit('U9' . $i, 10.0 + $i / 2);
                    }
                    $e = HigherOrgFixture::ESTABLISHMENT_A;

                    return [
                        new PublishedResult('r1', 'marie', $e, 'S1', 'Semestre 1', 12.5, 3, 20, 'admitted', 30, 30, 'Assez bien', [$unit('UE11', 13.0), $unit('UE12', 12.5), $unit('UE13', 15.0)]),
                        new PublishedResult('r2', 'marie', $e, 'S2', 'Semestre 2', 11.0, 5, 20, 'admitted', 25, 30, 'Passable', [$unit('UE21', 11.0), $unit('UE22', 8.0, 'not_acquired')]),
                        new PublishedResult('r9', 'marie', $e, 'S9', 'Semestre 9', 13.0, 1, 20, 'admitted', 60, 60, 'Assez bien', $twelve),
                    ];
                }
            },
            StudentDirectory::class => new class () implements StudentDirectory {
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
                    return [new EnrolmentInfo('en-' . $personId, $personId, null, 'ay-2026', 'EC', 'CF', 'CF-L1', 'GRP1', 'day', 'enrolled', null, 1, null)];
                }

                public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo
                {
                    return null;
                }

                public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array
                {
                    return [...$this->enrolmentsOf($tenantId, $establishmentId, 'marie'), ...$this->enrolmentsOf($tenantId, $establishmentId, 'yann')];
                }

                public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool
                {
                    return false;
                }
            },
            AcademicDirectory::class => new class () implements AcademicDirectory {
                public function school(string $tenantId, string $establishmentId, string $schoolId): ?SchoolInfo
                {
                    return new SchoolInfo('EC', 'EC', 'École de commerce', null);
                }

                public function program(string $tenantId, string $establishmentId, string $programId): ?ProgramInfo
                {
                    return new ProgramInfo('CF', 'EC', 'EC', 'D1', null, 'CF', 'Comptabilité et finance', 'lmd', 'fr', 'day', 3, 'active');
                }

                public function level(string $tenantId, string $establishmentId, string $levelId): ?LevelInfo
                {
                    return new LevelInfo('CF-L1', 'CF', 1, 'L1', 'Licence 1', false);
                }

                public function levelsOf(string $tenantId, string $establishmentId, string $programId): array
                {
                    return [];
                }

                public function group(string $tenantId, string $establishmentId, string $groupId): ?GroupInfo
                {
                    return $groupId === 'GRP1' ? new GroupInfo('GRP1', 'CF-L1', 'CF', 'G1', 'Groupe 1', null) : null;
                }

                public function groupsOf(string $tenantId, string $establishmentId, string $levelId): array
                {
                    return [];
                }

                public function tutelageFor(string $tenantId, string $establishmentId, string $programId, string $academicYearId): ?TutelageInfo
                {
                    return null;
                }
            },
            BirthInfo::class => new class () implements BirthInfo {
                public function birthDate(string $tenantId, string $personId): ?string
                {
                    return '2006-04-12';
                }

                public function isAdult(string $tenantId, string $personId, string $atDate): ?bool
                {
                    return true;
                }
            },
        ]);
        $this->installDocuments();
        $this->assign(self::T, 'jury-prep', self::E, 'higher.deliberation.prepare', Scope::ORG_UNIT, 'EC');
        $this->assign(self::T, 'jury-prep', self::E, 'documents.issue', Scope::ORG_UNIT, 'EC');
        $this->assign(self::T, 'curieux', self::E, 'higher.access', Scope::ESTABLISHMENT);
        foreach ([['S1', 'JURY-S1'], ['S2', 'JURY-S2'], ['S9', 'JURY-S9']] as [$period, $id]) {
            $this->insertJury($id, $period);
        }
        $this->insertDecision('marie', 'D-MARIE', 12.04, 55, 60, 'admitted_with_debts', 'Admis avec dettes', 'Assez bien', '2026-10-01T09:00:00+01:00');
    }

    protected function pdo(): \PDO
    {
        return $this->kernel->container()->get(ConnectionFactory::class)->pdo();
    }

    protected function insertJury(string $id, string $period): void
    {
        $this->pdo()->prepare('INSERT INTO hi_juries (id, tenant_id, establishment_id, school_id, program_id, level_id, structure_id, academic_year_id, period_id, period_label, exam_session, regime, president_person_id, quorum_min, status, minutes_version, reopen_count, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, self::T, self::E, 'EC', 'CF', 'CF-L1', 'GRP1', self::YEAR_ID, $period, 'Semestre ' . substr($period, 1), 'normal', 'lmd', 'pres', 3, 'signed', 1, 0, 'pres', '2026-09-01T09:00:00+01:00', '2026-09-01T09:00:00+01:00']);
    }

    protected function insertDecision(string $person, string $id, float $average, int $earned, int $total, string $code, string $label, ?string $mention, string $decidedAt): void
    {
        $this->pdo()->prepare('INSERT INTO hi_year_decisions (id, tenant_id, establishment_id, person_id, school_id, program_id, level_id, structure_id, academic_year_id, average, credits_earned, credits_total, cumulative_credits, decision_code, decision_label, mention, diploma_file, derogation, acquired_units, missing_units, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, self::T, self::E, $person, 'EC', 'CF', 'CF-L1', 'GRP1', self::YEAR_ID, $average, $earned, $total, $earned, $code, $label, $mention, 0, 0, '[]', '[]', 'pres', $decidedAt]);
    }
}
