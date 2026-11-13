<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Canteen;

use Scholaris\Modules\Apps\PublicSchools\Canteen\Application\HealthAggregates;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * Lot P3, cantine et santé scolaire (T687) : repas par classe et par jour (signalement d'un effectif servi supérieur à la classe),
 * synthèse mensuelle, stock (sortie au-delà du solde refusée), campagnes en effectifs (traités ≤ consentements), directeur en
 * lecture seule, indicateur territorial sans nom. Données fictives.
 */
final class CanteenTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->actor('cook', 'p-cook', ['public_schools.canteen.write', 'public_schools.canteen.read'], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_canteen_manager'));
        $this->actor('nurse', 'p-nurse', ['public_schools.canteen.write', 'public_schools.canteen.read'], Scope::territory('arr-1', 'pub_school_health_agent', 't1', 'e1'));
        $this->permissions->grant('director', 'public_schools.canteen.read');
    }

    public function testRepasEtStocks(): void
    {
        [$denied] = $this->api('PUT', '/public/canteen/meals/2026-10-08', 'director', ['lines' => [['structure_id' => 'c1', 'served_count' => 3]]]);
        self::assertSame(403, $denied->getStatusCode(), 'Le directeur consulte sans saisir.');
        [$saved, $json] = $this->api('PUT', '/public/canteen/meals/2026-10-08', 'cook', ['lines' => [['structure_id' => 'c1', 'served_count' => 4], ['structure_id' => 'c2', 'served_count' => 2]]]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['served_above_headcount:c1'], $json['data']['warnings']);
        $this->api('PUT', '/public/canteen/meals/2026-10-08', 'cook', ['lines' => [['structure_id' => 'c1', 'served_count' => 3]]]);
        [, $summary] = $this->api('GET', '/public/canteen/summary?month=2026-10', 'director');
        self::assertSame(5, $summary['data']['served_total'], 'Une nouvelle saisie du jour remplace la précédente.');
        self::assertSame(1, $summary['data']['service_days']);

        $this->api('POST', '/public/canteen/stock', 'cook', ['item_label' => 'Riz', 'unit_label' => 'kg', 'direction' => 'in', 'quantity' => 50, 'moved_on' => '2026-10-01', 'source_label' => 'Programme fictif']);
        [$out] = $this->api('POST', '/public/canteen/stock', 'cook', ['item_label' => 'riz', 'unit_label' => 'kg', 'direction' => 'out', 'quantity' => 20, 'moved_on' => '2026-10-08']);
        self::assertSame(201, $out->getStatusCode(), (string) $out->getBody());
        [$tooMuch] = $this->api('POST', '/public/canteen/stock', 'cook', ['item_label' => 'Riz', 'unit_label' => 'kg', 'direction' => 'out', 'quantity' => 31, 'moved_on' => '2026-10-08']);
        self::assertSame(409, $tooMuch->getStatusCode());
        [, $balance] = $this->api('GET', '/public/canteen/stock/balance', 'director');
        self::assertSame(30, $balance['data']['items'][0]['balance']);
    }

    public function testCampagneDeSanteEnEffectifsAvecConsentements(): void
    {
        [$noConsent] = $this->api('POST', '/public/health-campaigns', 'nurse', ['campaign_kind' => 'deworming', 'held_on' => '2026-10-05', 'girls_count' => 20, 'boys_count' => 18, 'consents_count' => 30]);
        self::assertSame(422, $noConsent->getStatusCode(), 'Traités ≤ consentements.');
        [$created, $json] = $this->api('POST', '/public/health-campaigns', 'nurse', ['campaign_kind' => 'deworming', 'held_on' => '2026-10-05', 'girls_count' => 15, 'boys_count' => 12, 'consents_count' => 30, 'refusals_count' => 3]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(27, $json['data']['treated_count']);
        self::assertArrayNotHasKey('person_id', $json['data']);
        $counts = $this->container()->get(HealthAggregates::class)->countsFor('t1', 'e1', '2026-10-08');
        self::assertSame(['kind=deworming;sex=F' => 15, 'kind=deworming;sex=M' => 12], $counts);
        [$outsider] = $this->api('GET', '/public/health-campaigns', 'outsider');
        self::assertSame(404, $outsider->getStatusCode());
    }
}
