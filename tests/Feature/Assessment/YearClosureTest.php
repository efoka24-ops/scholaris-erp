<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Scholaris\Modules\Socle\Assessment\Application\OpenDeliberationsCheck;
use Scholaris\Modules\Socle\Assessment\Application\PendingGradingTodo;
use Scholaris\Modules\Socle\Assessment\Application\UnlockedGradesCheck;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\Portals\Application\GradingTodoRegistry;
use Tests\Support\Assessment\AssessmentTestCase;

/** Contrats de clôture d'année et de portail enseignant fournis par Assessment. */
final class YearClosureTest extends AssessmentTestCase
{
    /** @var array{ue1: string, ue2: string} */
    private array $units;

    /** @var array{ue1: string, ue2: string} */
    private array $assessments;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $this->units = ['ue1' => $this->subject('UE1', 'teaching_unit', 1.0, 6), 'ue2' => $this->subject('UE2', 'teaching_unit', 1.0, 4)];
        $this->assessments = [
            'ue1' => $this->assessment($this->units['ue1'], 'Examen UE1', extra: ['teacher_person_id' => 't1']),
            'ue2' => $this->assessment($this->units['ue2'], 'Examen UE2', extra: ['teacher_person_id' => 't1']),
        ];
        // p1 admis, p2 admis par compensation, p3 rattrapage, p4 ajourné.
        foreach (['p1' => [12, 11], 'p2' => [12, 8], 'p3' => [14, 7], 'p4' => [5, 6]] as $person => [$a, $b]) {
            $this->grade($this->assessments['ue1'], $person, ['value' => $a]);
            $this->grade($this->assessments['ue2'], $person, ['value' => $b]);
        }
    }

    private function unlocked(): UnlockedGradesCheck
    {
        return $this->container()->get(UnlockedGradesCheck::class);
    }

    private function deliberations(): OpenDeliberationsCheck
    {
        return $this->container()->get(OpenDeliberationsCheck::class);
    }

    private function closeDeliberation(): void
    {
        [$response, $json] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Semestre 1', 'president_id' => 'm1', 'members_expected' => 5]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = (string) $json['data']['id'];
        $this->api('POST', '/deliberations/' . $id . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        $this->api('POST', '/deliberations/' . $id . '/start', 'jury');
        self::assertSame(200, $this->api('POST', '/deliberations/' . $id . '/close', 'jury')[0]->getStatusCode());
    }

    public function testLesFournisseursSontDecouverts(): void
    {
        $checks = array_map(static fn (object $c): string => $c::class, $this->container()->get(ClosureProviders::class)->checks());

        self::assertContains(UnlockedGradesCheck::class, $checks);
        self::assertContains(OpenDeliberationsCheck::class, $checks);
        self::assertSame('assessment.grades_unlocked', $this->unlocked()->key());
        self::assertSame('assessment.deliberations_open', $this->deliberations()->key());
    }

    public function testNotesNonVerrouilleesBloquentPuisPlusRienUneFoisVerrouillees(): void
    {
        $finding = $this->unlocked()->evaluate('t1', 'e1', 'ay1');
        self::assertTrue($finding->blocks());
        self::assertSame(2, $finding->blockingCount);

        $this->lock($this->assessments['ue1']);
        self::assertSame(1, $this->unlocked()->evaluate('t1', 'e1', 'ay1')->blockingCount);
        $this->lock($this->assessments['ue2']);
        self::assertFalse($this->unlocked()->evaluate('t1', 'e1', 'ay1')->blocks());
        self::assertSame(0, $this->unlocked()->evaluate('t1', 'e1', 'ay-autre')->blockingCount, 'Une autre année n\'est pas concernée.');
        self::assertSame(0, $this->unlocked()->evaluate('t1', 'e2', 'ay1')->blockingCount, 'Un autre établissement non plus.');
        self::assertSame(0, $this->unlocked()->evaluate('t2', 'e1', 'ay1')->blockingCount, 'Ni un autre client.');
    }

    public function testDeliberationOuverteBloqueJusquAsaCloture(): void
    {
        $this->lock($this->assessments['ue1']);
        $this->lock($this->assessments['ue2']);
        $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 5]);

        $finding = $this->deliberations()->evaluate('t1', 'e1', 'ay1');
        self::assertTrue($finding->blocks());
        self::assertSame(1, $finding->blockingCount);

        $this->closeDeliberation2();
        self::assertFalse($this->deliberations()->evaluate('t1', 'e1', 'ay1')->blocks());
        self::assertSame(0, $this->deliberations()->evaluate('t2', 'e1', 'ay1')->blockingCount);
    }

    private function closeDeliberation2(): void
    {
        $id = (string) $this->rows('SELECT id FROM deliberations')[0]['id'];
        $this->api('POST', '/deliberations/' . $id . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        $this->api('POST', '/deliberations/' . $id . '/start', 'jury');
        self::assertSame(200, $this->api('POST', '/deliberations/' . $id . '/close', 'jury')[0]->getStatusCode());
    }

    public function testLesAdmisSontLesDecisionsAdmisEtAdmisParCompensation(): void
    {
        $this->lock($this->assessments['ue1']);
        $this->lock($this->assessments['ue2']);
        $directory = $this->container()->get(AdmissionDirectory::class);
        self::assertSame([], $directory->admittedPersonIds('t1', 'e1', 'ay1'), 'Aucune décision avant la délibération.');

        $this->closeDeliberation();

        $admitted = $directory->admittedPersonIds('t1', 'e1', 'ay1');
        sort($admitted);
        self::assertSame(['p1', 'p2'], $admitted);
        self::assertSame([], $directory->admittedPersonIds('t1', 'e1', 'ay-autre'));
        self::assertSame([], $directory->admittedPersonIds('t1', 'e2', 'ay1'));
        self::assertSame([], $directory->admittedPersonIds('t2', 'e1', 'ay1'));
    }

    public function testSaisiesDeNotesAFairePourUnEnseignant(): void
    {
        $registry = $this->container()->get(GradingTodoRegistry::class);
        $this->moveTo($this->assessments['ue1'], 'entry');

        $items = $registry->pendingFor('t1', 't1');
        self::assertSame([], $items, 'Toutes les notes sont saisies.');

        $extra = $this->assessment($this->units['ue1'], 'Devoir surprise', extra: ['teacher_person_id' => 't1']);
        $this->moveTo($extra, 'entry');
        $this->grade($extra, 'p1', ['value' => 10]);
        $this->grade($extra, 'p2', ['value' => 11]);

        $items = $registry->pendingFor('t1', 't1');
        self::assertNotNull($items);
        self::assertCount(1, $items);
        self::assertSame([$extra, 'Devoir surprise', 'e1', 'c1', 2], [$items[0]['assessment_id'], $items[0]['label'], $items[0]['establishment_id'], $items[0]['structure_id'], $items[0]['missing_grades']]);

        self::assertSame([], $registry->pendingFor('t1', 'autre-enseignant'));
        self::assertSame([], $registry->pendingFor('t2', 't1'), 'Un autre client ne voit rien.');
        self::assertInstanceOf(PendingGradingTodo::class, $this->container()->get(PendingGradingTodo::class));
    }
}
