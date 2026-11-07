<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/** Conseil de classe : proposition calculée, décision motivée, procès-verbal, file des bulletins (T122). */
final class ClassCouncilTest extends AssessmentTestCase
{
    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
    }

    /** @return array<string, mixed> conseil ouvert, lignes indexées par personne */
    private function openCouncil(): array
    {
        [$response, $json] = $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Séquence 1', 'held_on' => '2026-11-20']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $json['data']['by_person'] = array_column($json['data']['lines'], null, 'person_id');

        return $json['data'];
    }

    public function testLaPropositionEstCalculeeAvecRangMoyenneEtAbsencesNonJustifiees(): void
    {
        $this->gradedClass();
        $this->attendance->unjustified = ['p3' => 3, 'p4' => 12];

        $council = $this->openCouncil();
        $lines = $council['by_person'];

        self::assertSame('open', $council['status']);
        self::assertEquals([15.33, 12.0, 10.33, 7.67], [$lines['p1']['average'], $lines['p2']['average'], $lines['p4']['average'], $lines['p3']['average']]);
        self::assertSame([1, 2, 3, 4], [$lines['p1']['rank_in_class'], $lines['p2']['rank_in_class'], $lines['p4']['rank_in_class'], $lines['p3']['rank_in_class']]);
        self::assertSame('Tableau d\'honneur', $lines['p1']['proposal']);
        self::assertSame('Encouragements', $lines['p2']['proposal']);
        self::assertSame('Blâme', $lines['p4']['proposal'], '12 absences non justifiées.');
        self::assertSame('Avertissement travail', $lines['p3']['proposal']);
        self::assertSame($lines['p1']['proposal'], $lines['p1']['decision'], 'La décision reprend la proposition par défaut.');
        self::assertSame(['p1', 'p2', 'p4', 'p3'], array_column($council['lines'], 'person_id'), 'Trié par rang.');
        self::assertSame('Ada Abega', $lines['p1']['name']);
        self::assertSame(3, $lines['p4']['rank_in_class']);
        self::assertSame(12, $lines['p4']['unjustified_absences']);
        self::assertEquals(11.33, $council['summary']['class_average']);
        self::assertSame(3, $council['summary']['pass_count']);
        self::assertSame(15, $council['summary']['unjustified_absences']);
        self::assertSame(['MATH', 'FRAN'], array_map(static fn (array $s): string => str_replace('Matière ', '', (string) $s['name']), $council['summary']['subject_averages']));
    }

    public function testUneDecisionDifferenteExigeUnMotif(): void
    {
        $this->gradedClass();
        $council = $this->openCouncil();
        $line = $council['by_person']['p2']['id'];

        [$noReason, $problem] = $this->api('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $line, 'council', ['decision' => 'Avertissement conduite']);
        self::assertSame(422, $noReason->getStatusCode());
        self::assertStringContainsString('indiquez le motif', $problem['errors']['reason'][0]);

        [$ok, $json] = $this->api('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $line, 'council', ['decision' => 'Avertissement conduite', 'reason' => 'retards répétés', 'conduct' => 'Passable']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $updated = array_column($json['data']['lines'], null, 'person_id')['p2'];
        self::assertSame(['Avertissement conduite', 'retards répétés', 'Encouragements', 'Passable'], [$updated['decision'], $updated['reason'], $updated['proposal'], $updated['conduct']]);

        // Revenir à la proposition efface le motif.
        [, $back] = $this->api('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $line, 'council', ['decision' => 'Encouragements']);
        self::assertNull(array_column($back['data']['lines'], null, 'person_id')['p2']['reason']);
    }

    public function testLeConseilNeSeValidePasTantQueLaSaisieNEstPasVerrouillee(): void
    {
        $this->gradedClass(false);
        $council = $this->openCouncil();

        [$response, $json] = $this->api('POST', '/class-councils/' . $council['id'] . '/validate', 'council');

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('La saisie n\'est pas verrouillée', $json['detail']);
    }

    public function testValidationReporteLesDecisionsGenerePvEtMetLesBulletinsEnFile(): void
    {
        $this->gradedClass(true);
        $council = $this->openCouncil();
        $this->api('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $council['by_person']['p2']['id'], 'council', ['decision' => 'Avertissement conduite', 'reason' => 'retards répétés']);
        $this->actors->persons['council'] = 'c-person';
        $this->people->add('t1', 'c-person', 'Paul', 'Censeur');

        [$response, $json] = $this->api('POST', '/class-councils/' . $council['id'] . '/validate', 'council');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('validated', $json['data']['status']);
        self::assertStringContainsString('modifiée, motif : retards répétés', $json['data']['minutes']);
        self::assertSame('issued', $json['data']['minutes_status']);
        self::assertSame('class_council_minutes', $this->issuer->issued[0]['type']);

        $results = array_column($this->rows('SELECT person_id, decision, status FROM results'), null, 'person_id');
        self::assertSame('Avertissement conduite', $results['p2']['decision']);
        self::assertSame('Tableau d\'honneur', $results['p1']['decision']);
        self::assertSame('computed', $results['p1']['status'], 'Rien n\'est publié par la validation du conseil.');
        self::assertCount(4, $this->rows("SELECT id FROM report_card_requests WHERE status = 'queued'"));

        [$locked, $problem] = $this->api('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $council['by_person']['p1']['id'], 'council', ['decision' => 'Blâme', 'reason' => 'x']);
        self::assertSame(409, $locked->getStatusCode());
        self::assertStringContainsString('validé', $problem['detail']);
        [$twice] = $this->api('POST', '/class-councils/' . $council['id'] . '/validate', 'council');
        self::assertSame(409, $twice->getStatusCode());
    }

    public function testGenerationDesBulletinsViaDocumentsSansDoublon(): void
    {
        $this->gradedClass(true);
        $council = $this->openCouncil();
        $this->api('POST', '/class-councils/' . $council['id'] . '/validate', 'council');

        [$response, $json] = $this->api('POST', '/class-councils/' . $council['id'] . '/report-cards', 'council');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['issued', 'issued', 'issued', 'issued'], array_column($json['data'], 'status'));

        $reportCards = array_values(array_filter($this->issuer->issued, static fn (array $d): bool => $d['type'] === 'report_card'));
        self::assertCount(4, $reportCards);
        $first = array_column($reportCards, null, 'person')['p1'];
        self::assertEquals(15.33, $first['data']['average']);
        self::assertSame(1, $first['data']['rank']);
        self::assertSame(4, $first['data']['class_size']);
        self::assertSame('Tableau d\'honneur', $first['data']['decision']);

        $this->api('POST', '/class-councils/' . $council['id'] . '/report-cards', 'council');
        self::assertCount(4, array_filter($this->issuer->issued, static fn (array $d): bool => $d['type'] === 'report_card'), 'Un bulletin déjà émis n\'est jamais réémis.');
    }

    public function testUnModeleDeBulletinAbsentLaisseLesDemandesEnEchecEtRepriseEnsuite(): void
    {
        $this->gradedClass(true);
        $this->issuer->knownTypes = ['class_council_minutes'];
        $council = $this->openCouncil();
        $this->api('POST', '/class-councils/' . $council['id'] . '/validate', 'council');

        [, $failed] = $this->api('POST', '/class-councils/' . $council['id'] . '/report-cards', 'council');
        self::assertSame(['failed', 'failed', 'failed', 'failed'], array_column($failed['data'], 'status'));
        self::assertStringContainsString('Type de document inconnu', (string) $failed['data'][0]['error_message']);

        $this->issuer->knownTypes[] = 'report_card';
        [, $retried] = $this->api('POST', '/class-councils/' . $council['id'] . '/report-cards', 'council');
        self::assertSame(['issued', 'issued', 'issued', 'issued'], array_column($retried['data'], 'status'));

        [, $queue] = $this->api('GET', '/class-councils/' . $council['id'] . '/report-cards', 'reader');
        self::assertCount(4, $queue['data']);
    }

    public function testLesBulletinsNeSeGenerentQuApresLaValidation(): void
    {
        $this->gradedClass(true);
        $council = $this->openCouncil();

        [$response] = $this->api('POST', '/class-councils/' . $council['id'] . '/report-cards', 'council');

        self::assertSame(409, $response->getStatusCode());
    }

    public function testUnSecondConseilPourLaMemeClasseEtLaMemePeriodeEstRefuse(): void
    {
        $this->gradedClass();
        $this->openCouncil();
        [$response] = $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testActualisationApresCorrectionConserveLesDecisionsModifiees(): void
    {
        $classe = $this->gradedClass();
        $council = $this->openCouncil();
        $this->api('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $council['by_person']['p2']['id'], 'council', ['decision' => 'Blâme', 'reason' => 'discipline']);
        $this->grade($classe['math_devoir'], 'p3', ['value' => 19]);

        [$response, $json] = $this->api('POST', '/class-councils/' . $council['id'] . '/refresh', 'council');

        self::assertSame(200, $response->getStatusCode());
        $lines = array_column($json['data']['lines'], null, 'person_id');
        self::assertEquals(15.0, $lines['p3']['average'], '(19 x 2 + 7) / 3 = 15');
        self::assertSame(2, $lines['p3']['rank_in_class']);
        self::assertSame('Tableau d\'honneur', $lines['p3']['decision'], 'Décision non modifiée : suit la nouvelle proposition.');
        self::assertSame('Blâme', $lines['p2']['decision']);
        self::assertSame('discipline', $lines['p2']['reason']);
    }

    public function testSeulLeConseilGereLeConseil(): void
    {
        $this->gradedClass();
        [$write] = $this->api('POST', '/class-councils', 'teacher', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        [$read] = $this->api('GET', '/class-councils', 'parent');

        self::assertSame(403, $write->getStatusCode());
        self::assertSame(403, $read->getStatusCode());
    }
}
