<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;

/** EX-MAT-10 (US1), RG-MAT-2 : la remise cite l'identifiant d'une autorisation valable ce jour ; jamais un nom libre ; toute voie testée. */
final class HandoverTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup();
    }

    public function testUneAutorisationPermanenteRemetLEnfantEtLeJournalEnTientTrace(): void
    {
        $pickup = $this->authorize($this->ada['child']);
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a');
        self::assertSame(201, $status);
        self::assertSame('handed_over', $json['data']['status']);
        self::assertFalse($json['data']['stale_authorization']);
        self::assertCount(1, $this->events('handed_over'));
        self::assertSame($pickup, $this->events('handed_over')[0]['pickup_id']);
        self::assertContains('nursery.handover.handed_over', $this->audit->actions());
        self::assertSame([], $this->safety->sent, 'Aucune alerte pour une remise normale.');
    }

    public function testAucunNomLibreNeRemetLEnfant(): void
    {
        $this->authorize($this->ada['child'], 'Tante Rose (exemple)');
        // Un nom déclaré seul ne remet pas : il faut l'identifiant d'une autorisation (ou un code).
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'declared_name' => 'Tante Rose (exemple)'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => 'x', 'code' => '123456'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'name' => 'Tante Rose'], 'educatrice-a'), 'Champ inconnu refusé.');
        self::assertSame([], $this->events('handed_over'));
    }

    public function testUnIdentifiantInconnuEstRefuseAvecAlerteAuxParentsEtALaDirection(): void
    {
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => 'inconnue', 'declared_name' => 'Inconnu Test'], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('unauthorized', $json['code']);
        self::assertSame('refused', $json['data']['status']);
        self::assertSame(['sent' => 2, 'queued' => 0, 'blocked' => 0, 'failed' => 0], $json['data']['alerts']);
        self::assertSame([], $this->events('handed_over'));
        $refused = $this->events('refused');
        self::assertCount(1, $refused);
        self::assertSame('Inconnu Test', $refused[0]['declared_name']);
        self::assertEqualsCanonicalizing([$this->ada['parent'], $this->directorPerson], $this->safety->recipients());
        self::assertSame(['nursery.not04.refused_parent', 'nursery.not04.refused_director'], $this->safety->templates());
        self::assertSame([], $this->messenger->sent, 'Le refus passe par le canal essentiel, jamais par la messagerie soumise au consentement.');
    }

    public function testUneAutorisationRevoqueeOuDUnAutreJourDonneAuthorizationNotValid(): void
    {
        $revoked = $this->authorize($this->ada['child'], 'Oncle Paul (exemple)');
        $this->ok('POST', '/nursery/pickup/authorized-persons/' . $revoked . '/revoke', [], 'secretaire-a');
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $revoked], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('authorization_not_valid', $json['code']);

        $otherDay = $this->authorize($this->ada['child'], 'Voisine (exemple)', 'day', '2026-10-22');
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $otherDay], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('authorization_not_valid', $json['code']);
        self::assertSame([], $this->events('handed_over'));
    }

    public function testUneAutorisationDUnAutreEnfantNeRemetPasCetEnfant(): void
    {
        $forBao = $this->authorize($this->bao['child'], 'Marraine de Bao (exemple)');
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $forBao], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('unauthorized', $json['code']);
    }

    public function testUneRestrictionBloqueLaRemiseEtLaPersonneVisseNeRecoitAucuneAlerte(): void
    {
        $second = 'p-second-parent';
        $this->world->people->add(self::TA, $second, 'Second', 'Parent', null, '+237600000077');
        $this->world->people->link(self::TA, $second, $this->ada['child']);
        $pickup = $this->authorize($this->ada['child'], 'Papa visé (exemple)');
        $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Autre nom', 'restricted_person_id' => $second, 'restricted_pickup_id' => $pickup,
            'nature' => 'no_pickup', 'decision_reference' => 'JUG-2026-FICTIF-004', 'valid_from' => '2026-10-01',
        ]);
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('restricted', $json['code']);
        self::assertContains($this->ada['parent'], $this->safety->recipients());
        self::assertNotContains($second, $this->safety->recipients(), 'La personne visée ne reçoit aucune alerte.');
        self::assertNotNull($this->events('refused')[0]['restriction_id']);
    }

    public function testUneRestrictionVisantLeNomBloqueMemeSousUneAutreAutorisation(): void
    {
        $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => '  papa   visé (EXEMPLE) ', 'nature' => 'no_pickup', 'decision_reference' => 'JUG-2026-FICTIF-005', 'valid_from' => '2026-10-01',
        ]);
        $pickup = $this->authorize($this->ada['child'], 'Papa visé (exemple)');
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a'));
    }

    public function testUneRestrictionLeveeOuExpireeNeBloquePlus(): void
    {
        $pickup = $this->authorize($this->ada['child'], 'Papa (exemple)');
        $restriction = $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Papa (exemple)', 'nature' => 'no_pickup', 'decision_reference' => 'JUG-2026-FICTIF-006', 'valid_from' => '2026-10-01',
        ]);
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a'));
        $this->ok('POST', '/nursery/pickup/restrictions/' . $restriction['id'] . '/lift', ['reason' => 'Jugement révisé (fictif)']);
        self::assertSame(201, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a'));
    }

    public function testLeRejeuParClientUuidNeCreeAucunDoublon(): void
    {
        $pickup = $this->authorize($this->ada['child']);
        $body = ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup, 'client_uuid' => 'lot-0001-a'];
        self::assertSame(201, $this->code('POST', '/nursery/pickup/handovers', $body, 'educatrice-a'));
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', $body, 'educatrice-a');
        self::assertSame(200, $status);
        self::assertTrue($json['data']['duplicate']);
        self::assertCount(1, $this->events('handed_over'));
    }

    public function testRemiseHorsLigneAvecAutorisationDevenueInvalideEstEnregistreeMarqueePerimeeAvecAlerte(): void
    {
        $pickup = $this->authorize($this->ada['child'], 'Oncle (exemple)');
        $this->ok('POST', '/nursery/pickup/authorized-persons/' . $pickup . '/revoke', [], 'secretaire-a');
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', [
            'learner_id' => $this->ada['child'], 'pickup_id' => $pickup, 'origin' => 'offline', 'client_uuid' => 'hl-0001', 'occurred_at' => '2026-10-21T16:05:00+01:00',
        ], 'educatrice-a');
        self::assertSame(201, $status);
        self::assertSame('handed_over', $json['data']['status']);
        self::assertTrue($json['data']['stale_authorization']);
        $events = $this->events('handed_over');
        self::assertCount(1, $events);
        self::assertSame('1', (string) $events[0]['stale_authorization']);
        self::assertSame('2026-10-21T16:05:00+01:00', $events[0]['occurred_at']);
        self::assertNotSame([], $this->safety->sent, 'L’alerte part malgré l’enregistrement.');
    }

    public function testRemiseEnLigneUnAutreJourEstRefusee(): void
    {
        $pickup = $this->authorize($this->ada['child']);
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup, 'school_day' => '2026-10-20'], 'educatrice-a'));
    }

    public function testPersonneNonListeeEnregistreUnRefusEtAlerte(): void
    {
        [$status, $json] = $this->call('POST', '/nursery/pickup/refusals', ['learner_id' => $this->ada['child'], 'declared_name' => 'Homme inconnu (fictif)'], 'educatrice-a');
        self::assertSame(201, $status);
        self::assertSame('refused', $json['data']['status']);
        self::assertSame(2, $json['data']['alerts']['sent']);
        self::assertCount(1, $this->events('refused'));
        self::assertSame([], $this->events('handed_over'));
    }

    public function testLeMaintienALEcoleEstTrace(): void
    {
        $event = $this->ok('POST', '/nursery/pickup/holds', ['learner_id' => $this->ada['child'], 'remark' => 'Parent injoignable, enfant gardé par la directrice.'], 'educatrice-a');
        self::assertSame('kept_at_school', $event['event_type']);
        self::assertCount(1, $this->events('kept_at_school'));
    }

    public function testUneEducatriceDUneAutreSectionEstRefuseeEn403JournaliseEtUnAutreClientEn404(): void
    {
        $pickup = $this->authorize($this->ada['child']);
        self::assertSame(403, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-b'));
        self::assertContains('nursery.scope.denied', $this->audit->actions());
        self::assertSame(403, $this->code('POST', '/nursery/pickup/refusals', ['learner_id' => $this->ada['child']], 'educatrice-b'));
        self::assertSame([], $this->events('handed_over'));

        $this->grantAll(self::TB, 'educatrice-x', self::EB);
        self::assertSame(404, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-x', self::TB, self::EB));
    }

    public function testUnParentNePeutPasRemettreUnEnfant(): void
    {
        $pickup = $this->authorize($this->ada['child']);
        self::assertSame(403, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'parent-' . $this->ada['child']));
    }

    public function testUneAlerteNonRemiseNEstJamaisSilencieuse(): void
    {
        $failing = new class () implements SafetyMessenger {
            public function send(string $tenantId, string $personId, string $templateKey, array $variables = []): DeliveryResult
            {
                if ($personId === 'p-directrice') {
                    throw new \RuntimeException('passerelle SMS indisponible');
                }

                return new DeliveryResult(DeliveryResult::BLOCKED, null, 'no_contact');
            }
        };
        $this->bootPickup([SafetyMessenger::class => $failing]);
        [$status, $json] = $this->call('POST', '/nursery/pickup/refusals', ['learner_id' => $this->ada['child'], 'declared_name' => 'Inconnu'], 'educatrice-a');
        self::assertSame(201, $status);
        self::assertSame(['sent' => 0, 'queued' => 0, 'blocked' => 1, 'failed' => 1], $json['data']['alerts']);
        self::assertContains('nursery.alert.failed', $this->audit->actions());
        self::assertContains('nursery.alert.undelivered', $this->audit->actions());
        self::assertCount(1, $this->events('refused'), 'L’événement est conservé même si une alerte échoue.');
    }
}
