<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Tests\Support\Higher\Grades\GradesTestCase;

/** API-16, RG-NOT-4 : l'étudiant ne voit que ses notes publiées, sans blocage financier ; requête de note sous 7 jours (EX-NOT-07). */
final class StudentResultsTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    /** @return string évaluation publiée (examen de Mathématiques financières : p1 = 6,5) avec CC à 8 publié aussi */
    private function publishedMf(): string
    {
        $cc = (string) $this->assessment('cc')['id'];
        $this->enter($cc, ['p1' => 8.0, 'p2' => 12.0, 'p3' => 10.0]);
        $exam = (string) $this->assessment()['id'];
        $this->enter($exam, ['p1' => 6.5, 'p2' => 12.5, 'p3' => 'ABI']);
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;
        $this->fakes->recent2fa[self::T . ':' . self::REGISTRAR] = true;
        foreach ([$cc, $exam] as $id) {
            $this->ok('POST', '/higher/grade-assessments/' . $id . '/submit', null, self::TEACHER);
            $this->ok('POST', '/higher/grade-assessments/' . $id . '/validate', null, self::CHEF);
            $this->ok('POST', '/higher/grade-assessments/' . $id . '/lock', null, self::CHEF);
        }
        self::assertSame([], $this->ok('GET', '/higher/students/me/results', null, self::STUDENT)['elements'], 'Verrouillée mais non publiée : rien.');
        foreach ([$cc, $exam] as $id) {
            $this->ok('POST', '/higher/grade-assessments/' . $id . '/publish', null, self::REGISTRAR);
        }

        return $exam;
    }

    public function testLEtudiantVoitSesSeulesNotesPublieesEtLaNoteDEc(): void
    {
        $this->publishedMf();
        $results = $this->ok('GET', '/higher/students/me/results', null, self::STUDENT);

        self::assertSame('p1', $results['person_id']);
        self::assertCount(1, $results['elements']);
        $mf = $results['elements'][0];
        self::assertSame('MF', $mf['element_code']);
        self::assertEquals(7.10, $mf['ec_mark'], 'F1 : 0,4 × 8 + 0,6 × 6,5 = 7,10 (exemple Marie N.).');
        self::assertEqualsCanonicalizing([8.0, 6.5], array_column($mf['assessments'], 'score'));
        self::assertTrue($mf['assessments'][0]['query_open']);
    }

    public function testBlocageFinancierActifRefuseLaConsultation(): void
    {
        $this->publishedMf();
        $this->fakes->blocked['p1:results'] = true;
        [$status, $problem] = $this->call('GET', '/higher/students/me/results', null, self::STUDENT);

        self::assertSame([403, 'https://api.scholaris.cm/errors/financial-hold'], [$status, $problem['type']]);
    }

    public function testRequeteDansLeDelaiPuisReponseDuChefDeDepartement(): void
    {
        $exam = $this->publishedMf();
        $markId = $this->line($exam, 'p1')['mark_id'];
        $query = $this->ok('POST', '/higher/students/me/grade-queries', ['mark_id' => $markId, 'message' => 'Je conteste la note de la question 3.'], self::STUDENT);

        self::assertSame('open', $query['status']);
        self::assertSame(409, $this->call('POST', '/higher/students/me/grade-queries', ['mark_id' => $markId, 'message' => 'Encore'], self::STUDENT)[0]);
        self::assertSame(404, $this->call('POST', '/higher/students/me/grade-queries', ['mark_id' => $this->line($exam, 'p2')['mark_id'], 'message' => 'Pas la mienne'], self::STUDENT)[0]);
        self::assertSame(403, $this->call('POST', '/higher/grade-queries/' . $query['id'] . '/respond', ['decision' => 'accepted', 'response' => 'ok'], self::TEACHER)[0]);
        $answered = $this->ok('POST', '/higher/grade-queries/' . $query['id'] . '/respond', ['decision' => 'accepted', 'response' => 'Recorrection demandée à l\'enseignant.'], self::CHEF);

        self::assertSame('accepted', $answered['status']);
        self::assertSame(['accepted'], array_column($this->ok('GET', '/higher/students/me/grade-queries', null, self::STUDENT), 'status'));
        self::assertCount(1, $this->ok('GET', '/higher/grade-queries', null, self::CHEF));
    }

    public function testRequeteHorsDelaiRefusee(): void
    {
        $exam = $this->publishedMf();
        $this->clock->freeze('2026-10-21');
        [$status, $problem] = $this->call('POST', '/higher/students/me/grade-queries', ['mark_id' => $this->line($exam, 'p1')['mark_id'], 'message' => 'Trop tard'], self::STUDENT);

        self::assertSame(409, $status);
        self::assertStringContainsString('7 jours', (string) $problem['detail']);
    }

    public function testUnEtudiantNAccedePasAuxRoutesDeSaisie(): void
    {
        $exam = $this->publishedMf();
        self::assertSame(404, $this->call('GET', '/higher/grade-assessments/' . $exam . '/marks', null, self::STUDENT)[0]);
        self::assertSame(403, $this->call('GET', '/higher/students/me/results', null, self::TEACHER)[0]);
    }
}
