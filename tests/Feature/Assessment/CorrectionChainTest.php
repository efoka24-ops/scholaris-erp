<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/** S3 : approbation à n niveaux d'une correction (chef de département puis directeur d'école), 2FA par étape. */
final class CorrectionChainTest extends AssessmentTestCase
{
    private string $gradeId;

    protected function setUp(): void
    {
        $this->bootAssessment(['assessment.correction.chain' => [
            ['permission' => 'assessment.validate', 'label' => 'Chef de département'],
            ['permission' => 'assessment.correction.approve', 'label' => 'Directeur d\'école', 'require_recent_2fa' => true],
        ]]);
        $this->seedClass();
        $devoir = $this->assessment($this->subject('MATH'));
        foreach (['p1', 'p2', 'p3', 'p4'] as $person) {
            $grade = $this->grade($devoir, $person, ['value' => 10]);
            if ($person === 'p1') {
                $this->gradeId = (string) $grade['id'];
            }
        }
        $this->lock($devoir);
        $this->permissions->grant('teacher', 'assessment.enter');
        $this->permissions->grant('head', 'assessment.read', 'assessment.validate');
        $this->access->grant('head', 'e1');
        $this->permissions->grant('director', 'assessment.validate');
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        [$response, $json] = $this->api('POST', '/grade-corrections', 'censor', ['grade_id' => $this->gradeId, 'value' => 14, 'reason' => 'Erreur de report']);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    private function score(): float
    {
        return (float) $this->rows("SELECT score FROM grades WHERE id = '" . $this->gradeId . "'")[0]['score'];
    }

    public function testDeuxEtapesDansLOrdreAvecDesPersonnesDistinctes(): void
    {
        $correction = $this->request();
        self::assertSame(2, $correction['steps_total']);
        self::assertSame('Chef de département', $correction['current_step']);

        [$tooEarly] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'reader');
        self::assertSame(403, $tooEarly->getStatusCode());

        [$first, $json] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'head');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('pending', $json['data']['status']);
        self::assertSame(1, $json['data']['approval_step']);
        self::assertSame('Directeur d\'école', $json['data']['current_step']);
        self::assertSame(10.0, $this->score(), 'La note ne change qu\'à la dernière étape.');

        [$headAgain] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'head');
        self::assertSame(403, $headAgain->getStatusCode(), 'Le chef de département n\'a pas le droit de la seconde étape.');

        [$no2fa] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'director');
        self::assertSame(403, $no2fa->getStatusCode());
        $this->secondFactor->recent = true;
        [$final, $done] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'director');
        self::assertSame(200, $final->getStatusCode(), (string) $final->getBody());
        self::assertSame('approved', $done['data']['status']);
        self::assertSame(['head', 'director'], array_column($done['data']['approval_trail'], 'user_id'));
        self::assertSame(14.0, $this->score());
        self::assertCount(2, $this->approvals->items, 'Une demande d\'approbation chaînée par étape.');
    }

    public function testUneMemePersonneNApprouvePasDeuxEtapes(): void
    {
        $correction = $this->request();
        $this->permissions->grant('head', 'assessment.correction.approve');
        $this->secondFactor->recent = true;
        $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'head');

        [$second, $json] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'head');
        self::assertSame(403, $second->getStatusCode());
        self::assertStringContainsString('déjà approuvé une étape', $json['detail']);
    }

    public function testRejetALaSecondeEtape(): void
    {
        $correction = $this->request();
        $this->api('POST', '/grade-corrections/' . $correction['id'] . '/approve', 'head');
        [$rejected, $json] = $this->api('POST', '/grade-corrections/' . $correction['id'] . '/reject', 'director');

        self::assertSame(200, $rejected->getStatusCode());
        self::assertSame('rejected', $json['data']['status']);
        self::assertSame(10.0, $this->score());
    }
}
