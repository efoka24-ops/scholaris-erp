<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/** EX-MAT-13, 15 : registre du jour, paquet du matin hors ligne et gestionnaire du point d'entrée commun de synchronisation. */
final class RegisterAndPackageTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup([], '2026-10-21T16:20:00+01:00');
    }

    public function testLeRegistreDuJourMontreArriveesSortiesRefusEtEncoreALEcole(): void
    {
        $cleo = $this->world->child(self::TA, self::EA, $this->sectionA, 'Cleo');
        $this->ok('POST', '/nursery/sections/' . $this->sectionA . '/call', [
            'day_part' => 'morning', 'client_uuid' => 'appel-0001', 'validate' => true, 'exceptions' => [['child_id' => $cleo['child'], 'status' => 'A']],
        ], 'educatrice-a');
        $pickup = $this->authorize($this->ada['child'], 'Tante Rose (exemple)');
        $this->ok('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a');
        $this->ok('POST', '/nursery/pickup/refusals', ['learner_id' => $this->ada['child'], 'declared_name' => 'Inconnu'], 'educatrice-a');

        $register = $this->ok('GET', '/nursery/register?date=2026-10-21');
        self::assertSame('2026-10-21', $register['date']);
        $rows = [];
        foreach ($register['sections'] as $section) {
            foreach ($section['children'] as $child) {
                $rows[$child['id']] = $child;
            }
        }
        self::assertSame('left', $rows[$this->ada['child']]['status']);
        self::assertSame('Tante Rose (exemple)', $rows[$this->ada['child']]['left_to']);
        self::assertSame(1, $rows[$this->ada['child']]['refusals']);
        self::assertSame('absent', $rows[$cleo['child']]['status']);
        self::assertSame('expected', $rows[$this->bao['child']]['status'], 'Section B : pas encore d’appel.');
        self::assertSame(1, $register['totals']['left']);
        self::assertSame(1, $register['totals']['absent']);
    }

    public function testLeRegistreNeMontreQueLePerimetreDeLEducatrice(): void
    {
        $register = $this->ok('GET', '/nursery/register', null, 'educatrice-a');
        self::assertSame([$this->sectionA], array_column($register['sections'], 'id'));
        self::assertSame(422, $this->code('GET', '/nursery/register?date=demain', null, 'educatrice-a'));
        self::assertSame(403, $this->code('GET', '/nursery/register', null, 'parent-' . $this->ada['child']));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame([], $this->ok('GET', '/nursery/register', null, 'directrice-b', self::TB, self::EB)['sections']);
    }

    public function testLePaquetDuMatinPorteAutorisesRestrictionsDirectionEtParametresDeLAge(): void
    {
        $pickup = $this->authorize($this->ada['child'], 'Tante Rose (exemple)');
        $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Papa visé (fictif)', 'nature' => 'no_pickup', 'decision_reference' => 'JUG-2026-FICTIF-002', 'valid_from' => '2026-10-01',
        ]);
        $package = $this->ok('GET', '/nursery/sections/' . $this->sectionA . '/pickup-package?date=2026-10-21', null, 'educatrice-a');
        self::assertSame($this->sectionA, $package['section_id']);
        self::assertSame(1, $package['max_age_days']);
        self::assertSame(30, $package['read_days']);
        self::assertSame('2026-10-21T16:20:00+01:00', $package['generated_at']);
        self::assertSame([$pickup], array_column($package['children'][0]['authorized'], 'id'));
        self::assertSame('Papa visé (fictif)', $package['children'][0]['restrictions'][0]['restricted_name']);
        self::assertSame('+237600000099', $package['direction'][0]['phone']);
        self::assertStringNotContainsString('code_hash', (string) json_encode($package), 'Aucune empreinte de code dans le paquet.');
        self::assertArrayNotHasKey('decision_reference', $package['children'][0]['restrictions'][0]);
    }

    public function testLePaquetEstRefuseAuxAutresSectionsEtAuxAutresClients(): void
    {
        self::assertSame(404, $this->code('GET', '/nursery/sections/' . $this->sectionA . '/pickup-package', null, 'educatrice-b'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('GET', '/nursery/sections/' . $this->sectionA . '/pickup-package', null, 'directrice-b', self::TB, self::EB));
    }

    private function handler(): BatchItemHandler
    {
        $handler = $this->kernel->container()->get('sync.handlers.nursery.pickup');
        self::assertInstanceOf(BatchItemHandler::class, $handler);

        return $handler;
    }

    private function context(string $user = 'educatrice-a'): BatchContext
    {
        return new BatchContext(new Principal($user, self::TA), self::EA, 'appareil-01', 'lot-01');
    }

    public function testLeGestionnaireDeSynchronisationRejoueSansDoublonEtMarqueLesAutorisationsPerimees(): void
    {
        $good = $this->authorize($this->ada['child'], 'Tante Rose (exemple)');
        $revoked = $this->authorize($this->ada['child'], 'Oncle (exemple)');
        $this->ok('POST', '/nursery/pickup/authorized-persons/' . $revoked . '/revoke', [], 'secretaire-a');

        $item = new BatchItem('nursery.pickup', 'item-0001', ['type' => 'handover', 'learner_id' => $this->ada['child'], 'pickup_id' => $good, 'occurred_at' => '2026-10-21T15:58:00+01:00']);
        self::assertSame(ItemResult::APPLIED, $this->handler()->handle($item, $this->context())->status);
        self::assertSame(ItemResult::DUPLICATE, $this->handler()->handle($item, $this->context())->status);
        self::assertCount(1, $this->events('handed_over'));
        self::assertSame('offline', $this->events('handed_over')[0]['origin']);

        $stale = new BatchItem('nursery.pickup', 'item-0002', ['type' => 'handover', 'learner_id' => $this->ada['child'], 'pickup_id' => $revoked]);
        $result = $this->handler()->handle($stale, $this->context());
        self::assertSame(ItemResult::APPLIED, $result->status);
        self::assertTrue($result->data['stale_authorization']);
        self::assertNotSame([], $this->safety->sent);

        $refusal = $this->handler()->handle(new BatchItem('nursery.pickup', 'item-0003', ['type' => 'refusal', 'learner_id' => $this->ada['child'], 'declared_name' => 'Inconnu']), $this->context());
        self::assertSame('refused', $refusal->data['status']);
        $hold = $this->handler()->handle(new BatchItem('nursery.pickup', 'item-0004', ['type' => 'hold', 'learner_id' => $this->ada['child'], 'remark' => 'Gardé à l’école.']), $this->context());
        self::assertSame(ItemResult::APPLIED, $hold->status);
    }

    public function testLeGestionnaireControleLeDroitDeLaSectionEtLeContenu(): void
    {
        $item = new BatchItem('nursery.pickup', 'item-0009', ['type' => 'refusal', 'learner_id' => $this->ada['child']]);
        try {
            $this->handler()->handle($item, $this->context('educatrice-b'));
            self::fail('Une éducatrice d’une autre section ne doit pas écrire.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $this->handler()->handle(new BatchItem('nursery.pickup', 'item-0010', ['type' => 'inconnu', 'learner_id' => $this->ada['child']]), $this->context());
            self::fail('Un type inconnu doit être refusé.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        try {
            $this->handler()->handle(new BatchItem('nursery.pickup', 'item-0011', ['type' => 'handover', 'learner_id' => $this->ada['child'], 'nom_libre' => 'Quelqu’un']), $this->context());
            self::fail('Un champ inconnu doit être refusé.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame([], $this->events('handed_over'));
    }
}
