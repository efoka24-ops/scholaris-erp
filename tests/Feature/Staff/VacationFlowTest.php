<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;
use Tests\Support\Staff\StaffTestCase;

/** Contrats, heures réalisées, relevés mensuels : de la saisie au paiement. */
final class VacationFlowTest extends StaffTestCase
{
    /** @var array<string, mixed> */
    private array $vac = [];

    protected function setUp(): void
    {
        $this->bootStaff();
        // « prof » est vacataire de catégorie « chargé de cours »
        $this->vac = $this->staff('p-prof', 'vacataire', ['category' => 'charge']);
        $this->api('PUT', '/vacation-rates', body: ['category' => 'charge', 'hourly_rate' => 6000]);
    }

    public function testLeCycleCompletDe43Virgule5HetdA261000Francs(): void
    {
        $contract = $this->activeContract($this->vac['id']);
        self::assertSame('active', $contract['status']);
        self::assertMatchesRegularExpression('/^VAC-2026-\d{5}$/', $contract['reference']);

        // 18 h de CM (6 x 3 h), 12 h de TD (6 x 2 h), 6 h de TP (2 x 3 h)
        for ($day = 1; $day <= 6; ++$day) {
            $this->validatedHour($this->vac['id'], 'prof', '2026-10-0' . $day, 180, 'cm');
            $this->validatedHour($this->vac['id'], 'prof', '2026-10-0' . $day, 120, 'td', ['slot_id' => 'sl' . $day]);
        }
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-05', 180, 'tp', ['slot_id' => 'tp1']);
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-06', 180, 'tp', ['slot_id' => 'tp2']);
        // une heure de septembre ne compte pas dans octobre
        $this->validatedHour($this->vac['id'], 'prof', '2026-09-30', 60, 'td');

        [$response, $body] = $this->api('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));
        $statement = $body['data'];
        self::assertSame([36 * 60, 43500, 6000, 261000, 'draft'], [$statement['minutes_total'], $statement['hetd_milli'], $statement['hourly_rate'], $statement['gross_amount'], $statement['status']]);

        $id = (string) $statement['id'];
        self::assertSame(409, $this->code('POST', '/vacation-statements/' . $id . '/pay', body: ['paid_on' => '2026-10-08', 'payment_reference' => 'VIR-1']), 'non validé : pas payable');
        self::assertSame('pending_approval', $this->api('POST', '/vacation-statements/' . $id . '/submit')[1]['data']['status']);
        // admin n'a pas vacations.validate
        self::assertSame(403, $this->code('POST', '/vacation-statements/' . $id . '/approve'));
        $this->permissions->grant('admin', 'vacations.validate');
        self::assertSame(403, $this->code('POST', '/vacation-statements/' . $id . '/approve'), 'le demandeur ne peut pas approuver');
        $validated = $this->api('POST', '/vacation-statements/' . $id . '/approve', 'boss')[1]['data'];
        self::assertSame(['validated', 'boss'], [$validated['status'], $validated['validated_by']]);

        $paid = $this->api('POST', '/vacation-statements/' . $id . '/pay', body: ['paid_on' => '2026-10-08', 'payment_reference' => 'VIR-2026-10-001'])[1]['data'];
        self::assertSame(['paid', 'VIR-2026-10-001', 261000], [$paid['status'], $paid['payment_reference'], $paid['gross_amount']]);
        self::assertSame(409, $this->code('POST', '/vacation-statements/' . $id . '/pay', body: ['paid_on' => '2026-10-08', 'payment_reference' => 'x']));

        $detail = $this->api('GET', '/vacation-statements/' . $id)[1]['data'];
        self::assertCount(14, $detail['lines']);
        self::assertSame(['kind' => 'vacation.statement', 'subject_id' => $id], array_intersect_key($this->approvals->requests['ap2'], ['kind' => 1, 'subject_id' => 1]));
        // un relevé payé ne se recalcule plus
        self::assertSame(409, $this->code('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']));
    }

