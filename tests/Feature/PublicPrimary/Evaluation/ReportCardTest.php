<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPrimary\Evaluation;

use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * T664 : bulletins DOC-08/09/10, appréciation et banque de formulations, publication par le directeur (API-07, RG-EVA-2), figement,
 * rectificatif au même numéro (RG-EVA-3, PP15), NOT-04, aucun rang (RG-EVA-1) ni donnée APEE (RG-DOC-3) ; remédiation tracée.
 */
final class ReportCardTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->seedReferential();
        [$saved] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $this->paulChecks()]);
        self::assertSame(201, $saved->getStatusCode(), (string) $saved->getBody());
    }

    /** @return array<string, mixed> bulletin de Paul pour l'unité 3 */
    private function generateUnitCards(): array
    {
        [$response, $json] = $this->api('POST', '/public-primary/report-cards/generate', 'teacher2', ['kind' => 'unit', 'scope_ref' => $this->unit(3), 'structure_id' => 'c2']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        foreach ($json['data'] as $card) {
            if ($card['person_id'] === 'paul') {
                return $card;
            }
        }
        self::fail('Bulletin de Paul absent.');
    }

    public function testLeDirecteurPublieLeBulletinQuiEstEnsuiteFige(): void
    {
        $card = $this->generateUnitCards();
        self::assertSame('draft', $card['state']);
        $mentions = array_column($card['content']['disciplines'], 'mention', 'discipline_code');
        self::assertEquals(['example_francais' => 'ECA', 'example_mathematiques' => 'A', 'example_english' => 'NA'], $mentions);

        [$appraised] = $this->api('PATCH', '/public-primary/report-cards/' . $card['id'], 'teacher2', ['appraisal' => 'Bon travail en mathématiques ; poursuivre la lecture.']);
        [$teacherPublish] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/publish', 'teacher2', []);
        [$published, $publishedJson] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/publish', 'director', []);
        [$again] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/publish', 'director', []);
        [$late] = $this->api('PATCH', '/public-primary/report-cards/' . $card['id'], 'teacher2', ['appraisal' => 'Changement']);
        [$frozenChecks] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $this->cellsFor('paul', 'example_english', ['C1' => [1, 1, 1]])]);

        self::assertSame(200, $appraised->getStatusCode(), (string) $appraised->getBody());
        self::assertSame(403, $teacherPublish->getStatusCode(), 'RG-EVA-2 : publication par le directeur seulement.');
        self::assertSame(200, $published->getStatusCode(), (string) $published->getBody());
        self::assertSame('published', $publishedJson['data']['state']);
        self::assertNotEmpty($publishedJson['data']['document']['number']);
        self::assertSame(409, $again->getStatusCode());
        self::assertSame(409, $late->getStatusCode());
        self::assertSame(409, $frozenChecks->getStatusCode(), 'La saisie d\'une unité publiée est figée.');
        self::assertCount(1, $this->messenger->of('pub.not04.parent'));
        $content = (string) $this->rows("SELECT content FROM documents WHERE type_code = 'pp_doc08_unit_report'")[0]['content'];
        self::assertStringContainsString('Mathématiques : A, avec maîtrise du critère de perfectionnement', $content);
        self::assertStringNotContainsStringIgnoringCase('rang', $content, 'RG-EVA-1 : aucun rang.');
        self::assertStringNotContainsStringIgnoringCase('apee', $content, 'RG-DOC-3 : aucune mention de l\'APEE.');
    }

    public function testRectificatifParLeDirecteurAvecMotifAuMemeNumero(): void
    {
        $card = $this->generateUnitCards();
        [, $published] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/publish', 'director', []);
        $number = $published['data']['document']['number'];
        $english = $this->frames['example_english']['id'];

        [$teacher] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/rectify', 'teacher2', ['frame_id' => $english, 'mention' => 'ECA', 'reason' => 'x']);
        [$noReason] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/rectify', 'director', ['frame_id' => $english, 'mention' => 'ECA']);
        [$response, $json] = $this->api('POST', '/public-primary/report-cards/' . $card['id'] . '/rectify', 'director', ['frame_id' => $english, 'mention' => 'ECA', 'reason' => 'Erreur de saisie de l\'occasion 2 constatée avec l\'enseignant.']);

        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(422, $noReason->getStatusCode());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('rectified', $json['data']['state']);
        $documents = $this->rows("SELECT number, status FROM documents WHERE type_code = 'pp_doc08_unit_report' ORDER BY revision");
        self::assertCount(2, $documents);
        self::assertSame($number, $documents[1]['number'], 'Rectificatif au même numéro (RG-DOC-5).');
        self::assertSame('ECA', $this->rows("SELECT mention FROM pp_mentions WHERE person_id = 'paul' AND frame_id = '" . $english . "' AND scope_kind = 'unit'")[0]['mention']);
    }

    public function testBulletinTrimestrielEtBanqueDeFormulations(): void
    {
        [$phrase] = $this->api('POST', '/public-primary/appraisal-phrases', 'teacher2', ['locale' => 'fr', 'phrase' => 'Élève appliqué, encouragements.']);
        [, $phrases] = $this->api('GET', '/public-primary/appraisal-phrases?locale=fr', 'teacher2');
        [$outsider] = $this->api('GET', '/public-primary/appraisal-phrases', 'outsider');
        [$response, $json] = $this->api('POST', '/public-primary/report-cards/generate', 'teacher2', ['kind' => 'trimester', 'scope_ref' => 'per-t2', 'structure_id' => 'c2', 'year_label' => self::YEAR]);

        self::assertSame(201, $phrase->getStatusCode());
        self::assertCount(1, $phrases['data']);
        self::assertSame(404, $outsider->getStatusCode());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $paul = array_values(array_filter($json['data'], static fn (array $c): bool => $c['person_id'] === 'paul'))[0];
        self::assertSame('Trimestre 2', $paul['content']['period_label_fr']);
        $french = array_values(array_filter($paul['content']['disciplines'], static fn (array $d): bool => $d['discipline_code'] === 'example_francais'))[0];
        self::assertSame('ECA', $french['mention'], 'Mention trimestrielle = dernière unité évaluée du trimestre.');
        self::assertCount(3, $french['units']);
    }

    public function testRegistreEtFicheDeSuiviDeRemediation(): void
    {
        [, $list] = $this->api('GET', '/public-primary/units/' . $this->unit(3) . '/remediation?structure_id=c2', 'teacher2');
        $remediationId = $list['data'][0]['pupils'][0]['remediation_id'];
        $english = $this->frames['example_english']['id'];

        [$session] = $this->api('POST', '/public-primary/remediation-sessions', 'teacher2', [
            'structure_id' => 'c2', 'frame_id' => $english, 'unit_id' => $this->unit(3), 'held_on' => '2026-10-06', 'duration_minutes' => 60,
            'form' => 'need_group', 'criterion_ids' => [$this->criterion('example_english', 'C1')], 'person_ids' => ['paul'],
        ]);
        [$badForm] = $this->api('POST', '/public-primary/remediation-sessions', 'teacher2', ['structure_id' => 'c2', 'frame_id' => $english, 'held_on' => '2026-10-06', 'duration_minutes' => 60, 'form' => 'punition', 'person_ids' => ['paul']]);
        [$resolvedWithoutNote] = $this->api('PATCH', '/public-primary/remediations/' . $remediationId, 'teacher2', ['state' => 'resolved']);
        [$updated] = $this->api('PATCH', '/public-primary/remediations/' . $remediationId, 'teacher2', ['state' => 'in_progress', 'form' => 'peer_tutoring']);
        [$sheet, $sheetJson] = $this->api('GET', '/public-primary/classes/c2/pupils/paul/remediation-sheet', 'teacher2');
        [$summary, $summaryJson] = $this->api('GET', '/public-primary/remediation-sessions/summary?structure_id=c2&date=2026-10-06', 'teacher2');
        [$otherTeacher] = $this->api('GET', '/public-primary/classes/c2/pupils/paul/remediation-sheet', 'teacher');

        self::assertSame(201, $session->getStatusCode(), (string) $session->getBody());
        self::assertSame(422, $badForm->getStatusCode());
        self::assertSame(422, $resolvedWithoutNote->getStatusCode());
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame(200, $sheet->getStatusCode());
        self::assertCount(4, $sheetJson['data']['remediations']);
        self::assertSame(60, $sheetJson['data']['minutes_attended']);
        self::assertSame(200, $summary->getStatusCode(), (string) $summary->getBody());
        $lines = array_column($summaryJson['data']['disciplines'], null, 'frame_id');
        self::assertTrue($lines[$english]['met'], 'Au moins 60 minutes par semaine (arrêté du 14 août 2026).');
        self::assertFalse($lines[$this->frames['example_francais']['id']]['met']);
        self::assertSame(404, $otherTeacher->getStatusCode());
    }

    public function testLesNotesChiffreesSontUneOptionDesactiveeParDefaut(): void
    {
        $body = ['structure_id' => 'c2', 'scores' => [['person_id' => 'paul', 'frame_id' => $this->frames['example_mathematiques']['id'], 'score' => 14.5]]];
        [$disabled] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/scores', 'teacher2', $body);
        $this->settings->values['public_primary.evaluation.numeric_scores_enabled'] = true;
        [$enabled, $json] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/scores', 'teacher2', $body);
        [$tooHigh] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/scores', 'teacher2', ['structure_id' => 'c2', 'scores' => [['person_id' => 'paul', 'frame_id' => $this->frames['example_mathematiques']['id'], 'score' => 21]]]);
        [, $list] = $this->api('GET', '/public-primary/units/' . $this->unit(3) . '/scores?structure_id=c2', 'teacher2');

        self::assertSame(409, $disabled->getStatusCode());
        self::assertSame(200, $enabled->getStatusCode(), (string) $enabled->getBody());
        self::assertSame(20, $json['data']['scale_max']);
        self::assertSame(422, $tooHigh->getStatusCode());
        self::assertEquals(14.5, $list['data'][0]['score']);
    }
}
