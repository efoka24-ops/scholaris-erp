<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/**
 * Cahier de qualification du 29/10/2026 : TNR-14 (type d'épreuve libre accepté) et TNR-15 (épreuve datée hors de sa
 * séquence acceptée). Le type appartient à la liste de l'établissement (assessment.kinds, sinon liste par défaut) ; la
 * date de l'épreuve tombe dans les bornes de sa période, à la création comme à la modification.
 */
final class AssessmentKindAndDateTest extends AssessmentTestCase
{
    /** @param array<string, mixed> $settings */
    private function boot(array $settings = []): string
    {
        $this->bootAssessment($settings);
        $this->seedClass();
        $this->connections()->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, parent_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['S1', 't1', 'e1', 'ay1', 'sequence', 1, '2026-09-07', '2026-10-16', null, '2026-08-01T08:00:00+01:00', '2026-08-01T08:00:00+01:00']);

        return $this->subject('FRA', 'subject', 3.0);
    }

    /**
     * @param array<string, mixed> $over
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function create(string $subject, array $over): array
    {
        return $this->api('POST', '/assessments', 'censor', $over + [
            'subject_id' => $subject, 'structure_id' => 'c1', 'period_id' => 'S1', 'academic_year_id' => 'ay1', 'kind' => 'devoir',
            'title' => 'Devoir', 'max_score' => 20.0,
        ]);
    }

    public function testTypeHorsListeRefuse(): void
    {
        $subject = $this->boot();

        [$refused, $json] = $this->create($subject, ['kind' => 'zzz', 'title' => 't']);
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertStringContainsString('Type d\'épreuve inconnu', (string) json_encode($json, JSON_UNESCAPED_UNICODE));

        [$ok] = $this->create($subject, ['kind' => 'composition']);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
    }

    public function testListeDeLEtablissementRemplaceLaListeParDefaut(): void
    {
        $subject = $this->boot(['assessment.kinds' => 'quiz,examen_blanc']);

        [$default] = $this->create($subject, ['kind' => 'devoir']);
        self::assertSame(422, $default->getStatusCode(), 'Hors de la liste propre à l\'établissement.');
        [$own] = $this->create($subject, ['kind' => 'examen_blanc']);
        self::assertSame(201, $own->getStatusCode(), (string) $own->getBody());
    }

    public function testDateHorsDeLaPeriodeRefuseeALaCreationEtALaModification(): void
    {
        $subject = $this->boot();

        [$outside, $json] = $this->create($subject, ['held_on' => '2027-02-10', 'title' => 'Hors période']);
        self::assertSame(422, $outside->getStatusCode(), (string) $outside->getBody());
        self::assertStringContainsString('du 2026-09-07 au 2026-10-16', (string) json_encode($json, JSON_UNESCAPED_UNICODE));

        [$inside, $created] = $this->create($subject, ['held_on' => '2026-10-14']);
        self::assertSame(201, $inside->getStatusCode(), (string) $inside->getBody());
        [$edge] = $this->create($subject, ['held_on' => '2026-10-16']);
        self::assertSame(201, $edge->getStatusCode(), 'Le dernier jour de la période est accepté.');

        [$moved] = $this->api('PATCH', '/assessments/' . $created['data']['id'], 'censor', ['held_on' => '2026-11-25']);
        self::assertSame(422, $moved->getStatusCode(), (string) $moved->getBody());
        [$fine] = $this->api('PATCH', '/assessments/' . $created['data']['id'], 'censor', ['held_on' => '2026-09-30']);
        self::assertSame(200, $fine->getStatusCode(), (string) $fine->getBody());
    }
}
