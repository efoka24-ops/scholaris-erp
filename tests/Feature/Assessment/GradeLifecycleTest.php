<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/** Cycle de vie d'une note : bornes, verrouillage, correction approuvée par une autre personne, historique (T115, T119, T124). */
final class GradeLifecycleTest extends AssessmentTestCase
{
    private string $math;
    private string $devoir;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $this->math = $this->subject();
        $this->devoir = $this->assessment($this->math, 'Devoir', 2.0);
    }

    public function testUneNoteDepassantLeMaximumEstRefuseAvecLeMessageDeLEcran(): void
    {
        [$response, $json] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => 22]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['Devoir : 22 dépasse le maximum de 20. Corrigez avant de valider.'], $json['errors']['value']);
        self::assertSame([], $this->rows('SELECT * FROM grades'));
    }

    public function testUneNoteNegativeOuAvecTropDeDecimalesEstRefusee(): void
    {
        [$negative] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => -1]);
        [$decimals] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => 12.345]);

        self::assertSame(422, $negative->getStatusCode());
        self::assertSame(422, $decimals->getStatusCode());
    }

    public function testSaisieModificationEtHistoriqueParNote(): void
    {
        $created = $this->grade($this->devoir, 'p1', ['value' => 14]);
        self::assertSame(1, $created['version']);

        $updated = $this->grade($this->devoir, 'p1', ['value' => 15]);
        self::assertSame(2, $updated['version']);
        self::assertEquals(15.0, $updated['score']);

        // Même valeur : aucune nouvelle version, aucun événement.
        $same = $this->grade($this->devoir, 'p1', ['value' => 15]);
        self::assertSame(2, $same['version']);

        [$response, $json] = $this->api('GET', '/grades/' . $created['id'] . '/events', 'reader');
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $json['data']);
        self::assertNull($json['data'][0]['old_score']);
        self::assertEquals(14.0, $json['data'][1]['old_score']);
        self::assertEquals(15.0, $json['data'][1]['new_score']);
        self::assertSame('censor', $json['data'][1]['author']);
    }

    public function testUnEleveInconnuDeLaClasseEstRefuse(): void
    {
        [$response] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $this->devoir, 'person_id' => 'inconnu', 'value' => 10]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testApresValidationLaSaisieEstVerrouilleeEtLaCorrectionPasseParUneAutrePersonne(): void
    {
        $grade = $this->grade($this->devoir, 'p1', ['value' => 14]);
        $this->grade($this->devoir, 'p2', ['value' => 10]);
        $this->grade($this->devoir, 'p3', ['value' => 11]);
        $this->grade($this->devoir, 'p4', ['value' => 12]);
        $this->lock($this->devoir);

        [$locked, $problem] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $this->devoir, 'person_id' => 'p1', 'value' => 18]);
        self::assertSame(409, $locked->getStatusCode());
        self::assertStringContainsString('demande de correction', $problem['detail']);

        // Demande motivée par le censeur lui-même : il ne peut pas l'approuver, même avec le droit.
        [$request, $created] = $this->api('POST', '/grade-corrections', 'censor', ['grade_id' => $grade['id'], 'value' => 15, 'reason' => 'erreur de report']);
        self::assertSame(202, $request->getStatusCode(), (string) $request->getBody());
        self::assertSame('pending', $created['data']['status']);
        $correctionId = $created['data']['id'];

        $this->permissions->grant('censor', 'assessment.correction.approve');
        [$self] = $this->api('POST', '/grade-corrections/' . $correctionId . '/approve', 'censor');
        self::assertSame(403, $self->getStatusCode());
        self::assertEquals(14.0, (float) $this->rows('SELECT score FROM grades WHERE id = ' . $this->connections()->pdo()->quote($grade['id']))[0]['score'], 'La note ne change pas avant approbation.');

        [$approved, $result] = $this->api('POST', '/grade-corrections/' . $correctionId . '/approve', 'director');
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame('approved', $result['data']['status']);

        [, $events] = $this->api('GET', '/grades/' . $grade['id'] . '/events', 'reader');
        $last = end($events['data']);
        self::assertSame('correction', $last['event_kind']);
        self::assertEquals([14.0, 15.0, 'erreur de report', 'director'], [$last['old_score'], $last['new_score'], $last['reason'], $last['approved_by']]);
        self::assertEquals(15.0, (float) $this->api('GET', '/grades/' . $grade['id'], 'reader')[1]['data']['score']);
    }

    public function testUneCorrectionRejeteeLaisseLaNoteIntacte(): void
    {
        $grade = $this->grade($this->devoir, 'p1', ['value' => 14]);
        foreach (['p2', 'p3', 'p4'] as $person) {
            $this->grade($this->devoir, $person, ['value' => 10]);
        }
        $this->lock($this->devoir);
        [, $created] = $this->api('POST', '/grade-corrections', 'censor', ['grade_id' => $grade['id'], 'value' => 19, 'reason' => 'demande contestée']);

        [$rejected, $json] = $this->api('POST', '/grade-corrections/' . $created['data']['id'] . '/reject', 'director');
        self::assertSame(200, $rejected->getStatusCode());
        self::assertSame('rejected', $json['data']['status']);
        [$again] = $this->api('POST', '/grade-corrections/' . $created['data']['id'] . '/approve', 'director');
        self::assertSame(409, $again->getStatusCode());
        self::assertEquals(14.0, $this->api('GET', '/grades/' . $grade['id'], 'reader')[1]['data']['score']);
    }

    public function testUneDemandeDeCorrectionSurUneEpreuveEncoreOuverteEstRefusee(): void
    {
        $grade = $this->grade($this->devoir, 'p1', ['value' => 14]);
        [$response, $json] = $this->api('POST', '/grade-corrections', 'censor', ['grade_id' => $grade['id'], 'value' => 15, 'reason' => 'x']);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('directement', $json['detail']);
    }

    public function testLaValidationExigeQueToutesLesNotesSoientSaisies(): void
    {
        $this->grade($this->devoir, 'p1', ['value' => 14]);
        $this->moveTo($this->devoir, 'entry');
        [$response, $json] = $this->api('POST', '/assessments/' . $this->devoir . '/transition', 'censor', ['to' => 'locked']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['Devoir : 3 notes manquantes. Corrigez avant de valider.'], $json['errors']['grades']);

        // Un absent justifié compte comme une ligne renseignée.
        foreach (['p2', 'p3', 'p4'] as $person) {
            $this->grade($this->devoir, $person, $person === 'p2' ? ['absence' => 'justified'] : ['value' => 9]);
        }
        $this->moveTo($this->devoir, 'locked');
    }

    public function testLeCycleParDefautInterditLesEtapesSautéesEtExigeLeDroitDeLaTransition(): void
    {
        [$skip, $problem] = $this->api('POST', '/assessments/' . $this->devoir . '/transition', 'censor', ['to' => 'published']);
        self::assertSame(409, $skip->getStatusCode());
        self::assertStringContainsString('Étapes possibles : entry', $problem['detail']);

        $this->moveTo($this->devoir, 'entry');
        [$denied] = $this->api('POST', '/assessments/' . $this->devoir . '/transition', 'teacher', ['to' => 'locked']);
        self::assertSame(403, $denied->getStatusCode(), 'L\'enseignant saisit mais ne valide pas.');
    }

    public function testUneNoteEstUniqueParEleveEtParEpreuve(): void
    {
        $this->grade($this->devoir, 'p1', ['value' => 14]);
        $this->grade($this->devoir, 'p1', ['value' => 13]);

        self::assertCount(1, $this->rows('SELECT id FROM grades'));
    }
}
