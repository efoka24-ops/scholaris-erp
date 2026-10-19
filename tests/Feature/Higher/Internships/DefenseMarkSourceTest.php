<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Internships;

use DI\Container;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\MarkSources;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSheet;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSource;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\DefenseMarkSource;
use Tests\Support\Higher\Deliberation\DeliberationFixtures as F;
use Tests\Support\Higher\Internships\FakeCurriculum;
use Tests\Support\Higher\Internships\InternshipsTestCase;

/** La note de soutenance entre dans la simulation du jury (EX-DEL-02) seulement quand le PV est établi ; EC visé par `higher.defense.element_code`. */
final class DefenseMarkSourceTest extends InternshipsTestCase
{
    private string $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInternships();
        $this->group = (string) $this->ok('POST', '/higher/groups', ['level_id' => $this->org['l2'], 'code' => 'G2', 'name' => 'Groupe 2', 'capacity' => 60])['id'];
        $this->fakes->enrol('p1', $this->org['school'], $this->org['program'], $this->org['l2'], $this->group, self::YEAR);
        $this->kernel->container()->set(CurriculumDirectory::class, new FakeCurriculum($this->org['program'], $this->org['l2'], self::YEAR));
        $this->settings->set(self::E, DefenseMarkSource::ELEMENT_KEY, 'MEM', $this->org['school']);
    }

    private function source(): DefenseMarkSource
    {
        $source = $this->kernel->container()->get(DefenseMarkSource::class);
        self::assertInstanceOf(DefenseMarkSource::class, $source);

        return $source;
    }

    private function sheet(string $tenant = self::T, ?string $group = null, string $period = 'S2'): ?MarkSheet
    {
        return $this->source()->sheet($tenant, self::E, $group ?? $this->group, $period, 'normal', self::YEAR);
    }

    private function close(float $grade): void
    {
        $id = (string) $this->schedule($this->ready())[1]['data']['id'];
        $this->ok('POST', '/higher/defenses/' . $id . '/convene', null, self::OFFICER);
        $this->clock->freeze('2026-10-20');
        $this->ok('POST', '/higher/defenses/' . $id . '/grade', ['grade' => $grade], self::PRESIDENT);
        self::assertNull($this->sheet(), 'Note saisie mais PV non établi : la soutenance n\'entre pas.');
        $this->ok('POST', '/higher/defenses/' . $id . '/minutes', null, self::PRESIDENT);
    }

    public function testSansPvOuSansElementConfigurePasDeNote(): void
    {
        self::assertNull($this->sheet(), 'Aucune soutenance.');
        $this->close(14.5);
        self::assertNotNull($this->sheet());

        $this->settings->set(self::E, DefenseMarkSource::ELEMENT_KEY, '', $this->org['school']);
        self::assertNull($this->sheet(), 'EC « à confirmer » : tant qu\'il n\'est pas choisi, la soutenance n\'entre pas.');
        $this->settings->set(self::E, DefenseMarkSource::ELEMENT_KEY, 'AUTRE', $this->org['school']);
        self::assertNull($this->sheet(), 'EC absent de la maquette.');
    }

    public function testNoteDeSoutenanceDansLEcDeLaMaquetteEtCalculChiffre(): void
    {
        $this->close(14.5);
        $sheet = $this->sheet();

        self::assertNotNull($sheet);
        self::assertSame(['p1'], array_keys($sheet->units));
        $unit = $sheet->units['p1'][0];
        self::assertSame(['ue-stage', 'ec-mem', 'MEM'], [$unit['subject_id'], $unit['groups'][0]['element_id'], $unit['groups'][0]['code']]);
        self::assertEquals(14.5, $unit['groups'][0]['entries'][0]['value']);
        self::assertSame(20.0, $unit['groups'][0]['entries'][0]['max']);
        self::assertNull($this->sheet(period: 'S1'), 'Autre semestre : l\'EC n\'y est pas.');

        // Fusion avec la feuille de Grades (suivi de stage 12/20) : 0,4 × 12 + 0,6 × 14,5 = 4,80 + 8,70 = 13,50.
        $container = new Container();
        $container->set(MarkSource::PREFIX . 'grades', [new class () implements MarkSource {
            public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
            {
                $ec = ['element_id' => 'ec-mem'] + F::ec('MEM', 6, 12, null);
                $unit = ['subject_id' => 'ue-stage'] + F::ue('UE6', [$ec]);

                return new MarkSheet(['p1' => [$unit]]);
            }
        }]);
        $container->set(MarkSource::PREFIX . 'internships', [$this->source()]);
        $units = (new MarkSources($container))->require(self::T, self::E, $this->group, 'S2', 'normal', self::YEAR)->units['p1'];
        $result = (new HigherLmdStrategy())->computeLearner(F::rules(), $units, 'normal');

        self::assertSame(13.5, $result['subjects']['ue-stage']);
    }

    public function testIsolationEntreClientsEtGroupes(): void
    {
        $this->close(15.0);

        self::assertNotNull($this->sheet());
        self::assertNull($this->sheet('T-autre'), 'Soutenance d\'un autre client.');
        self::assertNull($this->sheet(group: $this->org['g1']), 'Groupe d\'un autre niveau.');
    }
}
