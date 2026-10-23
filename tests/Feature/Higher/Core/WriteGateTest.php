<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Http\Support\MeController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;
use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Higher\HigherTestCase;

/**
 * Lien avec Platform (wave-plan 0.4) : essai, actif et impayé dans le délai de grâce écrivent ; suspendu lit et exporte mais
 * n'écrit pas (423, « abonnement suspendu : lecture et export seulement ») ; fermé ou jamais ouvert : ni lecture (404) ni écriture (403).
 * L'écriture HTTP est contrôlée par HigherRoutes AVANT le contrôleur (route d'écriture de test servie par MeController).
 */
final class WriteGateTest extends HigherTestCase
{
    protected function setUp(): void
    {
        $this->bootHigher();
        (new HigherRoutes($this->kernel->routes()))->post('/_test/write', [MeController::class, 'show']);
        $this->assign(Org::TENANT_A, 'dg', Org::ESTABLISHMENT_A, 'higher.access', 'establishment');
    }

    /** @return array<string, array{0: ?string, 1: int, 2: int}> état => [statut GET, statut écriture] */
    public static function states(): array
    {
        return [
            'essai' => [ApplicationAccess::TRIAL, 200, 200],
            'actif' => [ApplicationAccess::ACTIVE, 200, 200],
            'impayé dans le délai de grâce' => [ApplicationAccess::PAST_DUE, 200, 200],
            'suspendu : lecture seule' => [ApplicationAccess::SUSPENDED, 200, 423],
            'fermé' => [ApplicationAccess::CLOSED, 404, 403],
            'jamais ouvert' => [null, 404, 403],
        ];
    }

    #[DataProvider('states')]
    public function testLEtatDuDroitDUsageCommandeLecturesEtEcritures(?string $state, int $read, int $write): void
    {
        $this->applications->set(Org::TENANT_A, $state);

        [$get] = $this->api('GET', '/higher/me', 'dg', Org::TENANT_A, Org::ESTABLISHMENT_A);
        [$post, $problem] = $this->api('POST', '/higher/_test/write', 'dg', Org::TENANT_A, Org::ESTABLISHMENT_A, []);

        self::assertSame($read, $get->getStatusCode());
        self::assertSame($write, $post->getStatusCode());
        if ($write === 423) {
            self::assertSame(WriteGate::SUSPENDED_TYPE, $problem['type']);
            self::assertStringContainsString('lecture et export seulement', (string) $problem['detail']);
            self::assertStringStartsWith('application/problem+json', $post->getHeaderLine('Content-Type'));
        }
    }

    public function testLaLectureDUnClientSuspenduSignaleQuIlNEstPlusModifiable(): void
    {
        $this->applications->set(Org::TENANT_A, ApplicationAccess::SUSPENDED);

        [, $json] = $this->api('GET', '/higher/me', 'dg', Org::TENANT_A, Org::ESTABLISHMENT_A);

        self::assertSame('suspended', $json['data']['application']['state']);
        self::assertFalse($json['data']['application']['writable']);
    }

    public function testUneTachePlanifieeSauteLeClientSuspenduEtRefuseDEcrireSansLui(): void
    {
        $this->applications->set(Org::TENANT_B, ApplicationAccess::SUSPENDED);
        $gate = $this->service(WriteGate::class);

        self::assertTrue($gate->allowsWrites(Org::TENANT_A));
        self::assertFalse($gate->allowsWrites(Org::TENANT_B));
        self::assertTrue($gate->allowsReads(Org::TENANT_B));
        $gate->assertWritable(Org::TENANT_A);

        try {
            $gate->assertWritable(Org::TENANT_B);
            self::fail('Une tâche planifiée ne doit pas écrire pour un client suspendu.');
        } catch (HttpException $e) {
            self::assertSame(423, $e->status);
        }
    }

    public function testUnClientNePeutPasEcrireAvecLAbonnementDUnAutre(): void
    {
        $this->applications->set(Org::TENANT_A, ApplicationAccess::ACTIVE);
        $this->applications->set(Org::TENANT_B, null);
        $this->assign(Org::TENANT_B, 'boss-b', Org::ESTABLISHMENT_B, 'higher.access', 'establishment');

        [$post] = $this->api('POST', '/higher/_test/write', 'boss-b', Org::TENANT_B, Org::ESTABLISHMENT_B, []);

        self::assertSame(403, $post->getStatusCode());
    }
}
