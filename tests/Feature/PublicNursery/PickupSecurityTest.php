<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;
use Tests\Feature\PublicNursery\Support\PublicNurseryCase;

/**
 * C-106 (T724, T725) : code à usage unique du jour (haché, une seule remise, blocage après les essais paramétrés, alerte), paquet du
 * matin sans code ni donnée médicale, lots hors ligne rejoués sans doublon, remise hors ligne avec autorisation périmée enregistrée et
 * alertée. Données fictives.
 */
final class PickupSecurityTest extends PublicNurseryCase
{
    private const DAY = '2026-10-08';

    private string $child;
    private string $parent;
    private string $director;
    private string $pickup;

    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'pn-pickup-code-test-secret';
        $this->bootPublicNursery();
        $this->grantAll('teacher', 'public_nursery.pickup.handover');
        $this->grantAll('director', 'public_schools.school.write');
        $this->child = (string) $this->person('Lina', 'Fictive')['id'];
        $this->parent = (string) $this->person('Mere', 'Fictive')['id'];
        $this->director = (string) $this->person('Dora', 'Directrice')['id'];
        $this->enrol($this->child);
        $this->linkParent($this->child, $this->parent);
        $this->assignDirector($this->director);
        $this->pickup = (string) $this->ok('POST', '/public-nursery/pickup/authorized-persons', 'teacher', [
            'learner_id' => $this->child, 'name' => 'Tante Rose', 'relationship' => 'tante', 'grant_type' => 'permanent',
        ])['id'];
    }

    /** @return array<string, mixed> */
    private function issue(): array
    {
        return $this->ok('POST', '/public-nursery/pickup/codes', 'teacher', ['learner_id' => $this->child, 'pickup_id' => $this->pickup, 'valid_on' => self::DAY]);
    }

    /** @return list<string> */
    private function sent(): array
    {
        return array_column($this->safety->sent, 'template');
    }

    public function testLeCodeEstRenduUneFoisHacheEtEnvoyeParSms(): void
    {
        $issued = $this->issue();
        self::assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        self::assertSame(['pn.pickup_code'], $this->sent());
        self::assertSame($issued['code'], $this->safety->sent[0]['variables']['reference']);
        $stored = (string) $this->connections()->pdo()->query("SELECT code_hash FROM pn_pickup_codes WHERE id = '" . $issued['id'] . "'")->fetchColumn();
        self::assertStringNotContainsString($issued['code'], $stored, 'Jamais conservé en clair.');
        self::assertSame(64, strlen($stored));
        self::assertSame(422, $this->code('POST', '/public-nursery/pickup/codes', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'pickup_id' => $this->pickup, 'valid_on' => '2026-10-07']), 'Pas de code pour un jour passé.');
        self::assertSame(404, $this->code('POST', '/public-nursery/pickup/codes', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'pickup_id' => '01UNKNOWN0000000000000000', 'valid_on' => self::DAY]));
    }

    public function testUnCodeValableUnJourPourUneSeuleRemise(): void
    {
        $issued = $this->issue();
        [$response, $json] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'code' => $issued['code'], 'school_day' => self::DAY]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('handed_over', $json['data']['status']);
        self::assertSame($issued['id'], $json['data']['event']['code_id']);
        self::assertSame('used', (string) $this->connections()->pdo()->query("SELECT status FROM pn_pickup_codes WHERE id = '" . $issued['id'] . "'")->fetchColumn());

        [$again, $refused] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'code' => $issued['code'], 'school_day' => self::DAY]);
        self::assertSame(422, $again->getStatusCode());
        self::assertSame('code_invalid', $refused['data']['reason'], 'Un code déjà utilisé ne remet plus l’enfant.');
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_gate_events WHERE event_type = 'handed_over'")->fetchColumn());

        $tomorrow = $this->ok('POST', '/public-nursery/pickup/codes', 'teacher', ['learner_id' => $this->child, 'pickup_id' => $this->pickup, 'valid_on' => '2026-10-09']);
        [$wrongDay, $wrong] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'code' => $tomorrow['code'], 'school_day' => self::DAY]);
        self::assertSame(422, $wrongDay->getStatusCode());
        self::assertSame('code_invalid', $wrong['data']['reason'], 'Un code du lendemain ne vaut pas aujourd’hui.');
    }

    public function testCinqCodesErronesBloquentEtAlertentLaDirection(): void
    {
        $issued = $this->issue();
        $this->safety->sent = [];
        $reasons = [];
        for ($i = 0; $i < 5; ++$i) {
            [, $json] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'code' => '00000' . $i, 'school_day' => self::DAY]);
            $reasons[] = $json['data']['reason'];
        }
        self::assertSame(['code_invalid', 'code_invalid', 'code_invalid', 'code_invalid', 'code_blocked'], $reasons);
        self::assertContains('pn.not07.director', $this->sent(), 'La direction est alertée.');
        [$late, $blocked] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'code' => $issued['code'], 'school_day' => self::DAY]);
        self::assertSame(422, $late->getStatusCode());
        self::assertSame('code_blocked', $blocked['data']['reason'], 'Même le bon code est refusé une fois bloqué.');
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_gate_events WHERE event_type = 'handed_over'")->fetchColumn());
    }

    public function testLeParentCreeLeCodeDuJourDeSonEnfantSeulement(): void
    {
        $now = self::NOW;
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['parent-user', self::T, $this->parent, 'fr', 'active', $now, $now]);
        $other = (string) $this->person('Eva', 'Etrangere')['id'];
        $this->enrol($other);

        $created = $this->ok('POST', '/public-nursery/portal/pickup-codes', 'parent-user', ['learner_id' => $this->child, 'pickup_id' => $this->pickup, 'valid_on' => self::DAY]);
        self::assertMatchesRegularExpression('/^\d{6}$/', $created['code']);
        self::assertSame(404, $this->code('POST', '/public-nursery/portal/pickup-codes', 'parent-user', self::T, self::E, ['learner_id' => $other, 'pickup_id' => $this->pickup, 'valid_on' => self::DAY]), 'Pas l’enfant d’une autre famille.');
        self::assertSame(422, $this->code('POST', '/public-nursery/portal/pickup-codes', 'parent-user', self::T, self::E, ['learner_id' => $this->child, 'valid_on' => self::DAY]));
        self::assertSame(422, $this->code('POST', '/public-nursery/portal/pickup-codes', 'parent-user', self::T, self::E, ['learner_id' => $this->child, 'pickup_id' => $this->pickup, 'valid_on' => '2026-10-01']));
    }

    public function testLePaquetDuMatinNeContientNiCodeNiDonneeMedicaleEtRespecteLaPortee(): void
    {
        $this->issue();
        $package = $this->ok('GET', '/public-nursery/pickup/package?date=' . self::DAY, 'teacher');
        self::assertSame(self::DAY, $package['date']);
        self::assertCount(1, $package['children']);
        self::assertSame('Tante Rose', $package['children'][0]['authorized'][0]['name']);
        $json = (string) json_encode($package);
        foreach (['code', 'allerg', 'medical', 'phone'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $json, 'Le paquet ne porte pas : ' . $forbidden);
        }
        $this->scopes->restrictToClasses('teacher', 'section-b');
        self::assertSame([], $this->ok('GET', '/public-nursery/pickup/package?date=' . self::DAY, 'teacher')['children'], 'Hors de sa section : aucun enfant.');
        self::assertSame(422, $this->code('GET', '/public-nursery/pickup/package?date=hier', 'teacher', self::T, self::E));
    }

    public function testLeLotHorsLigneEstRejoueSansDoublonEtLaRemisePerimeeEstEnregistreeEtAlertee(): void
    {
        $revoked = (string) $this->ok('POST', '/public-nursery/pickup/authorized-persons', 'teacher', [
            'learner_id' => $this->child, 'name' => 'Oncle Marc', 'relationship' => 'oncle', 'grant_type' => 'permanent',
        ])['id'];
        $this->ok('POST', '/public-nursery/pickup/authorized-persons/' . $revoked . '/revoke', 'teacher');
        $this->safety->sent = [];

        $arrival = new BatchItem('public_nursery.pickup', 'item-0001', ['type' => 'arrival', 'learner_id' => $this->child, 'school_day' => self::DAY, 'occurred_at' => '2026-10-08T07:40:00+01:00']);
        self::assertSame(ItemResult::APPLIED, $this->handler()->handle($arrival, $this->context())->status);
        self::assertSame(ItemResult::DUPLICATE, $this->handler()->handle($arrival, $this->context())->status);

        $good = new BatchItem('public_nursery.pickup', 'item-0002', ['type' => 'handover', 'learner_id' => $this->child, 'pickup_id' => $this->pickup, 'school_day' => self::DAY]);
        $first = $this->handler()->handle($good, $this->context());
        self::assertSame(ItemResult::APPLIED, $first->status);
        self::assertSame('handed_over', $first->data['status']);
        self::assertSame(ItemResult::DUPLICATE, $this->handler()->handle($good, $this->context())->status);
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_gate_events WHERE event_type = 'handed_over'")->fetchColumn());
        self::assertSame('offline', (string) $this->connections()->pdo()->query("SELECT origin FROM pn_gate_events WHERE event_type = 'handed_over'")->fetchColumn());

        $stale = $this->handler()->handle(new BatchItem('public_nursery.pickup', 'item-0003', ['type' => 'handover', 'learner_id' => $this->child, 'pickup_id' => $revoked, 'school_day' => self::DAY]), $this->context());
        self::assertSame(ItemResult::APPLIED, $stale->status);
        self::assertTrue($stale->data['stale_authorization'], 'Remise faite avec le paquet du matin puis autorisation retirée.');
        self::assertSame('handed_over', $stale->data['status']);
        self::assertContains('pn.not07.director', $this->sent(), 'Alerte de la direction.');
        $audit = $this->ok('GET', '/public-nursery/register/audit?date=' . self::DAY, 'director');
        self::assertFalse($audit['ok'], 'Le contrôle O7 signale la remise à autorisation périmée.');

        $refusal = $this->handler()->handle(new BatchItem('public_nursery.pickup', 'item-0004', ['type' => 'refusal', 'learner_id' => $this->child, 'declared_name' => 'Inconnu', 'school_day' => self::DAY]), $this->context());
        self::assertSame('refused', $refusal->data['status']);
        $hold = $this->handler()->handle(new BatchItem('public_nursery.pickup', 'item-0005', ['type' => 'hold', 'learner_id' => $this->child, 'remark' => 'Parent prévenu (fictif).', 'school_day' => self::DAY]), $this->context());
        self::assertSame(ItemResult::APPLIED, $hold->status);
    }

    public function testLeLotRefuseUnCodeHorsLigneUnTypeInconnuEtLesDroits(): void
    {
        foreach ([
            ['type' => 'handover', 'learner_id' => $this->child, 'pickup_id' => $this->pickup, 'code' => '123456'],
            ['type' => 'teleport', 'learner_id' => $this->child],
            ['type' => 'hold', 'learner_id' => $this->child],
            ['type' => 'arrival'],
        ] as $index => $payload) {
            try {
                $this->handler()->handle(new BatchItem('public_nursery.pickup', 'bad-' . $index, $payload), $this->context());
                self::fail('Élément invalide accepté : ' . json_encode($payload));
            } catch (\Scholaris\Core\Http\HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
        try {
            $this->handler()->handle(new BatchItem('public_nursery.pickup', 'nope', ['type' => 'arrival', 'learner_id' => $this->child]), $this->context('outsider'));
            self::fail('Sans permission.');
        } catch (\Scholaris\Core\Http\HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->scopes->restrictToClasses('teacher', 'section-b');
        try {
            $this->handler()->handle(new BatchItem('public_nursery.pickup', 'scope', ['type' => 'arrival', 'learner_id' => $this->child]), $this->context());
            self::fail('Hors de sa section.');
        } catch (\Scholaris\Core\Http\HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $this->handler()->handle(new BatchItem('public_nursery.pickup', 'scope2', ['type' => 'handover', 'learner_id' => $this->child, 'pickup_id' => $this->pickup]), $this->context());
            self::fail('Remise hors de sa section.');
        } catch (\Scholaris\Core\Http\HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_gate_events WHERE event_type = 'handed_over'")->fetchColumn());
    }

    private function handler(): BatchItemHandler
    {
        $handler = $this->kernel->container()->get('sync.handlers.public_nursery.pickup');
        self::assertInstanceOf(BatchItemHandler::class, $handler);

        return $handler;
    }

    private function context(string $user = 'teacher'): BatchContext
    {
        return new BatchContext(new Principal($user, self::T), self::E, 'appareil-01', 'lot-01');
    }
}
