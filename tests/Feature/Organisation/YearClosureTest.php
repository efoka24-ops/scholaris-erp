<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Tests\Support\Organisation\ClosureDoubles;
use Tests\Support\Organisation\OrganisationTestCase;

/** Clôture d'année : contrôles, demande, approbation avec 2FA récente, étapes idempotentes, état fermé, journal. */
final class YearClosureTest extends OrganisationTestCase
{
    private ClosureDoubles $doubles;
    private string $year = '';
    private string $next = '';

    protected function setUp(): void
    {
        $this->doubles = new ClosureDoubles();
        $this->bootOrganisation([
            'organisation.closure.checks.test' => [$this->doubles],
            'organisation.closure.carry_over.test' => [$this->doubles],
            'organisation.closure.promotion.test' => [$this->doubles],
            // Les fournisseurs réels (Billing, Assessment, People) sont testés dans leurs modules : ici, seules les doublures répondent.
            'organisation.closure.checks.billing' => [],
            'organisation.closure.checks.assessment' => [],
            'organisation.closure.carry_over.billing' => [],
            'organisation.closure.promotion.people' => [],
        ]);
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'secondary', 'alice', 'bob', 'carol');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'secondary', 'alice', 'bob');
        $this->seedEstablishment('t2', 'x1', 'Étranger', 'secondary', 'mallory');
        foreach (['alice', 'bob', 'mallory'] as $user) {
            $this->grantAll($user);
        }
        $this->grantAll('carol', 'organisation.read');
        $this->secondFactor->allow('bob');
        $this->secondFactor->allow('mallory');
        $this->year = $this->api('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])[1]['data']['id'];
        $this->next = $this->api('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30'])[1]['data']['id'];
    }

    private function yearStatus(string $year = ''): string
    {
        return $this->api('GET', '/academic-years/' . ($year ?: $this->year), 'alice', 't1', 'e1')[1]['data']['status'];
    }

    public function testLeControleListeLesPointsBloquantsEtLesNonEvaluables(): void
    {
        $this->doubles->unpaid = 3;
        [$response, $body] = $this->api('GET', '/academic-years/' . $this->year . '/closure-check', 'alice', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode());
        $checks = array_column($body['data']['checks'], null, 'key');
        self::assertFalse($body['data']['ready']);
        self::assertSame('blocking', $checks['billing.unpaid']['status']);
        self::assertSame(3, $checks['billing.unpaid']['blocking_count']);
        self::assertSame('not_evaluable', $checks['assessment.grades_unlocked']['status']);
        self::assertSame('not_evaluable', $checks['assessment.deliberations_open']['status']);
        self::assertNotEmpty($checks['assessment.deliberations_open']['detail']);
        self::assertSame(2, $body['data']['not_evaluable_count']);

        $this->doubles->unpaid = 0;
        self::assertTrue($this->api('GET', '/academic-years/' . $this->year . '/closure-check', 'alice', 't1', 'e1')[1]['data']['ready']);
    }

    public function testUneSourceEnErreurDevientNonEvaluableSansBloquer(): void
    {
        $this->doubles->failChecks = true;
        $data = $this->api('GET', '/academic-years/' . $this->year . '/closure-check', 'alice', 't1', 'e1')[1]['data'];
        $checks = array_column($data['checks'], null, 'key');
        self::assertSame('not_evaluable', $checks['billing.unpaid']['status']);
        self::assertTrue($data['ready']);
    }

    public function testDesImpayesBloquentLaDemande(): void
    {
        $this->doubles->unpaid = 5;
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Impayés (5)', $body['detail']);
        self::assertSame('open', $this->yearStatus());
    }

    public function testDemandePuisApprobationParUneAutrePersonneAvecDeuxFacteurs(): void
    {
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => $this->next]);
        self::assertSame(202, $response->getStatusCode(), json_encode($body));
        self::assertSame('pending_approval', $body['data']['status']);
        self::assertSame('open', $this->yearStatus());

        // le demandeur ne peut pas approuver (et n'a de toute façon pas la 2FA)
        self::assertSame(403, $this->code('POST', '/academic-years/' . $this->year . '/close/approve', 'alice', 't1', 'e1'));
        // un autre responsable sans 2FA récente : refusé
        $this->secondFactor->revoke('bob');
        self::assertSame(403, $this->code('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1'));
        self::assertSame('open', $this->yearStatus());

        $this->secondFactor->allow('bob');
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode(), json_encode($body));
        self::assertSame('closed', $body['data']['status']);
        self::assertSame('done', $body['data']['steps']['archive']['status']);
        self::assertSame('done', $body['data']['steps']['carry_over']['status']);
        self::assertSame(12, $body['data']['steps']['carry_over']['counts']['balances']);
        self::assertSame(40, $body['data']['steps']['promotion']['counts']['promoted']);
        self::assertSame('closed', $this->yearStatus());
        self::assertSame('open', $this->yearStatus($this->next));
    }

    public function testLApprobationEstIdempotenteEtLaDemandeNeSeDuppliquePas(): void
    {
        $first = $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => $this->next]);
        $again = $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => $this->next]);
        self::assertSame(200, $again[0]->getStatusCode());
        self::assertSame($first[1]['data']['id'], $again[1]['data']['id']);
        self::assertSame($first[1]['data']['approval_id'], $again[1]['data']['approval_id']);

        $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1');
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('closed', $body['data']['status']);
        self::assertSame(1, $this->doubles->carryCalls, 'le report ne se rejoue pas');
        self::assertSame(1, $this->doubles->promoteCalls);
        $archives = $this->connections()->pdo()->query('SELECT COUNT(*) FROM year_archives')->fetchColumn();
        self::assertSame(1, (int) $archives);
        // une année close ne peut plus faire l'objet d'une demande
        self::assertSame(409, $this->code('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1'));
    }

    public function testUneEtapeEnEchecReprendSansRefaireLesAutres(): void
    {
        $this->doubles->failPromotion = true;
        $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => $this->next]);
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('promotion', $body['detail']);
        self::assertSame('open', $this->yearStatus());
        self::assertSame(1, $this->doubles->carryCalls);

        $this->doubles->failPromotion = false;
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode(), json_encode($body));
        self::assertSame('closed', $body['data']['status']);
        self::assertSame(1, $this->doubles->carryCalls, 'le report déjà fait n\'est pas rejoué');
        self::assertSame(2, $this->doubles->promoteCalls);
    }

    public function testSansAnneeSuivanteOuSansFournisseurLesEtapesSontExpliquees(): void
    {
        $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1');
        $steps = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1')[1]['data']['steps'];
        self::assertSame('skipped', $steps['carry_over']['status']);
        self::assertSame('skipped', $steps['promotion']['status']);
        self::assertSame(0, $this->doubles->carryCalls);
    }

    public function testRefusPuisNouvelleDemande(): void
    {
        $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1');
        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close/reject', 'bob', 't1', 'e1');
        self::assertSame('rejected', $body['data']['status']);
        self::assertSame(409, $this->code('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1'));
        self::assertSame(202, $this->code('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnneeSuivanteInvalide(): void
    {
        self::assertSame(422, $this->code('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => 'inconnue']));
        self::assertSame(422, $this->code('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => $this->year]));
        self::assertSame(422, $this->code('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['autre' => 1]));
    }

    public function testApresLaClotureLesEcrituresDeCetteAnneeSontRefusees(): void
    {
        $this->closeYear($this->year, 'e1');
        self::assertSame(409, $this->code('PATCH', '/academic-years/' . $this->year, 'alice', 't1', 'e1', ['label' => 'Nouveau']));
        self::assertSame(409, $this->code('POST', '/periods', 'alice', 't1', 'e1', [
            'academic_year_id' => $this->year, 'kind' => 'trimester', 'ordinal' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-20',
        ]));
        // l'année suivante reste modifiable
        self::assertSame(200, $this->code('PATCH', '/academic-years/' . $this->next, 'alice', 't1', 'e1', ['label' => '2027/28']));
    }

    public function testJournalEtArchive(): void
    {
        $this->closeYear($this->year, 'e1');
        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE action LIKE 'academic_year.%' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('academic_year.closure_requested', $actions);
        self::assertContains('academic_year.closure_step', $actions);
        self::assertContains('academic_year.closed', $actions);
        $snapshot = (string) $this->connections()->pdo()->query('SELECT snapshot FROM year_archives')->fetchColumn();
        self::assertSame('2026-2027', json_decode($snapshot, true)['year']['label']);
    }

    public function testDroitsEtIsolation(): void
    {
        // lecture seule : ni contrôle, ni demande
        self::assertSame(403, $this->code('GET', '/academic-years/' . $this->year . '/closure-check', 'carol', 't1', 'e1'));
        self::assertSame(403, $this->code('POST', '/academic-years/' . $this->year . '/close', 'carol', 't1', 'e1'));
        // autre établissement du même client, autre client
        foreach ([['bob', 't1', 'e2'], ['mallory', 't2', 'x1']] as [$user, $tenant, $establishment]) {
            self::assertSame(404, $this->code('GET', '/academic-years/' . $this->year . '/closure-check', $user, $tenant, $establishment));
            self::assertSame(404, $this->code('POST', '/academic-years/' . $this->year . '/close', $user, $tenant, $establishment));
            self::assertSame(404, $this->code('POST', '/academic-years/' . $this->year . '/close/approve', $user, $tenant, $establishment));
            self::assertSame(404, $this->code('POST', '/academic-years/' . $this->year . '/close/reject', $user, $tenant, $establishment));
        }
        self::assertSame('open', $this->yearStatus());
    }
}
