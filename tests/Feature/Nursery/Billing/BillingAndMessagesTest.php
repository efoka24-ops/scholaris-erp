<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Billing;

use Scholaris\Modules\Apps\Nursery\Billing\Domain\NurseryFees;
use Tests\Feature\Nursery\Pickup\PickupTestCase;

/** EX-MAT-25, 28, 30 : frais par le socle Billing (facture d'inscription, soldes) et messages de l'école aux parents des sections. */
final class BillingAndMessagesTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup();
        $this->grant(self::TA, 'secretaire-a', self::EA, 'nursery.enrol');
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.message.send', $this->sectionA);
    }

    public function testLesNaturesDeFraisDeLaMaternelleSontDeclareesAuSocle(): void
    {
        $kinds = $this->kernel->container()->get('billing.fee_kinds.nursery');
        self::assertSame(NurseryFees::KINDS, $kinds);
        self::assertContains('daycare', $kinds);
        self::assertContains('canteen', $kinds);
    }

    public function testLaFactureDInscriptionRendCeQuiEstAPayerALInscription(): void
    {
        $enrollment = 'en-' . $this->ada['child'];
        $invoice = $this->ok('POST', '/nursery/enrolments/' . $enrollment . '/invoice', [], 'secretaire-a');
        self::assertSame(37000, $invoice['net_xaf']);
        self::assertSame(3000, $invoice['discount_xaf']);
        self::assertSame(19000, $invoice['due_at_enrolment_xaf'], 'Inscription + première tranche.');
        self::assertSame([$enrollment], $this->world->billing->invoiced);
        self::assertContains('nursery.enrolment_invoice.issued', $this->audit->actions());
    }

    public function testFactureRefuseeHorsPerimetreEtPourUnAutreClient(): void
    {
        $this->grant(self::TA, 'econome-a', self::EA, 'nursery.enrol', $this->sectionB);
        self::assertSame(403, $this->code('POST', '/nursery/enrolments/en-' . $this->ada['child'] . '/invoice', [], 'econome-a'));
        self::assertSame(403, $this->code('POST', '/nursery/enrolments/en-' . $this->ada['child'] . '/invoice', [], 'educatrice-a'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('POST', '/nursery/enrolments/en-' . $this->ada['child'] . '/invoice', [], 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/enrolments/inconnue/invoice', []));
        self::assertSame([], $this->world->billing->invoiced);
    }

    public function testLeSoldeDeLaFamilleEstLimiteAuxEnfantsDuParent(): void
    {
        $this->world->billing->balances[$this->ada['child']] = 12000;
        $parent = 'parent-' . $this->ada['child'];
        $balance = $this->ok('GET', '/nursery/portal/balance?learner_id=' . $this->ada['child'], null, $parent);
        self::assertSame(12000, $balance['balance_xaf']);
        self::assertTrue($balance['in_arrears']);
        self::assertSame(404, $this->code('GET', '/nursery/portal/balance?learner_id=' . $this->ada['child'], null, 'parent-' . $this->bao['child']));
        self::assertSame(12000, $this->ok('GET', '/nursery/children/' . $this->ada['child'] . '/balance', null, 'secretaire-a')['balance_xaf']);
        self::assertSame(403, $this->code('GET', '/nursery/children/' . $this->bao['child'] . '/balance', null, 'educatrice-a'));
    }

    public function testUnMessageDeSectionPartAuxParentsParLaMessagerieOrdinaireEtExclutLesPersonnesVisees(): void
    {
        $second = 'p-second';
        $this->world->people->add(self::TA, $second, 'Second', 'Parent', null, '+237600000055');
        $this->world->people->link(self::TA, $second, $this->ada['child']);
        $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Second Parent', 'restricted_person_id' => $second, 'nature' => 'no_contact',
            'decision_reference' => 'JUG-2026-FICTIF-077', 'valid_from' => '2026-10-01',
        ]);
        $result = $this->ok('POST', '/nursery/messages', ['subject' => 'Sortie au zoo', 'body' => 'Prévoir un chapeau.', 'section_ids' => [$this->sectionA]], 'educatrice-a');
        self::assertSame(1, $result['recipients']);
        self::assertSame(1, $result['skipped_by_restriction']);
        self::assertSame([$this->ada['parent']], $this->messenger->recipients());
        self::assertSame([], $this->safety->sent, 'Un message ordinaire n’emprunte pas le canal de sécurité.');
        self::assertCount(1, $this->ok('GET', '/nursery/messages', null, 'educatrice-a'));
    }

    public function testUneEducatriceNEcritQuAuxParentsDeSaSectionEtLaDirectionATous(): void
    {
        $body = ['subject' => 'Réunion', 'body' => 'Jeudi 17 h.'];
        self::assertSame(403, $this->code('POST', '/nursery/messages', $body + ['section_ids' => [$this->sectionB]], 'educatrice-a'));
        self::assertSame(201, $this->code('POST', '/nursery/messages', $body));
        self::assertEqualsCanonicalizing([$this->ada['parent'], $this->bao['parent']], $this->messenger->recipients());
        self::assertSame(422, $this->code('POST', '/nursery/messages', $body + ['section_ids' => ['inconnue']]));
        self::assertSame(422, $this->code('POST', '/nursery/messages', ['subject' => 'Sans texte']));
        self::assertCount(0, $this->ok('GET', '/nursery/messages', null, 'educatrice-a'), 'Un message qui touche d’autres sections n’est pas listé.');
        self::assertSame(403, $this->code('POST', '/nursery/messages', $body, 'parent-' . $this->ada['child']));
    }
}
