<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Dashboards;

use Scholaris\Modules\Apps\Primary\Dashboards\Application\ClassFigure;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\ClassFigures;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\ClosureReadiness;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\PrimaryIndicators;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\SheetFollowUp;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\EstablishmentBalances;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Reporting\Application\ProviderRegistry;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Primary\FakeBalances;
use Tests\Support\Primary\FakePresence;
use Tests\Support\Primary\FixedPeriod;
use Tests\Support\Primary\PrimaryTestCase;

/** EX-PRI-43, 44, 46 : tableau de bord du directeur (agrégats par classe, périmètre, isolation) et contrôles avant clôture. */
final class DashboardTest extends PrimaryTestCase
{
    private const DAY = '2026-10-21';

    private InMemoryEnrollments $enrollments;
    private FakePresence $presence;
    private FakeBalances $balances;
    /** @var list<ClassFigure> */
    private array $classes = [];

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->presence = new FakePresence();
        $this->balances = new FakeBalances();
        $this->bootPrimary([
            EnrollmentDirectory::class => $this->enrollments,
            DailyPresence::class => $this->presence,
            DebtorDirectory::class => $this->balances,
            EstablishmentBalances::class => $this->balances,
            CurrentPeriod::class => new FixedPeriod(),
        ], self::DAY);
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => 'ay1']);
        $this->classes = $this->service(ClassFigures::class)->all(self::TA, self::EA);
        self::assertCount(3, $this->classes, 'Le modèle d\'exemple a trois classes.');
        foreach (['a1', 'a2', 'a3'] as $i => $person) {
            $this->enrollments->add(self::TA, 'en-' . $person, $person, self::EA, $this->classes[0]->id, 'ay1');
        }
        foreach (['b1', 'b2'] as $person) {
            $this->enrollments->add(self::TA, 'en-' . $person, $person, self::EA, $this->classes[1]->id, 'ay1');
        }
    }

    /**
     * @param array<string, mixed> $dashboard
     * @return array<string, mixed>
     */
    private function row(array $dashboard, string $classId): array
    {
        foreach ($dashboard['classes'] as $row) {
            if ($row['class_id'] === $classId) {
                return $row;
            }
        }
        self::fail('Classe absente du tableau de bord.');
    }

    private function enterMark(string $classId, string $periodId, string $person): void
    {
        $levelId = '';
        foreach ($this->classes as $class) {
            if ($class->id === $classId) {
                $levelId = $class->levelId;
            }
        }
        $competency = $this->call('GET', '/primary/competencies?filter[level_structure_id]=' . $levelId)[1]['data'][0]['id'];
        $this->ok('PUT', "/primary/classes/$classId/competency-sheet?period_id=$periodId", ['entries' => [['person_id' => $person, 'competency_id' => $competency, 'scale_code' => 'acquired']]]);
    }

    public function testEffectifsAbsencesParDemiJourneePaiementsEtSaisieParClasse(): void
    {
        [$c1, $c2] = [$this->classes[0]->id, $this->classes[1]->id];
        $this->presence->set(self::TA, self::EA, self::DAY, $c1, DailyPresence::MORNING, ['a1']);
        $this->presence->set(self::TA, self::EA, self::DAY, $c1, DailyPresence::AFTERNOON, ['a1', 'a2']);
        $this->presence->set(self::TA, self::EA, '2026-10-20', $c1, DailyPresence::MORNING, ['a3']);
        $this->balances->set(self::TA, self::EA, 'a1', 12000);
        $this->balances->set(self::TA, self::EA, 'a3', 5000);
        $this->balances->set(self::TA, self::EA, 'b1', 0);
        $this->balances->set(self::TB, self::EB, 'zz', 99999);

        $data = $this->ok('GET', '/primary/dashboard');
        self::assertSame(self::DAY, $data['date']);
        self::assertSame('P-T1', $data['period']['id']);
        self::assertSame('establishment', $data['perimeter']);
        $first = $this->row($data, $c1);
        self::assertSame([3, 1, 2, 2, 17000], [$first['learners'], $first['absent_morning'], $first['absent_afternoon'], $first['late_payers'], $first['late_balance_xaf']]);
        $second = $this->row($data, $c2);
        self::assertSame([2, 0, 0, 0, 0], [$second['learners'], $second['absent_morning'], $second['absent_afternoon'], $second['late_payers'], $second['late_balance_xaf']]);
        self::assertSame(['classes' => 3, 'learners' => 5, 'absent_morning' => 1, 'absent_afternoon' => 2, 'late_payers' => 2, 'late_balance_xaf' => 17000, 'sheets_late' => 0], $data['totals']);
        // 3 élèves x 3 compétences du niveau, rien saisi, aucune date limite paramétrée : jamais « en retard ».
        self::assertSame(['period_id' => 'P-T1', 'expected' => 9, 'entered' => 0, 'status' => 'open', 'deadline' => null, 'late' => false], $first['sheet']['current']);
        self::assertSame(0, $this->row($data, $this->classes[2]->id)['learners']);
        // Agrégats seulement : aucun identifiant d'élève dans la réponse.
        foreach (['a1', 'a2', 'a3', 'b1', 'b2', 'zz'] as $person) {
            self::assertStringNotContainsString('"' . $person . '"', json_encode($data, JSON_THROW_ON_ERROR));
        }
    }

    public function testRetardDeSaisieSeulementAvecDateLimiteDepassee(): void
    {
        [$c1, $c2] = [$this->classes[0]->id, $this->classes[1]->id];
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, SheetFollowUp::DEADLINES_KEY, ['P-T1' => '2026-10-15', 'P-BAD' => 'demain'], self::DIRECTOR);
        $this->enterMark($c1, 'P-T1', 'a1');

        $data = $this->ok('GET', '/primary/dashboard');
        $first = $this->row($data, $c1)['sheet'];
        self::assertSame([9, 1, '2026-10-15', true], [$first['current']['expected'], $first['current']['entered'], $first['current']['deadline'], $first['current']['late']]);
        self::assertTrue($this->row($data, $c2)['sheet']['late'], 'Classe sans aucune saisie, échéance dépassée.');
        self::assertSame(2, $data['totals']['sheets_late']);

        // Feuille verrouillée : plus de retard de saisie.
        $this->ok('POST', "/primary/classes/$c1/competency-sheet/lock?period_id=P-T1");
        $after = $this->ok('GET', '/primary/dashboard');
        self::assertFalse($this->row($after, $c1)['sheet']['late']);
        self::assertSame(1, $after['totals']['sheets_late']);
    }

    public function testDateLimiteFutureNeFaitPasDeRetardEtPeriodeAnterieureNonVerrouilleeSi(): void
    {
        $c1 = $this->classes[0]->id;
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, SheetFollowUp::DEADLINES_KEY, ['P-T1' => '2026-11-30'], self::DIRECTOR);
        $this->enterMark($c1, 'P-T1', 'a1');
        self::assertFalse($this->row($this->ok('GET', '/primary/dashboard'), $c1)['sheet']['late']);

        $this->enterMark($c1, 'P-OLD', 'a1');
        $row = $this->row($this->ok('GET', '/primary/dashboard'), $c1)['sheet'];
        self::assertSame(['P-OLD'], $row['overdue_periods']);
        self::assertTrue($row['late'], 'Période terminée dont la feuille n\'est pas verrouillée : à terminer, sans paramètre.');

        $this->ok('POST', "/primary/classes/$c1/competency-sheet/lock?period_id=P-OLD");
        $row = $this->row($this->ok('GET', '/primary/dashboard'), $c1)['sheet'];
        self::assertSame([[], false], [$row['overdue_periods'], $row['late']]);
    }

    public function testPerimetreDeClasseIsolationEtPermission(): void
    {
        [$c1, $c2] = [$this->classes[0]->id, $this->classes[1]->id];
        $this->assign(self::TA, 'maitre', self::EA, 'primary.dashboard.read', Scope::CLASS_GROUP, $c2);
        $limited = $this->ok('GET', '/primary/dashboard', null, 'maitre');
        self::assertSame('classes', $limited['perimeter']);
        self::assertSame([$c2], array_column($limited['classes'], 'class_id'));
        self::assertSame(2, $limited['totals']['learners']);
        self::assertNotContains($c1, array_column($limited['classes'], 'class_id'));

        self::assertSame(403, $this->call('GET', '/primary/dashboard', null, 'intrus')[0], 'Sans permission : refus.');
        self::assertSame(403, $this->call('GET', '/primary/closure/check?academic_year_id=ay1', null, 'maitre')[0], 'Clôture : tout l\'établissement exigé.');

        // Autre client : ses propres classes seulement (aucune ici), jamais celles de l'école A.
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        $other = $this->ok('GET', '/primary/dashboard', null, 'directeur-b', self::TB, self::EB);
        self::assertSame([], $other['classes']);
        self::assertSame(0, $other['totals']['learners']);
        self::assertSame(403, $this->call('GET', '/primary/dashboard', null, self::DIRECTOR, self::TA, self::EB)[0], 'Établissement d\'un autre client.');
    }

    public function testControlesAvantClotureFeuillesEtTableDePassage(): void
    {
        [$c1, $c2, $c3] = [$this->classes[0]->id, $this->classes[1]->id, $this->classes[2]->id];
        $this->enterMark($c1, 'P-T1', 'a1');

        $report = $this->ok('GET', '/primary/closure/check?academic_year_id=ay1');
        self::assertFalse($report['ready']);
        self::assertSame(3, $report['blocking_count'], 'Feuilles, table de passage et conseils des maîtres (P-002).');
        $checks = array_column($report['checks'], null, 'key');
        self::assertSame(['blocking', 1], [$checks[ClosureReadiness::SHEETS]['status'], $checks[ClosureReadiness::SHEETS]['blocking_count']]);
        self::assertSame('blocking', $checks[ClosureReadiness::MAP]['status']);
        self::assertSame([['class_id' => $c1, 'name' => $this->classes[0]->name, 'periods' => ['P-T1']]], $report['sheets_to_lock']);
        self::assertSame(['missing_next_class', 'missing_next_class'], array_column($report['promotion_map_errors'], 'code'));

        // Une autre année n'est pas concernée par les feuilles de cette classe.
        self::assertSame(0, $this->ok('GET', '/primary/closure/check?academic_year_id=ay2')['checks'][0]['blocking_count']);
        // Sans année indiquée : celle de la période en cours.
        self::assertSame('ay1', $this->ok('GET', '/primary/closure/check')['academic_year_id']);

        $this->ok('POST', "/primary/classes/$c1/competency-sheet/lock?period_id=P-T1");
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'people.promotion.next_structure', [$c1 => $c2, $c2 => $c3], self::DIRECTOR);
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.closure.require_councils', false, self::DIRECTOR);
        $ready = $this->ok('GET', '/primary/closure/check?academic_year_id=ay1');
        self::assertTrue($ready['ready']);
        self::assertSame(0, $ready['blocking_count']);
        self::assertSame([], $ready['sheets_to_lock']);
    }

    public function testFournisseursDuSocleIndicateursTableauEtControlesDeCloture(): void
    {
        $this->balances->set(self::TA, self::EA, 'a1', 7000);
        $this->enterMark($this->classes[0]->id, 'P-OLD', 'a1');
        $scope = new IndicatorScope(self::TA, self::EA, self::DAY, '2026-10-19');
        $byKey = [];
        foreach ($this->service(PrimaryIndicators::class)->indicators($scope) as $indicator) {
            $byKey[$indicator->key] = $indicator;
        }
        self::assertSame(array_keys(PrimaryIndicators::EXPECTED), array_keys($byKey));
        self::assertSame([5, 1, 1], [$byKey['primary.learners']->value, $byKey['primary.late_payers']->value, $byKey['primary.sheets_late']->value]);
        foreach ($this->service(PrimaryIndicators::class)->indicators(new IndicatorScope(self::TB, self::EB, self::DAY, '2026-10-19')) as $indicator) {
            self::assertFalse($indicator->available, 'Établissement sans niveau primaire : indisponible, jamais zéro.');
        }

        $dashboard = $this->service(ProviderRegistry::class)->dashboard('primary');
        self::assertNotNull($dashboard);
        self::assertSame('primary.dashboard.read', $dashboard->permission());
        self::assertSame(array_keys(PrimaryIndicators::EXPECTED), array_keys($dashboard->expected()));

        $keys = array_map(static fn ($check): string => $check->key(), $this->service(ClosureProviders::class)->checks());
        self::assertContains(ClosureReadiness::SHEETS, $keys);
        self::assertContains(ClosureReadiness::MAP, $keys);
        $other = $this->service(ClosureReadiness::class)->sheets(self::TB, self::EB, 'ay1')['finding'];
        self::assertSame([true, 0], [$other->evaluable, $other->blockingCount], 'Sans objet hors primaire : aucun blocage.');
    }
}
