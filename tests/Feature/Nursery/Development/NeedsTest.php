<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Development;

/** EX-MAT-19 (US13) : besoin particulier signalé sans diagnostic, visible de la section, de la direction et des parents ; consultation journalisée. */
final class NeedsTest extends DevelopmentTestCase
{
    protected function setUp(): void
    {
        $this->bootDevelopment();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->ok('POST', '/nursery/needs', ['learner_id' => $this->ada['child'], 'need_area' => 'language', 'summary' => 'Parle peu en groupe, à accompagner.'], 'educatrice-a');
    }

    public function testSignalementOrientationEtClotureAvecInformationDesParents(): void
    {
        $need = $this->report();
        self::assertSame('open', $need['status']);
        self::assertContains($this->ada['parent'], $this->messenger->recipients());
        self::assertSame('oriented', $this->ok('POST', '/nursery/needs/' . $need['id'] . '/orient', ['orientation_note' => 'Orienté vers l’orthophoniste du quartier (fictif).'])['status']);
        self::assertSame('closed', $this->ok('POST', '/nursery/needs/' . $need['id'] . '/close', [])['status']);
        self::assertSame(409, $this->code('POST', '/nursery/needs/' . $need['id'] . '/close', []));
        self::assertSame(409, $this->code('POST', '/nursery/needs/' . $need['id'] . '/orient', ['orientation_note' => 'Encore']));
    }

    public function testAucunDiagnosticNiChampLibreAuDeLaDescriptionCourte(): void
    {
        self::assertSame(422, $this->code('POST', '/nursery/needs', ['learner_id' => $this->ada['child'], 'need_area' => 'cancer', 'summary' => 'x'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/needs', ['learner_id' => $this->ada['child'], 'need_area' => 'other', 'summary' => str_repeat('a', 501)], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/needs', ['learner_id' => $this->ada['child'], 'need_area' => 'other', 'summary' => 'ok', 'diagnosis' => 'x'], 'educatrice-a'));
    }

    public function testVisibleDeLaSectionDeLaDirectionEtDesParentsSeulement(): void
    {
        $need = $this->report();
        self::assertCount(1, $this->ok('GET', '/nursery/needs?learner_id=' . $this->ada['child'], null, 'educatrice-a'));
        self::assertCount(1, $this->ok('GET', '/nursery/needs'));
        self::assertSame(404, $this->code('GET', '/nursery/needs?learner_id=' . $this->ada['child'], null, 'educatrice-b'));
        self::assertCount(0, $this->ok('GET', '/nursery/needs', null, 'educatrice-b'));
        self::assertSame(403, $this->code('GET', '/nursery/needs', null, 'secretaire-a'), 'La secrétaire n’a pas ce droit.');

        $parent = 'parent-' . $this->ada['child'];
        self::assertSame([$need['id']], array_column($this->ok('GET', '/nursery/portal/needs?learner_id=' . $this->ada['child'], null, $parent), 'id'));
        self::assertSame(404, $this->code('GET', '/nursery/portal/needs?learner_id=' . $this->ada['child'], null, 'parent-' . $this->bao['child']));
        self::assertContains('nursery.need.viewed', $this->audit->actions());
    }

    public function testOrienterEtClorePasseParLaDirectionEtLesAutresClientsNeVoientRien(): void
    {
        $need = $this->report();
        self::assertSame(403, $this->code('POST', '/nursery/needs/' . $need['id'] . '/orient', ['orientation_note' => 'x'], 'educatrice-a'));
        self::assertSame(403, $this->code('POST', '/nursery/needs', ['learner_id' => $this->bao['child'], 'need_area' => 'other', 'summary' => 'x'], 'educatrice-a'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('POST', '/nursery/needs/' . $need['id'] . '/close', [], 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('GET', '/nursery/needs?learner_id=' . $this->ada['child'], null, 'directrice-b', self::TB, self::EB));
    }
}
