<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

use Scholaris\Core\Database\ConnectionFactory;

/** EX-MAT-14 (US6) : code du jour à usage unique, haché (HMAC), essais limités par paramètre, jamais affiché après création. */
final class CodesTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup();
    }

    /** @return array{code: string, pickup: string, id: string} */
    private function issue(string $name = 'Voisine (exemple)'): array
    {
        $pickup = $this->authorize($this->ada['child'], $name, 'day', '2026-10-21');
        $issued = $this->ok('POST', '/nursery/pickup/codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'secretaire-a');

        return ['code' => (string) $issued['code'], 'pickup' => $pickup, 'id' => (string) $issued['id']];
    }

    public function testLeCodeEstRenduUneSeuleFoisHacheEnBaseEtEnvoyeAuParent(): void
    {
        $issued = $this->issue();
        self::assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        $stored = $this->service(ConnectionFactory::class)->pdo()->query('SELECT code_hash, status FROM nu_pickup_codes')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertNotSame($issued['code'], $stored['code_hash']);
        self::assertSame(64, strlen((string) $stored['code_hash']));
        self::assertSame('issued', $stored['status']);
        self::assertContains($this->ada['parent'], $this->safety->recipients());
        self::assertSame('nursery.not05.code', $this->safety->sent[0]['template']);
        // Aucune route ne relit le code.
        self::assertStringNotContainsString($issued['code'], (string) json_encode($this->call('GET', '/nursery/pickup/authorized-persons?learner_id=' . $this->ada['child'], null, 'secretaire-a')[1]));
        self::assertSame('no-store', $this->raw('POST', '/nursery/pickup/codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $issued['pickup']], 'secretaire-a')->getHeaderLine('Cache-Control'));
    }

    public function testUnCodeValideRemetLEnfantUneSeuleFois(): void
    {
        $issued = $this->issue();
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => $issued['code']], 'educatrice-a');
        self::assertSame(201, $status);
        self::assertSame('handed_over', $json['data']['status']);
        self::assertSame($issued['pickup'], $this->events('handed_over')[0]['pickup_id']);
        self::assertNotNull($this->events('handed_over')[0]['code_id']);
        // Usage unique : le même code ne remet plus rien.
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => $issued['code']], 'educatrice-a'));
        self::assertCount(1, $this->events('handed_over'));
    }

    public function testUnCodeErroneEstCompteEtLeBlocageParametreAlerteLaDirection(): void
    {
        $issued = $this->issue();
        $attempts = 5; // valeur de départ posée en donnée plateforme (migration nursery_settings_defaults)
        for ($i = 1; $i < $attempts; ++$i) {
            [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => '00000' . $i], 'educatrice-a');
            self::assertSame(422, $status);
            self::assertSame('code_invalid', $json['code']);
        }
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => '000009'], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('code_blocked', $json['code']);
        self::assertContains('nursery.not04.refused_director', $this->safety->templates());
        // Même le bon code est refusé une fois le blocage posé.
        [, $after] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => $issued['code']], 'educatrice-a');
        self::assertSame('code_blocked', $after['code']);
        self::assertSame([], $this->events('handed_over'));
    }

    public function testLeNombreDEssaisVientDesParametresDeLEcole(): void
    {
        $this->setSetting(self::TA, self::EA, 'nursery.pickup.code_max_attempts', 2);
        $this->issue();
        self::assertSame('code_invalid', $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => '111111'], 'educatrice-a')[1]['code']);
        self::assertSame('code_blocked', $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => '222222'], 'educatrice-a')[1]['code']);
    }

    public function testRetirerLaPersonneInvalideSonCode(): void
    {
        $issued = $this->issue();
        $this->ok('POST', '/nursery/pickup/authorized-persons/' . $issued['pickup'] . '/revoke', [], 'secretaire-a');
        self::assertSame(422, $this->code('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => $issued['code']], 'educatrice-a'));
        self::assertSame([], $this->events('handed_over'));
    }

    public function testLeCodeDUneAutorisationRestreinteNeRemetPasLEnfant(): void
    {
        $issued = $this->issue('Papa visé (exemple)');
        $this->ok('POST', '/nursery/pickup/restrictions', [
            'learner_id' => $this->ada['child'], 'restricted_name' => 'Papa visé (exemple)', 'nature' => 'no_pickup', 'decision_reference' => 'JUG-2026-FICTIF-009', 'valid_from' => '2026-10-01',
        ]);
        [$status, $json] = $this->call('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'code' => $issued['code']], 'educatrice-a');
        self::assertSame(422, $status);
        self::assertSame('restricted', $json['code']);
    }

    public function testUnCodeNExistePasPourUneAutorisationInvalideOuUnAutreEnfant(): void
    {
        $other = $this->authorize($this->bao['child'], 'Marraine (exemple)', 'day', '2026-10-21');
        self::assertSame(404, $this->code('POST', '/nursery/pickup/codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $other], 'secretaire-a'));
        $tomorrow = $this->authorize($this->ada['child'], 'Voisin (exemple)', 'day', '2026-10-22');
        self::assertSame(422, $this->code('POST', '/nursery/pickup/codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $tomorrow], 'secretaire-a'));
        self::assertSame(201, $this->code('POST', '/nursery/pickup/codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $tomorrow, 'valid_on' => '2026-10-22'], 'secretaire-a'));
    }

    public function testLeParentCreeUnCodePourSonEnfantSeulement(): void
    {
        $pickup = $this->authorize($this->ada['child'], 'Voisine (exemple)', 'day', '2026-10-21');
        $issued = $this->ok('POST', '/nursery/portal/pickup-codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'parent-' . $this->ada['child']);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $issued['code']);
        self::assertSame(404, $this->code('POST', '/nursery/portal/pickup-codes', ['learner_id' => $this->bao['child'], 'pickup_id' => $pickup], 'parent-' . $this->ada['child']));
    }

    public function testSansCleDeSignatureLesCodesSontRefusesEn503(): void
    {
        $pickup = $this->authorize($this->ada['child'], 'Voisine (exemple)', 'day', '2026-10-21');
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = '';
        self::assertSame(503, $this->code('POST', '/nursery/pickup/codes', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'secretaire-a'));
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'nursery-test-secret-key';
    }
}