    public function testUneHeureNonEmargeeOuSansCahierDeTextesNEstPasPayable(): void
    {
        $this->activeContract($this->vac['id']);
        $unsigned = $this->api('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 60, 'content' => 'Fractions'])[1]['data'];
        self::assertNull($unsigned['teacher_signed_at']);
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $unsigned['id'] . '/validate', 'boss'));

        // l'administration ne peut pas émarger à la place de l'enseignant
        self::assertSame(403, $this->code('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-06', 'minutes' => 60, 'signed' => true]));
        self::assertSame(403, $this->code('POST', '/vacation-hours/' . $unsigned['id'] . '/sign'));
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $unsigned['id'] . '/sign', 'prof', body: ['content' => 'Fractions : addition']));
        self::assertSame('validated', $this->api('POST', '/vacation-hours/' . $unsigned['id'] . '/validate', 'boss')[1]['data']['status']);

        $noContent = $this->api('POST', '/vacation-hours', 'prof', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-06', 'minutes' => 60, 'signed' => true])[1]['data'];
        $problem = $this->api('POST', '/vacation-hours/' . $noContent['id'] . '/validate', 'boss');
        self::assertSame(409, $problem[0]->getStatusCode());
        self::assertStringContainsString('cahier de textes', $problem[1]['detail']);
    }

    public function testValidationReserveeAuResponsableEtDifferenteDuDeclarant(): void
    {
        $this->activeContract($this->vac['id']);
        $hour = $this->declaredHour($this->vac['id'], 'prof', '2026-10-05', 60, 'td');

        self::assertSame(403, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'admin'));
        self::assertSame(403, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'prof'));

        // même avec le droit, celui qui a déclaré ne peut pas valider
        $this->permissions->grant('prof', 'vacations.validate');
        self::assertSame(403, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'prof'));
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));
    }

    public function testRejetAvecMotifPuisNouvelleDeclaration(): void
    {
        $this->activeContract($this->vac['id']);
        $hour = $this->declaredHour($this->vac['id'], 'prof', '2026-10-05', 60, 'td', ['slot_id' => 'sl9']);

        self::assertSame(422, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/reject', 'boss', body: []));
        $rejected = $this->api('POST', '/vacation-hours/' . $hour['id'] . '/reject', 'boss', body: ['reason' => 'Séance non tenue'])[1]['data'];
        self::assertSame(['rejected', 'Séance non tenue'], [$rejected['status'], $rejected['rejection_reason']]);
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));

        // la séance rejetée peut être redéclarée ; une séance déjà déclarée ne peut pas l'être deux fois
        $again = $this->declaredHour($this->vac['id'], 'prof', '2026-10-05', 90, 'td', ['slot_id' => 'sl9']);
        self::assertSame([$hour['id'], 'declared', 90], [$again['id'], $again['status'], $again['minutes']]);
        self::assertSame(409, $this->code('POST', '/vacation-hours', 'prof', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 90, 'slot_id' => 'sl9']));
    }

    public function testSansContratActifLHeureNEstPasValidable(): void
    {
        $hour = $this->declaredHour($this->vac['id'], 'prof', '2026-10-05', 60, 'td');
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));

        // un contrat en brouillon ou en attente ne suffit pas
        $draft = $this->api('POST', '/vacation-contracts', body: ['staff_id' => $this->vac['id'], 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])[1]['data'];
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));
        $this->api('POST', '/vacation-contracts/' . $draft['id'] . '/submit');
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));
        $this->api('POST', '/vacation-contracts/' . $draft['id'] . '/approve', 'boss');
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss'));
    }

    public function testPlafondDuContrat(): void
    {
        $this->activeContract($this->vac['id'], ['max_minutes' => 120]);
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 90, 'td');
        $over = $this->declaredHour($this->vac['id'], 'prof', '2026-10-02', 60, 'td');
        $problem = $this->api('POST', '/vacation-hours/' . $over['id'] . '/validate', 'boss');

        self::assertSame(409, $problem[0]->getStatusCode());
        self::assertStringContainsString('volume horaire', $problem[1]['detail']);
        $fits = $this->declaredHour($this->vac['id'], 'prof', '2026-10-03', 30, 'td');
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $fits['id'] . '/validate', 'boss'));
    }

    public function testPlafondMensuelDeLaCategorie(): void
    {
        $this->activeContract($this->vac['id']);
        $this->api('PUT', '/vacation-rates', body: ['category' => 'charge', 'hourly_rate' => 6000, 'monthly_cap_minutes' => 120]);
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 90, 'td');
        $over = $this->declaredHour($this->vac['id'], 'prof', '2026-10-02', 31, 'td');
        $problem = $this->api('POST', '/vacation-hours/' . $over['id'] . '/validate', 'boss');

        self::assertSame(409, $problem[0]->getStatusCode());
        self::assertStringContainsString('plafond mensuel', $problem[1]['detail']);
        // le plafond est mensuel : le mois suivant repart de zéro
        $this->clock->advance(30 * 86400);
        $next = $this->declaredHour($this->vac['id'], 'prof', '2026-11-02', 31, 'td');
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $next['id'] . '/validate', 'boss'));
    }

    public function testContratValidationADeuxPersonnes(): void
    {
        $contract = $this->api('POST', '/vacation-contracts', body: ['staff_id' => $this->vac['id'], 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'hourly_rate' => 7000])[1]['data'];
        $id = (string) $contract['id'];

        self::assertSame(409, $this->code('POST', '/vacation-contracts/' . $id . '/approve', 'boss'));
        self::assertSame(403, $this->code('POST', '/vacation-contracts/' . $id . '/submit', 'boss'));
        $submitted = $this->api('POST', '/vacation-contracts/' . $id . '/submit')[1]['data'];
        self::assertSame('pending_approval', $submitted['status']);
        self::assertSame(409, $this->code('PATCH', '/vacation-contracts/' . $id, body: ['hourly_rate' => 1]));
        self::assertSame(403, $this->code('POST', '/vacation-contracts/' . $id . '/approve', 'admin'));
        $this->permissions->grant('admin', 'vacations.validate');
        self::assertSame(403, $this->code('POST', '/vacation-contracts/' . $id . '/approve', 'admin'), 'le demandeur ne peut pas approuver');

        self::assertSame('rejected', $this->api('POST', '/vacation-contracts/' . $id . '/reject', 'boss')[1]['data']['status']);
        self::assertSame(200, $this->code('PATCH', '/vacation-contracts/' . $id, body: ['hourly_rate' => 6500]));
        $this->api('POST', '/vacation-contracts/' . $id . '/submit');
        self::assertSame('active', $this->api('POST', '/vacation-contracts/' . $id . '/approve', 'boss2')[1]['data']['status']);
        self::assertSame('terminated', $this->api('POST', '/vacation-contracts/' . $id . '/terminate')[1]['data']['status']);
        self::assertSame(409, $this->code('POST', '/vacation-contracts/' . $id . '/terminate'));
    }

    public function testRegleSurLesContrats(): void
    {
        $permanent = $this->staff('p-prof2', 'permanent');
        self::assertSame(409, $this->code('POST', '/vacation-contracts', body: ['staff_id' => $permanent['id'], 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));

        $this->api('POST', '/vacation-contracts', body: ['staff_id' => $this->vac['id'], 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        self::assertSame(409, $this->code('POST', '/vacation-contracts', body: ['staff_id' => $this->vac['id'], 'starts_on' => '2027-06-01', 'ends_on' => '2027-12-31']), 'chevauchement');
        self::assertSame(422, $this->code('POST', '/vacation-contracts', body: ['staff_id' => $this->vac['id'], 'starts_on' => '2028-01-01', 'ends_on' => '2027-12-31']));
        self::assertSame(201, $this->code('POST', '/vacation-contracts', body: ['staff_id' => $this->vac['id'], 'starts_on' => '2027-07-01', 'ends_on' => '2027-12-31']));
    }

    public function testSansTauxUnContratNePeutPasEtreSoumis(): void
    {
        $other = $this->staff('p-prof2', 'invite');
        $contract = $this->api('POST', '/vacation-contracts', body: ['staff_id' => $other['id'], 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])[1]['data'];

        self::assertSame(422, $this->code('POST', '/vacation-contracts/' . $contract['id'] . '/submit'));
        self::assertSame(200, $this->code('PATCH', '/vacation-contracts/' . $contract['id'], body: ['hourly_rate' => 5000]));
        self::assertSame(200, $this->code('POST', '/vacation-contracts/' . $contract['id'] . '/submit'));
    }

    public function testRelevePeutEtreRecalculeAvantSoumissionEtRefuseRedevientBrouillon(): void
    {
        $this->activeContract($this->vac['id']);
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 60, 'td');
        $first = $this->api('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10'])[1]['data'];
        self::assertSame(6000, $first['gross_amount']);

        $this->validatedHour($this->vac['id'], 'prof', '2026-10-02', 60, 'cm');
        $again = $this->api('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10'])[1]['data'];
        self::assertSame([$first['id'], 15000], [$again['id'], $again['gross_amount']]);

        $this->api('POST', '/vacation-statements/' . $first['id'] . '/submit');
        self::assertSame(409, $this->code('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']));
        self::assertSame('draft', $this->api('POST', '/vacation-statements/' . $first['id'] . '/reject', 'boss')[1]['data']['status']);
        self::assertSame(200, $this->code('POST', '/vacation-statements/' . $first['id'] . '/submit'));
    }

    public function testReleveImpossibleSansHeureValideeOuPourUnPermanent(): void
    {
        $this->activeContract($this->vac['id']);
        $this->declaredHour($this->vac['id'], 'prof', '2026-10-01', 60, 'td');
        self::assertSame(409, $this->code('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']));
        self::assertSame(422, $this->code('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-13']));

        $permanent = $this->staff('p-prof2', 'permanent');
        self::assertSame(409, $this->code('POST', '/vacation-statements', body: ['staff_id' => $permanent['id'], 'period' => '2026-10']));
    }

    public function testUnPermanentPeutAvoirSesHeuresValideesSansContrat(): void
    {
        $permanent = $this->staff('p-prof2', 'permanent');
        $hour = $this->validatedHour($permanent['id'], 'prof2', '2026-10-05', 60, 'td');

        self::assertNull($hour['contract_id']);
        self::assertSame('validated', $hour['status']);
    }

    public function testSaisieDeLAdministrationPourUnEnseignantEtDatesFutures(): void
    {
        self::assertSame(201, $this->code('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 60]));
        self::assertSame(422, $this->code('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-09', 'minutes' => 60]));
        self::assertSame(422, $this->code('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 0]));
        self::assertSame(422, $this->code('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 721]));
        self::assertSame(422, $this->code('POST', '/vacation-hours', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 60, 'assignment_id' => 'inconnue']));
        // un autre enseignant ne peut pas saisir pour ce collègue
        self::assertSame(403, $this->code('POST', '/vacation-hours', 'prof2', body: ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 60]));
    }

    public function testLEnseignantNeVoitQueSesHeuresSesContratsEtSesReleves(): void
    {
        $this->staff('p-prof2', 'vacataire', ['category' => 'charge']);
        $this->activeContract($this->vac['id']);
        $mine = $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 60, 'td');
        $this->api('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']);
        $other = $this->api('POST', '/vacation-hours', body: ['staff_id' => $this->api('GET', '/staff?filter[person_id]=p-prof2')[1]['data'][0]['id'], 'held_on' => '2026-10-05', 'minutes' => 60])[1]['data'];

        self::assertSame([$mine['id']], array_column($this->api('GET', '/vacation-hours', 'prof')[1]['data'], 'id'));
        self::assertSame(200, $this->code('GET', '/vacation-hours/' . $mine['id'], 'prof'));
        self::assertSame(404, $this->code('GET', '/vacation-hours/' . $other['id'], 'prof'));
        self::assertCount(1, $this->api('GET', '/vacation-contracts', 'prof')[1]['data']);
        self::assertCount(1, $this->api('GET', '/vacation-statements', 'prof')[1]['data']);
        self::assertSame([$other['id']], array_column($this->api('GET', '/vacation-hours', 'prof2')[1]['data'], 'id'));
        self::assertSame(404, $this->code('GET', '/vacation-hours/' . $mine['id'], 'prof2'));
        self::assertSame(403, $this->code('POST', '/vacation-statements', 'prof', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']));
        self::assertSame(403, $this->code('PUT', '/vacation-rates', 'prof', body: ['category' => 'x', 'hourly_rate' => 1]));
        self::assertSame(403, $this->code('GET', '/vacation-rates', 'prof'));
    }

    public function testFiltresDeLaListeDesHeures(): void
    {
        $this->activeContract($this->vac['id']);
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 60, 'td');
        $this->declaredHour($this->vac['id'], 'prof', '2026-09-20', 60, 'td');

        self::assertCount(1, $this->api('GET', '/vacation-hours?filter[period]=2026-10')[1]['data']);
        self::assertCount(1, $this->api('GET', '/vacation-hours?filter[status]=declared')[1]['data']);
        self::assertCount(2, $this->api('GET', '/vacation-hours?filter[staff_id]=' . $this->vac['id'])[1]['data']);
        self::assertSame(422, $this->code('GET', '/vacation-hours?filter[period]=2026-13'));
        self::assertSame(422, $this->code('GET', '/vacation-hours?filter[tenant_id]=t2'));
        $page = $this->api('GET', '/vacation-hours?per_page=1')[1];
        self::assertNotNull($page['meta']['next_cursor']);
    }

    public function testEnregistrementDesHeuresParLeContratEntrant(): void
    {
        $recorder = $this->kernel->container()->get(TaughtHoursRecorder::class);
        $id = $recorder->record('t1', 'e1', $this->vac['id'], 'slot-42', '2026-10-05', 120);

        self::assertSame($id, $recorder->record('t1', 'e1', $this->vac['id'], 'slot-42', '2026-10-05', 120), 'idempotent');
        self::assertSame($id, $recorder->record('t1', 'e1', $this->vac['id'], 'slot-42', '2026-10-05', 90), 'mise à jour tant que déclarée');
        $row = $this->api('GET', '/vacation-hours/' . $id)[1]['data'];
        self::assertSame(['declared', 'scheduling', 90, null], [$row['status'], $row['source'], $row['minutes'], $row['teacher_signed_at']]);
        self::assertCount(1, $this->api('GET', '/vacation-hours')[1]['data']);

        // payable seulement après émargement et cahier de textes
        $this->activeContract($this->vac['id']);
        self::assertSame(409, $this->code('POST', '/vacation-hours/' . $id . '/validate', 'boss'));
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $id . '/sign', 'prof', body: ['content' => 'Géométrie', 'session_kind' => 'tp']));
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $id . '/validate', 'boss'));
    }

    public function testLeContratEntrantRefuseLesDonneesInvalides(): void
    {
        $recorder = $this->kernel->container()->get(TaughtHoursRecorder::class);

        $this->expectException(\Scholaris\Core\Http\HttpException::class);
        $recorder->record('t1', 'e1', $this->vac['id'], 'slot-1', '2026-10-05', 0);
    }

    public function testLesActionsSontJournalisees(): void
    {
        $this->activeContract($this->vac['id']);
        $hour = $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 60, 'td');
        $actions = $this->connections()->pdo()->query('SELECT action FROM audit_log ORDER BY seq')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('vacation_contract.approved', $actions);
        self::assertContains('vacation_hours.validated', $actions);
        self::assertContains('vacation_rate.set', $actions);
        self::assertNotEmpty($hour['id']);
    }
}
