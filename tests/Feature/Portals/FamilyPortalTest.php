<?php

declare(strict_types=1);

namespace Tests\Feature\Portals;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Tests\Support\Portals\PortalsTestCase;

final class FamilyPortalTest extends PortalsTestCase
{
    protected function setUp(): void
    {
        $this->bootPortals();
        $this->attendance->set('t1', 'e1', 'c1', new AttendanceSummary(20, 4, 1, 2));
        $this->documents->add('t1', new IssuedDocument('d1', 'IPES-2026-0001', 'tok1', IssuedDocument::VALID, 'attestation', 'c1'));
        $this->documents->add('t1', new IssuedDocument('d2', 'IPES-2026-0002', 'tok2', IssuedDocument::REVOKED, 'attestation', 'c1'));
    }

    /** @return array<string, mixed> */
    private function family(): array
    {
        [$response, $json] = $this->api('/portal/family', 'parent');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    public function testUnParentVoitTousSesEnfantsDeTousLesEtablissementsEnUnSeulCompte(): void
    {
        $family = $this->family();

        self::assertSame(175000, $family['family_balance_xaf']);
        self::assertCount(2, $family['children']);
        self::assertSame(['e1'], array_column($family['children'][0]['enrollments'], 'establishment_id'));
        self::assertSame(['e2'], array_column($family['children'][1]['enrollments'], 'establishment_id'));
        self::assertSame('IPES Douala', $family['children'][0]['enrollments'][0]['establishment_name']);
    }

    public function testMoyenneRangAbsencesResultatsEtDocuments(): void
    {
        $child = $this->family()['children'][0];

        self::assertSame(13.5, $child['results']['latest']['average']);
        self::assertSame(4, $child['results']['latest']['rank']);
        self::assertSame(4, $child['attendance']['absences']);
        self::assertSame(1, $child['attendance']['unjustified_absences']);
        self::assertSame(['IPES-2026-0001'], array_column($child['documents']['items'], 'number'));
    }

    public function testEcheanceEtCahierDeVieIndisponiblesSansSource(): void
    {
        $family = $this->family();

        self::assertFalse($family['next_due']['available']);
        self::assertFalse($family['children'][0]['life_book']['available']);
    }

    public function testApprenantMajeurSansConsentementEstEnAccesRestreint(): void
    {
        $this->adults->set('c1', true);
        $child = $this->family()['children'][0];

        self::assertSame('restricted', $child['access']);
        self::assertArrayNotHasKey('results', $child);
        self::assertArrayNotHasKey('attendance', $child);
        self::assertArrayNotHasKey('documents', $child);
        self::assertSame(150000, $child['balance_xaf']);
    }

    public function testApprenantMajeurAvecConsentementEstVisible(): void
    {
        $this->adults->set('c1', true);
        $this->consents->grant('t1', 'c1', ConsentChecker::PARENT_ACCESS);

        self::assertSame('full', $this->family()['children'][0]['access']);
    }

    public function testBlocageFinancierMasqueResultatsEtDocumentsMaisPasLeSolde(): void
    {
        $this->debtors->block('t1', 'e1', 'c1', 'results');
        $this->debtors->block('t1', 'e1', 'c1', 'documents');
        $child = $this->family()['children'][0];

        self::assertTrue($child['results']['blocked']);
        self::assertSame([], $child['results']['items']);
        self::assertTrue($child['documents']['blocked']);
        self::assertSame(150000, $child['balance_xaf']);
    }

    public function testInscriptionNonActiveIgnoree(): void
    {
        $this->enrollments->add('t1', 'en3', 'c1', 'e2', 'c9', 'withdrawn');

        self::assertCount(1, $this->family()['children'][0]['enrollments']);
    }

    /** D1 (recette) : l'enfant inscrit sous condition est facturé : son inscription et son solde apparaissent au portail. */
    public function testInscriptionSousConditionVisibleAvecSonSolde(): void
    {
        $before = $this->family();
        $this->enrollments->add('t1', 'en4', 'c1', 'e2', 'c9', 'conditional');
        $this->debtors->setBalance('t1', 'e2', 'c1', 40000);
        $after = $this->family();

        $enrollments = $after['children'][0]['enrollments'];
        self::assertSame(['e1', 'e2'], array_column($enrollments, 'establishment_id'));
        self::assertSame(['active', 'conditional'], array_column($enrollments, 'status'));
        self::assertSame($before['children'][0]['balance_xaf'] + 40000, $after['children'][0]['balance_xaf']);
        self::assertSame($before['family_balance_xaf'] + 40000, $after['family_balance_xaf']);
    }

    public function testDeuxInscriptionsDansLeMemeEtablissementNeDoublentPasLeSolde(): void
    {
        $before = $this->family()['children'][0]['balance_xaf'];
        $this->enrollments->add('t1', 'en5', 'c1', 'e1', 'c8', 'active');

        self::assertSame($before, $this->family()['children'][0]['balance_xaf']);
    }

    public function testCompteSansPersonneEstRefuse(): void
    {
        $response = $this->api('/portal/family', 'orphelin')[0];

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSansAuthentification401(): void
    {
        self::assertSame(401, $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/portal/family'))->getStatusCode());
    }
}
