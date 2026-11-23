<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Admissions;

use Scholaris\Modules\Apps\Higher\Admissions\Application\DecisionService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Higher\Admissions\EntryTestCase;

/** ADM : campagnes, candidatures, pièces, frais de dossier, décisions (TST-02, RG-ADM-1 à 4), concours, classement, statistiques. */
final class AdmissionsTest extends EntryTestCase
{
    protected function setUp(): void
    {
        $this->bootEntry();
    }

    public function testOuvertureRefuseeSansAutorisationDuNiveau(): void
    {
        $id = (string) $this->ok('POST', '/higher/admissions/campaigns', [
            'level_id' => $this->org['l1'], 'academic_year_id' => 'AY-2030', 'opens_on' => '2026-03-01', 'closes_on' => '2026-10-31',
            'seats' => 10, 'application_fee_xaf' => 10000,
        ], self::SCOL)['id'];
        [$status, $problem] = $this->call('POST', '/higher/admissions/campaigns/' . $id . '/open', null, self::SCOL);

        self::assertSame(422, $status, 'Filière LMD sans tutelle pour AY-2030 (RG-ACA-2).');
        self::assertStringContainsString('RG-ACA-2', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
    }

    public function testCandidatureEnLigneCompleteAvecMobileMoneyPuisNot01EtDoc01(): void
    {
        $campaign = $this->openCampaign();
        $application = $this->ok('POST', '/higher/admissions/applications', ['campaign_id' => $campaign, 'diploma' => 'bac', 'diploma_series' => 'C', 'diploma_year' => 2026], self::CAND);
        self::assertSame(['online', 'draft', self::MARIE], [$application['channel'], $application['status'], $application['person_id']]);
        $id = (string) $application['id'];
        foreach ($application['documents'] as $document) {
            $this->ok('POST', '/higher/admissions/applications/' . $id . '/documents', ['requirement_code' => $document['requirement_code'], 'document_ref' => 'scan-1'], self::CAND);
        }
        $submitted = $this->ok('POST', '/higher/admissions/applications/' . $id . '/submit', null, self::CAND);
        self::assertSame(['submitted', 'unpaid'], [$submitted['status'], $submitted['fee']['status']]);
        self::assertSame('hi.dossier', $this->billing->charges[0]->lines[0]->feeCode);
        self::assertSame(10000, $this->billing->charges[0]->lines[0]->amountXaf);

        $pending = $this->ok('POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'orange_money', 'payer_phone' => '+237690000001'], self::CAND);
        self::assertSame(['submitted', 'pending'], [$pending['status'], $pending['fee']['status']]);
        $this->billing->confirm((string) $pending['fee']['payment_id']);
        $complete = $this->ok('POST', '/higher/admissions/applications/' . $id . '/fee-payment', [], self::CAND);

        self::assertSame(['complete', 'paid', false], [$complete['status'], $complete['fee']['status'], $complete['fee']['refundable']]);
        self::assertSame(['NOT-01'], $this->fakes->codesFor(self::MARIE));
        self::assertSame('DOC-01', $this->fakes->documents[0]['code']);
        self::assertNotNull($complete['acknowledgement_document_id']);
    }

    public function testTst02AdmisImpossibleSansPieceVerifiee(): void
    {
        $application = $this->completeApplication($this->openCampaign(), 'Tst');
        $id = (string) $application['id'];
        [$status, $problem] = $this->call('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'admitted', 'reason' => 'Bon dossier.'], self::DIR);

        self::assertSame(422, $status);
        self::assertStringContainsString('RG-ADM-1', (string) $problem['detail']);
        self::assertSame('complete', $this->ok('GET', '/higher/admissions/applications/' . $id, null, self::SCOL)['status']);

        $diploma = array_values(array_filter($application['documents'], static fn (array $d): bool => $d['requirement_code'] === 'diploma'))[0];
        [$status] = $this->call('POST', '/higher/admissions/applications/' . $id . '/documents/' . $diploma['id'] . '/verify', null, self::DIR);
        self::assertSame(403, $status, 'La vérification des pièces est faite par la scolarité.');
        $this->ok('POST', '/higher/admissions/applications/' . $id . '/documents/' . $diploma['id'] . '/verify', null, self::SCOL);
        $admitted = $this->ok('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'admitted', 'reason' => 'Bon dossier.'], self::DIR);

        self::assertSame('admitted', $admitted['status']);
        self::assertSame('admitted', $admitted['decisions'][0]['decision']);
        $person = (string) $admitted['person_id'];
        self::assertContains('NOT-03', $this->fakes->codesFor($person));
        self::assertContains('DOC-03', array_column($this->fakes->documents, 'code'));
    }

    public function testPrerequisNonSatisfaitsRefusentLAdmission(): void
    {
        $application = $this->completeApplication($this->openCampaign(), 'Bts', 'bts');
        [$status, $problem] = $this->call('POST', '/higher/admissions/applications/' . $application['id'] . '/decision', ['decision' => 'conditional', 'reason' => 'x'], self::DIR);

        self::assertSame(422, $status, 'Niveau 1 : baccalauréat ou GCE A Level exigé.');
        self::assertStringContainsString('Prérequis', (string) $problem['detail']);
    }

    public function testAdmisSousReserveExpireATrenteJoursPuisRefuseAvecNotification(): void
    {
        $application = $this->completeApplication($this->openCampaign(), 'Reserve');
        $id = (string) $application['id'];
        $conditional = $this->ok('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::DIR);
        self::assertSame(['conditional', '2026-11-12'], [$conditional['status'], $conditional['conditional_until']]);

        $decisions = $this->service(DecisionService::class);
        self::assertSame(0, $decisions->expireConditionals('2026-11-12')['expired']);
        $this->admissionsClock->freeze('2026-11-13');
        self::assertSame(1, $decisions->expireConditionals()['expired']);

        $rejected = $this->ok('GET', '/higher/admissions/applications/' . $id, null, self::SCOL);
        self::assertSame('rejected', $rejected['status']);
        self::assertSame('expiry', $rejected['decisions'][1]['source']);
        self::assertSame(['NOT-01', 'NOT-03', 'NOT-03'], $this->fakes->codesFor((string) $rejected['person_id']));
        self::assertContains('higher.admission.reserve_expired', array_column($this->audit->entries, 'action'));
    }

    public function testExpirationSauteUnClientSuspendu(): void
    {
        $application = $this->completeApplication($this->openCampaign(), 'Suspendu');
        $this->ok('POST', '/higher/admissions/applications/' . $application['id'] . '/decision', ['decision' => 'conditional', 'reason' => 'x'], self::DIR);
        $this->applications->set(self::T, ApplicationAccess::SUSPENDED);

        self::assertSame(['expired' => 0, 'skipped_tenants' => 1], $this->service(DecisionService::class)->expireConditionals('2027-01-01'));
        [$status] = $this->call('POST', '/higher/admissions/applications/' . $application['id'] . '/withdraw', ['reason' => 'x'], self::SCOL);
        self::assertSame(423, $status, 'Application suspendue : lecture seule (WriteGate).');
    }

    public function testAuDelaDesPlacesSeulementParDecisionTraceeDuDirecteur(): void
    {
        $campaign = $this->openCampaign(1);
        $first = $this->completeApplication($campaign, 'Premier');
        $second = $this->completeApplication($campaign, 'Second');
        $this->admit((string) $first['id']);
        $diploma = array_values(array_filter($second['documents'], static fn (array $d): bool => $d['requirement_code'] === 'diploma'))[0];
        $this->ok('POST', '/higher/admissions/applications/' . $second['id'] . '/documents/' . $diploma['id'] . '/verify', null, self::SCOL);

        [$status, $problem] = $this->call('POST', '/higher/admissions/applications/' . $second['id'] . '/decision', ['decision' => 'admitted', 'reason' => 'Bon dossier.'], self::DIR);
        self::assertSame(409, $status);
        self::assertStringContainsString('RG-ADM-3', (string) $problem['detail']);

        $admitted = $this->ok('POST', '/higher/admissions/applications/' . $second['id'] . '/decision', [
            'decision' => 'admitted', 'reason' => 'Bon dossier.', 'over_capacity_reason' => 'Salle agrandie, accord du directeur.',
        ], self::DIR);
        self::assertTrue($admitted['over_capacity']);
        self::assertSame('Salle agrandie, accord du directeur.', $admitted['decisions'][0]['over_capacity_reason']);
        $traces = array_values(array_filter($this->audit->entries, static fn (array $e): bool => $e['action'] === 'higher.admission.over_capacity'));
        self::assertCount(1, $traces);
        self::assertSame(self::DIR, $traces[0]['actor']);

        $stats = $this->ok('GET', '/higher/admissions/campaigns/' . $campaign . '/statistics', null, self::DIR);
        self::assertSame([1, 2, 0, 1, 20000], [$stats['seats'], $stats['seats_taken'], $stats['seats_left'], $stats['over_capacity_admissions'], $stats['fees_collected_xaf']]);
    }

    public function testFraisDeDossierNonRemboursablesSaufAnnulationDeLaCampagne(): void
    {
        $campaign = $this->openCampaign();
        $withdrawn = $this->ok('POST', '/higher/admissions/applications/' . $this->completeApplication($campaign, 'Retrait')['id'] . '/withdraw', ['reason' => 'Autre école.'], self::SCOL);
        self::assertSame(['withdrawn', false], [$withdrawn['status'], $withdrawn['fee']['refundable']]);

        $other = $this->completeApplication($campaign, 'Annulation');
        $this->ok('POST', '/higher/admissions/campaigns/' . $campaign . '/cancel', ['reason' => 'Filière suspendue.'], self::SCOL);
        $after = $this->ok('GET', '/higher/admissions/applications/' . $other['id'], null, self::SCOL);
        self::assertSame(['withdrawn', true], [$after['status'], $after['fee']['refundable']]);
    }

    public function testClassementAvecExAequoEtListeDAttente(): void
    {
        $campaign = $this->openCampaign(2);
        $ids = [];
        foreach (['A' => 1500, 'B' => 1200, 'C' => 1200, 'D' => 900] as $name => $score) {
            $ids[$name] = (string) $this->completeApplication($campaign, 'Rang' . $name)['id'];
            $this->ok('POST', '/higher/admissions/applications/' . $ids[$name] . '/score', ['score_hundredths' => $score], self::SCOL);
        }
        $ranking = $this->ok('POST', '/higher/admissions/campaigns/' . $campaign . '/ranking', null, self::SCOL);
        $byId = array_column($ranking, null, 'application_id');

        self::assertSame([1, 2, 2, 4], [$byId[$ids['A']]['rank_position'], $byId[$ids['B']]['rank_position'], $byId[$ids['C']]['rank_position'], $byId[$ids['D']]['rank_position']]);
        self::assertSame(['main', 'main', 'main', 'waiting'], [$byId[$ids['A']]['list_kind'], $byId[$ids['B']]['list_kind'], $byId[$ids['C']]['list_kind'], $byId[$ids['D']]['list_kind']]);
    }

    public function testConcoursConvocationsEtNumerosDeTable(): void
    {
        $campaign = $this->openCampaign(10, 5000, null, 'contest');
        $this->completeApplication($campaign, 'Concours1');
        $this->completeApplication($campaign, 'Concours2');
        $session = $this->ok('POST', '/higher/admissions/campaigns/' . $campaign . '/contest-sessions', [
            'contest_date' => '2026-10-20', 'center_name' => 'Douala', 'room_name' => 'A1', 'room_capacity' => 50,
        ], self::SCOL);
        $seats = $this->ok('POST', '/higher/admissions/contest-sessions/' . $session['id'] . '/convocations', null, self::SCOL);

        self::assertSame(['A1-001', 'A1-002'], array_column($seats, 'seat_number'));
        self::assertSame(2, count(array_filter($this->fakes->documents, static fn (array $d): bool => $d['code'] === 'DOC-02')));
        self::assertSame([], $this->ok('POST', '/higher/admissions/contest-sessions/' . $session['id'] . '/convocations', null, self::SCOL), 'Pas de double convocation.');
    }

    public function testCandidatureAuGuichetCreeLaFicheEtLeCandidatNeVoitQueLaSienne(): void
    {
        $campaign = $this->openCampaign();
        $counter = $this->completeApplication($campaign, 'Guichet');
        self::assertSame(['counter', 'p-new-guichet'], [$counter['channel'], $counter['person_id']]);

        [$status] = $this->call('GET', '/higher/admissions/applications/' . $counter['id'], null, self::CAND);
        self::assertSame(404, $status);
        $own = $this->ok('POST', '/higher/admissions/applications', ['campaign_id' => $campaign, 'diploma' => 'gce_al'], self::CAND);
        $list = $this->ok('GET', '/higher/admissions/applications', null, self::CAND);
        self::assertSame([$own['id']], array_column($list, 'id'));
    }

    public function testIsolationClientEtEcole(): void
    {
        $campaign = $this->openCampaign();
        $application = $this->completeApplication($campaign, 'Isole');
        $school = $this->otherSchool();
        foreach (['higher.application.manage', 'higher.admission.read'] as $permission) {
            $this->assign(self::T, self::SCOL_IT, self::E, $permission, 'org_unit', $school, 'higher_scolarite');
        }

        [$read] = $this->call('GET', '/higher/admissions/applications/' . $application['id'], null, self::SCOL_IT);
        [$write] = $this->call('POST', '/higher/admissions/applications/' . $application['id'] . '/score', ['score_hundredths' => 1000], self::SCOL_IT);
        [$tenant] = $this->call('GET', '/higher/admissions/campaigns/' . $campaign, null, self::ADMIN_B, self::TB, self::EB);
        self::assertSame([404, 403, 403], [$read, $write, $tenant]);
        $this->assign(self::TB, self::ADMIN_B, self::EB, 'higher.admission.read', 'establishment');
        [$tenant] = $this->call('GET', '/higher/admissions/campaigns/' . $campaign, null, self::ADMIN_B, self::TB, self::EB);
        self::assertSame(404, $tenant, 'Un identifiant d\'un autre client est introuvable.');
        self::assertSame([], $this->ok('GET', '/higher/admissions/applications', null, self::SCOL_IT));
    }

    private function otherSchool(): string
    {
        return (string) $this->ok('POST', '/higher/schools', ['code' => 'INF', 'name' => 'École d\'informatique'])['id'];
    }
}
