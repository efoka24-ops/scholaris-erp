<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

/** EX-MAT-11 (US2), RG-MAT-3 : restrictions de garde saisies par la directrice, invisibles du parent (404), consultation journalisée. */
final class RestrictionsTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Personne visée (fictive)', 'nature' => 'no_pickup',
            'decision_reference' => 'JUG-2026-FICTIF-001', 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-31',
        ];
    }

    public function testLaDirectriceSaisitEtLeveUneRestriction(): void
    {
        $created = $this->ok('POST', '/nursery/pickup/restrictions', $this->body());
        self::assertSame('active', $created['status']);
        self::assertContains('nursery.restriction.created', $this->audit->actions());

        $lifted = $this->ok('POST', '/nursery/pickup/restrictions/' . $created['id'] . '/lift', ['reason' => 'Décision révisée (fictive)']);
        self::assertSame('lifted', $lifted['status']);
        self::assertSame(409, $this->code('POST', '/nursery/pickup/restrictions/' . $created['id'] . '/lift', ['reason' => 'Encore']));
    }

    public function testValidationDesDatesEtDesChamps(): void
    {
        self::assertSame(422, $this->code('POST', '/nursery/pickup/restrictions', ['valid_to' => '2026-09-01'] + $this->body()));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/restrictions', ['nature' => 'autre'] + $this->body()));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/restrictions', []));
        self::assertSame(404, $this->code('POST', '/nursery/pickup/restrictions', ['learner_id' => 'inconnu'] + $this->body()));
    }

    public function testUnParentLaSecretaireEtLaSupervisionRepondent404(): void
    {
        $this->ok('POST', '/nursery/pickup/restrictions', $this->body());
        $parent = 'parent-' . $this->ada['child'];
        self::assertSame(404, $this->code('GET', '/nursery/pickup/restrictions', null, $parent));
        self::assertSame(404, $this->code('POST', '/nursery/pickup/restrictions', $this->body(), $parent));
        self::assertSame(404, $this->code('GET', '/nursery/pickup/restrictions', null, 'secretaire-a'), 'La secrétaire ne lit pas les décisions de justice.');
        $this->grant(self::TA, 'supervision-a', self::EA, 'nursery.dashboard.read');
        self::assertSame(404, $this->code('GET', '/nursery/pickup/restrictions', null, 'supervision-a'));
    }

    public function testLePersonnelDeLaSectionLesVoitEtChaqueConsultationEstJournalisee(): void
    {
        $this->ok('POST', '/nursery/pickup/restrictions', $this->body());
        $before = count(array_keys($this->audit->actions(), 'nursery.restriction.viewed', true));
        self::assertCount(1, $this->ok('GET', '/nursery/pickup/restrictions', null, 'educatrice-a'));
        self::assertCount(0, $this->ok('GET', '/nursery/pickup/restrictions', null, 'educatrice-b'), 'Une éducatrice ne voit que sa section.');
        self::assertCount(1, $this->ok('GET', '/nursery/pickup/restrictions'));
        self::assertSame($before + 3, count(array_keys($this->audit->actions(), 'nursery.restriction.viewed', true)));
    }

    public function testUneEducatriceNeSaisitPasDeRestriction(): void
    {
        self::assertSame(404, $this->code('POST', '/nursery/pickup/restrictions', $this->body(), 'educatrice-a'));
    }

    public function testUnAutreClientNeVoitAucuneRestriction(): void
    {
        $restriction = $this->ok('POST', '/nursery/pickup/restrictions', $this->body());
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame([], $this->ok('GET', '/nursery/pickup/restrictions', null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/pickup/restrictions/' . $restriction['id'] . '/lift', ['reason' => 'Pirate'], 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/pickup/restrictions', $this->body(), 'directrice-b', self::TB, self::EB));
    }
}
