<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Scholaris\Modules\Socle\Billing\Contracts\FeeItemDraft;
use Scholaris\Modules\Socle\Billing\Contracts\FeeItemGuard;
use Tests\Support\Billing\BillingTestCase;

/** Garde de création de frais (SS8) : catalogue fictif d'un établissement public, refus 422 `fee_not_authorized`. */
final class FeeItemGuardTest extends BillingTestCase
{
    /** @var list<FeeItemDraft> */
    public static array $seen = [];

    private function bootWithGuard(): void
    {
        self::$seen = [];
        $guard = new class () implements FeeItemGuard {
            public function refusal(FeeItemDraft $draft): ?string
            {
                FeeItemGuardTest::$seen[] = $draft;
                if ($draft->establishmentId !== 'e1') {
                    return null;
                }

                return in_array($draft->code, ['apee', 'insc'], true) && $draft->amountXaf <= 25000
                    ? null
                    : 'Frais absent du catalogue autorisé de l\'établissement.';
            }
        };
        $this->bootBilling([], ['billing.fee_item_guards.test' => [$guard]]);
    }

    public function testFraisHorsCatalogueRefuse(): void
    {
        $this->bootWithGuard();

        [$response, $json] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'other', 'name' => 'Frais de photocopie', 'amount_xaf' => 2000]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('fee_not_authorized', $json['code']);
        self::assertStringContainsString('catalogue autorisé', $json['detail']);
        self::assertSame([], $this->rows('SELECT id FROM fee_items'), 'Rien n\'est enregistré.');
        self::assertTrue(self::$seen[0]->isCreation());
    }

    public function testFraisDuCatalogueAccepteEtModificationControlee(): void
    {
        $this->bootWithGuard();

        [$created, $json] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'other', 'code' => 'apee', 'name' => 'APEE', 'amount_xaf' => 10000]);
        self::assertSame(201, $created->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        $id = (string) $json['data']['id'];

        [$refused, $error] = $this->api('PATCH', '/fee-items/' . $id, 'manager', ['amount_xaf' => 30000]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('fee_not_authorized', $error['code']);
        $draft = self::$seen[count(self::$seen) - 1];
        self::assertSame($id, $draft->feeItemId);
        self::assertSame('apee', $draft->code, 'La garde reçoit l\'état complet après modification.');
        self::assertSame(30000, $draft->amountXaf);

        [$ok] = $this->api('PATCH', '/fee-items/' . $id, 'manager', ['amount_xaf' => 12000]);
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testLaGardeIgnoreLesAutresEtablissements(): void
    {
        $this->bootWithGuard();

        [$response] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'other', 'name' => 'Frais de photocopie', 'amount_xaf' => 2000], 't1', 'e2');

        self::assertSame(201, $response->getStatusCode());
    }

    public function testSansGardeLeComportementEstInchange(): void
    {
        $this->bootBilling();

        [$response] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'other', 'name' => 'Frais de photocopie', 'amount_xaf' => 2000]);

        self::assertSame(201, $response->getStatusCode());
    }
}
