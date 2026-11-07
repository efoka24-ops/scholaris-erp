<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/** Délibération en séance : crédits, proposition et décision du jury, quorum, clôture, signatures (T122). */
final class DeliberationTest extends AssessmentTestCase
{
    /** @var array{ue1: string, ue2: string} */
    private array $units;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $this->units = ['ue1' => $this->subject('UE1', 'teaching_unit', 1.0, 6), 'ue2' => $this->subject('UE2', 'teaching_unit', 1.0, 4)];
        $ue1 = $this->assessment($this->units['ue1'], 'Examen UE1');
        $ue2 = $this->assessment($this->units['ue2'], 'Examen UE2');
        // p1 : 12 / 11 admis ; p2 : 12 / 8 compensation ; p3 : 14 / 7 rattrapage ; p4 : 5 / 6 ajourné.
        foreach (['p1' => [12, 11], 'p2' => [12, 8], 'p3' => [14, 7], 'p4' => [5, 6]] as $person => [$a, $b]) {
            $this->grade($ue1, $person, ['value' => $a]);
            $this->grade($ue2, $person, ['value' => $b]);
        }
        $this->lock($ue1);
        $this->lock($ue2);
        $this->permissions->grant('jury', 'assessment.deliberation.manage');
        $this->actors->persons['jury'] = 'm1';
    }

    /**
     * @param array<string, mixed> $deliberation
     * @return array<string, array<string, mixed>>
     */
    private function byPerson(array $deliberation): array
    {
        return array_column($deliberation['lines'], null, 'person_id');
    }

    public function testTableauCreditsEtPropositionsDuJury(): void
    {
        // Les épreuves portent la période seq1 : la délibération porte sur la même période.
        [$response, $json] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 5]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $lines = $this->byPerson($json['data']);

        self::assertSame(['Admis', 'Admis par compensation', 'Rattrapage', 'Ajourné'], [$lines['p1']['proposal'], $lines['p2']['proposal'], $lines['p3']['proposal'], $lines['p4']['proposal']]);
        self::assertSame([10, 10, 6, 0], [$lines['p1']['credits_earned'], $lines['p2']['credits_earned'], $lines['p3']['credits_earned'], $lines['p4']['credits_earned']]);
        self::assertSame(10, $lines['p1']['credits_total']);
        self::assertSame(['UE1', 'UE2'], array_column($lines['p1']['unit_averages'], 'code'));
        self::assertSame([6, 4], array_column($lines['p1']['unit_averages'], 'credits'));
        self::assertSame($lines['p1']['proposal'], $lines['p1']['decision']);
        self::assertSame(['admitted' => 1, 'compensated' => 1, 'resit' => 1, 'adjourned' => 1, 'modified' => 0, 'quorum_reached' => false], $json['data']['summary']);
    }

    /** @return array<string, mixed> */
    private function sitting(): array
    {
        [, $json] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Semestre 1', 'president_id' => 'm1', 'members_expected' => 5]);

        return $json['data'];
    }

    public function testLaSeanceNeSOuvrePasSansQuorum(): void
    {
        $deliberation = $this->sitting();
        $this->api('POST', '/deliberations/' . $deliberation['id'] . '/attendance', 'jury', ['members_present' => ['m1', 'm2']]);

        [$response, $json] = $this->api('POST', '/deliberations/' . $deliberation['id'] . '/start', 'jury');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Quorum non atteint : 2 membre(s) présent(s) sur 5', $json['detail']);

        [, $present] = $this->api('POST', '/deliberations/' . $deliberation['id'] . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        self::assertTrue($present['data']['quorum_reached']);
        [$started, $open] = $this->api('POST', '/deliberations/' . $deliberation['id'] . '/start', 'jury');
        self::assertSame(200, $started->getStatusCode());
        self::assertSame('open', $open['data']['status']);
    }

    public function testLeSeuilDeQuorumEstUnParametre(): void
    {
        $this->settings->values['assessment.quorum_percent'] = 80;
        $deliberation = $this->sitting();

        [, $three] = $this->api('POST', '/deliberations/' . $deliberation['id'] . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        [, $four] = $this->api('POST', '/deliberations/' . $deliberation['id'] . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3', 'm4']]);

        self::assertFalse($three['data']['quorum_reached']);
        self::assertTrue($four['data']['quorum_reached']);
    }

    public function testDecisionDuJuryDifferenteExigeUnMotifAuPv(): void
    {
        $deliberation = $this->sitting();
        $id = $deliberation['id'];
        $this->api('POST', '/deliberations/' . $id . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        $this->api('POST', '/deliberations/' . $id . '/start', 'jury');
        $line = $this->byPerson($deliberation)['p3']['id'];

        [$noReason] = $this->api('PATCH', '/deliberations/' . $id . '/lines/' . $line, 'jury', ['decision' => 'Admis par compensation']);
        [$unknown] = $this->api('PATCH', '/deliberations/' . $id . '/lines/' . $line, 'jury', ['decision' => 'Excellent', 'reason' => 'x']);
        [$ok, $json] = $this->api('PATCH', '/deliberations/' . $id . '/lines/' . $line, 'jury', ['decision' => 'Admis par compensation', 'reason' => 'Situation médicale établie']);

        self::assertSame(422, $noReason->getStatusCode());
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(1, $json['data']['summary']['modified']);
        self::assertSame(2, $json['data']['summary']['compensated']);
    }

    public function testLesDecisionsNeSeModifientPasAvantOuvertureDeLaSeance(): void
    {
        $deliberation = $this->sitting();
        [$response] = $this->api('PATCH', '/deliberations/' . $deliberation['id'] . '/lines/' . $this->byPerson($deliberation)['p1']['id'], 'jury', ['decision' => 'Ajourné', 'reason' => 'x']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testClotureReporteLesDecisionsGenereLePvEtLesMembresPresentsLeSignent(): void
    {
        $deliberation = $this->sitting();
        $id = $deliberation['id'];
        $this->api('POST', '/deliberations/' . $id . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        $this->api('POST', '/deliberations/' . $id . '/start', 'jury');
        $this->api('PATCH', '/deliberations/' . $id . '/lines/' . $this->byPerson($deliberation)['p3']['id'], 'jury', ['decision' => 'Ajourné', 'reason' => 'Absences aux rattrapages']);

        [$closed, $json] = $this->api('POST', '/deliberations/' . $id . '/close', 'jury');
        self::assertSame(200, $closed->getStatusCode(), (string) $closed->getBody());
        self::assertSame('closed', $json['data']['status']);
        self::assertSame('issued', $json['data']['minutes_status']);
        self::assertStringContainsString('modifiée par le jury, motif : Absences aux rattrapages', $json['data']['minutes']);
        self::assertSame('deliberation_minutes', $this->issuer->issued[0]['type']);
        self::assertSame('Ajourné', array_column($this->rows('SELECT person_id, decision FROM results'), 'decision', 'person_id')['p3']);

        [$locked] = $this->api('PATCH', '/deliberations/' . $id . '/lines/' . $this->byPerson($deliberation)['p1']['id'], 'jury', ['decision' => 'Ajourné', 'reason' => 'x']);
        self::assertSame(409, $locked->getStatusCode(), 'Décisions non modifiables après clôture.');

        // Signature : seuls les membres présents, une fois chacun.
        $this->permissions->grant('stranger', 'assessment.read');
        $this->access->grant('stranger', 'e1');
        $this->actors->persons['stranger'] = 'zz';
        [$outsider] = $this->api('POST', '/deliberations/' . $id . '/sign', 'stranger');
        [$signed, $afterSign] = $this->api('POST', '/deliberations/' . $id . '/sign', 'jury');
        [$twice] = $this->api('POST', '/deliberations/' . $id . '/sign', 'jury');
        self::assertSame(403, $outsider->getStatusCode());
        self::assertSame(200, $signed->getStatusCode());
        self::assertSame(['m1'], array_column($afterSign['data']['signatures'], 'person_id'));
        self::assertSame(409, $twice->getStatusCode());
    }

    public function testReouvertureExceptionnelleParLaDirectionAcademique(): void
    {
        $deliberation = $this->sitting();
        $id = $deliberation['id'];
        $this->api('POST', '/deliberations/' . $id . '/attendance', 'jury', ['members_present' => ['m1', 'm2', 'm3']]);
        $this->api('POST', '/deliberations/' . $id . '/start', 'jury');
        $this->api('POST', '/deliberations/' . $id . '/close', 'jury');

        [$denied] = $this->api('POST', '/deliberations/' . $id . '/reopen', 'jury', ['reason' => 'Erreur de saisie']);
        [$noReason] = $this->api('POST', '/deliberations/' . $id . '/reopen', 'director', []);
        [$ok, $json] = $this->api('POST', '/deliberations/' . $id . '/reopen', 'director', ['reason' => 'Erreur de saisie constatée par le jury']);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(422, $noReason->getStatusCode());
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('open', $json['data']['status']);
        self::assertSame('Erreur de saisie constatée par le jury', $json['data']['reopen_reason']);
        self::assertSame([], $json['data']['signatures']);
    }

    public function testPasDeSecondeDeliberationNiDeDroitSansPermission(): void
    {
        $this->sitting();
        [$twice] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 3]);
        [$denied] = $this->api('POST', '/deliberations', 'teacher', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 3]);

        self::assertSame(409, $twice->getStatusCode());
        self::assertSame(403, $denied->getStatusCode());
    }
}
