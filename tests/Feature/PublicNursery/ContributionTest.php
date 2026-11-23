<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Apps\PublicNursery\Contribution\Application\ContributionService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\PublicNursery\Support\PublicNurseryCase;

/**
 * Contribution exigible de la maternelle publique (circulaire du MINEDUB, recherche-minedub §2) : 7 500 FCFA par défaut, une tranche,
 * contre reçu numéroté du module Documents, quotas paramétrables, souches conservées après annulation ; plafond de 30 enfants par classe.
 */
final class ContributionTest extends PublicNurseryCase
{
    private string $child;

    protected function setUp(): void
    {
        $this->bootPublicNursery();
        $this->grantAll('director', 'public_schools.school.write');
        $this->grantAll('teacher', 'public_nursery.dev.observe');
        $this->child = (string) $this->person('Lina', 'Fictive')['id'];
        $this->enrol($this->child);
    }

    public function testValeursDeDepartOfficiellesEtParametrees(): void
    {
        $summary = $this->ok('GET', '/public-nursery/contributions/summary', 'director');
        self::assertSame(7500, $summary['rules']['amount_xaf']);
        self::assertSame(1, $summary['rules']['installments']);
        self::assertSame([], $summary['rules']['quotas'], 'Quotas à confirmer : aucun pourcentage inventé.');
        self::assertStringContainsString('1996', (string) $summary['rules']['source']);
        self::assertSame(7500, $summary['expected_xaf']);
        self::assertSame(1, $summary['unpaid']);
    }

    public function testEncaissementContreRecuUneSeuleTrancheEtSoucheConserveeApresAnnulation(): void
    {
        $receipt = $this->ok('POST', '/public-nursery/contributions', 'director', ['learner_id' => $this->child, 'payer_name' => 'Parent fictif', 'paid_on' => '2026-09-07']);
        self::assertSame(7500, $receipt['amount_xaf']);
        self::assertNotSame('', $receipt['receipt_number']);
        self::assertStringStartsWith('/api/v1/public/verify/', $receipt['verification_path']);
        $document = $this->connections()->pdo()->query("SELECT type_code, status, content FROM documents WHERE id = '" . $receipt['document_id'] . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($document);
        self::assertSame('pn_contribution_receipt', $document['type_code']);
        self::assertStringContainsString('7 500', (string) $document['content']);
        self::assertSame(409, $this->code('POST', '/public-nursery/contributions', 'director', self::T, self::E, ['learner_id' => $this->child, 'payer_name' => 'Parent fictif']), 'Une seule tranche.');

        self::assertSame(422, $this->code('POST', '/public-nursery/contributions/' . $receipt['id'] . '/cancel', 'director', self::T, self::E, []), 'Annulation motivée.');
        $cancelled = $this->ok('POST', '/public-nursery/contributions/' . $receipt['id'] . '/cancel', 'director', ['reason' => 'Erreur de saisie (fictive).']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('revoked', $this->connections()->pdo()->query("SELECT status FROM documents WHERE id = '" . $receipt['document_id'] . "'")->fetchColumn());
        $stubs = $this->ok('GET', '/public-nursery/contributions', 'director');
        self::assertCount(1, $stubs, 'La souche reste.');
        $again = $this->ok('POST', '/public-nursery/contributions', 'director', ['learner_id' => $this->child, 'payer_name' => 'Parent fictif']);
        self::assertNotSame($receipt['receipt_number'], $again['receipt_number']);
        self::assertSame(0, $this->ok('GET', '/public-nursery/contributions/summary', 'director')['unpaid']);
    }

    public function testQuotasParametresEtMontantModifiable(): void
    {
        $this->setting('public_nursery.contribution.amount_xaf', 9000);
        $this->setting('public_nursery.contribution.quotas', [['code' => 'A', 'label' => 'Quota A (exemple)', 'percent' => 60], ['code' => 'B', 'label' => 'Quota B (exemple)', 'percent' => 40]]);
        $receipt = $this->ok('POST', '/public-nursery/contributions', 'director', ['learner_id' => $this->child, 'payer_name' => 'Parent fictif']);
        self::assertSame(9000, $receipt['amount_xaf']);
        self::assertSame([5400, 3600], array_column($receipt['split']['lines'], 'amount_xaf'));
        $summary = $this->ok('GET', '/public-nursery/contributions/summary', 'director');
        self::assertTrue($summary['quotas_configured']);
        self::assertSame(9000, $summary['collected_xaf']);
        self::assertSame(0, $summary['split']['unallocated_xaf']);
        self::assertSame(['lines' => [['code' => 'X', 'label' => 'x', 'percent' => 33.0, 'amount_xaf' => 2475]], 'unallocated_xaf' => 5025], ContributionService::split(7500, [['code' => 'X', 'label' => 'x', 'percent' => 33.0]]));
        self::assertSame([], ContributionService::quotas([['code' => 'A', 'label' => 'a', 'percent' => 120]]), 'Total au-delà de 100 % refusé.');
    }

    public function testDroitsIsolationEtApplicationSuspendue(): void
    {
        self::assertSame(403, $this->code('POST', '/public-nursery/contributions', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'payer_name' => 'X']));
        self::assertSame(403, $this->code('GET', '/public-nursery/contributions/unpaid', 'teacher', self::T, self::E));
        $receipt = $this->ok('POST', '/public-nursery/contributions', 'director', ['learner_id' => $this->child, 'payer_name' => 'Parent fictif']);
        $this->grantAll('other-director', 'public_schools.school.write');
        self::assertSame(404, $this->code('POST', '/public-nursery/contributions/' . $receipt['id'] . '/cancel', 'other-director', 'tenant-other', 'est-other', ['reason' => 'Pirate']));
        self::assertSame([], $this->ok('GET', '/public-nursery/contributions', 'other-director', null, 'tenant-other', 'est-other')['data'] ?? []);
        self::assertSame(404, $this->code('POST', '/public-nursery/contributions', 'other-director', 'tenant-other', 'est-other', ['learner_id' => $this->child, 'payer_name' => 'X']), 'Enfant d’un autre client : introuvable.');
        $this->apps->become(ApplicationAccess::SUSPENDED);
        self::assertSame(423, $this->code('POST', '/public-nursery/contributions', 'director', self::T, self::E, ['learner_id' => $this->child, 'payer_name' => 'X']));
        self::assertSame(200, $this->code('GET', '/public-nursery/contributions/summary', 'director', self::T, self::E), 'Suspendu : lecture et export.');
    }

    public function testPlafondDeTrenteEnfantsParClasseAlerteSansBloquer(): void
    {
        $this->setting('public_nursery.class.max_children', 2);
        foreach (['Ama', 'Bea'] as $name) {
            $this->enrol((string) $this->person($name, 'Fictive')['id']);
        }
        $report = $this->ok('GET', '/public-nursery/sections/capacity', 'director');
        self::assertSame(2, $report['max_children']);
        self::assertSame(1, $report['over_capacity']);
        self::assertSame(3, $report['classes'][0]['enrolled']);
        self::assertSame(1, $report['classes'][0]['excess']);
        self::assertSame(30, $this->kernel->container()->get(\Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader::class)->value('tenant-other', 'est-other', 'public_nursery.class.max_children'), 'Valeur de départ : arrêté du 14 août 2026.');
        $this->scopes->restrictToClasses('teacher', 'section-b');
        self::assertSame([], $this->ok('GET', '/public-nursery/sections/capacity', 'teacher')['classes'], 'Hors de sa section : rien.');
    }
}
