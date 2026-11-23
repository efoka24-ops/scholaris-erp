<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Tests\Support\Assessment\AssessmentTestCase;

/** Calcul des résultats, publication aux familles et lecture limitée aux enfants du parent (T121). */
final class ResultsPublicationTest extends AssessmentTestCase
{
    /** @var array{math: string, french: string, math_devoir: string, french_devoir: string} */
    private array $class;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $this->class = $this->gradedClass(true);
        $this->people->add('t1', 'parent1', 'Marie', 'Abega');
        $this->people->link('t1', 'parent1', 'p1');
        $this->actors->persons['parent'] = 'parent1';
    }

    private function publish(string $user = 'censor'): \Psr\Http\Message\ResponseInterface
    {
        [$response] = $this->api('POST', '/results/publish', $user, ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Séquence 1']);

        return $response;
    }

    public function testLePersonnelLitLesResultatsCalculesAvecRangEtMention(): void
    {
        $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        [$response, $json] = $this->api('GET', '/results?filter[structure_id]=c1&filter[period_id]=seq1', 'reader');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = array_column($json['data'], null, 'person_id');
        self::assertEquals([15.33, 1, 4, 'Bien', 'computed'], [$rows['p1']['average'], $rows['p1']['rank_in_class'], $rows['p1']['class_size'], $rows['p1']['mention'], $rows['p1']['status']]);
        self::assertEquals([7.67, 4], [$rows['p3']['average'], $rows['p3']['rank_in_class']]);
        self::assertSame(['MATH', 'FRAN'], array_column($rows['p1']['details'], 'code'));
    }

    public function testLaPublicationExigeLeDroitEtMetLesEpreuvesAEtatPublie(): void
    {
        self::assertSame(403, $this->publish('teacher')->getStatusCode());

        $response = $this->publish();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = $this->decode($response)['data'];
        self::assertSame(['published', 'published', 'published', 'published'], array_column($rows, 'status'));
        self::assertCount(4, $this->rows('SELECT id FROM results WHERE published_at IS NOT NULL'));
        self::assertSame(['published', 'published'], array_column($this->rows('SELECT status FROM assessments'), 'status'));
    }

    public function testLaPublicationEstRefuseeTantQueLaSaisieNEstPasVerrouillee(): void
    {
        $this->api('POST', '/assessments', 'censor', [
            'subject_id' => $this->class['math'], 'structure_id' => 'c1', 'period_id' => 'seq1', 'kind' => 'devoir', 'title' => 'Devoir 2',
        ]);
        $response = $this->publish();

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Passage impossible', $this->decode($response)['detail']);
        self::assertSame([], $this->rows("SELECT id FROM results WHERE status = 'published'"));
    }

    public function testLaPublicationAttendLaValidationDuConseilDeClasse(): void
    {
        $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        $response = $this->publish();

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('conseil de classe', $this->decode($response)['detail']);

        [$council] = $this->api('GET', '/class-councils', 'council');
        $this->api('POST', '/class-councils/' . $this->decode($council)['data'][0]['id'] . '/validate', 'council');
        self::assertSame(200, $this->publish()->getStatusCode());
    }

    public function testUnParentNeLitQueLesResultatsPublicsDeSesEnfants(): void
    {
        // Avant publication : rien.
        [$before, $none] = $this->api('GET', '/results', 'parent');
        self::assertSame(200, $before->getStatusCode());
        self::assertSame([], $none['data']);

        $this->publish();
        [$response, $json] = $this->api('GET', '/results', 'parent');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['p1'], array_column($json['data'], 'person_id'), 'Seul son enfant, jamais le reste de la classe.');
        self::assertArrayNotHasKey('proposal', $json['data'][0]);
        self::assertArrayNotHasKey('status', $json['data'][0]);
        self::assertEquals([15.33, 1], [$json['data'][0]['average'], $json['data'][0]['rank_in_class']]);

        $own = $this->rows("SELECT id FROM results WHERE person_id = 'p1'")[0]['id'];
        $other = $this->rows("SELECT id FROM results WHERE person_id = 'p2'")[0]['id'];
        self::assertSame(200, $this->api('GET', '/results/' . $own, 'parent')[0]->getStatusCode());
        self::assertSame(404, $this->api('GET', '/results/' . $other, 'parent')[0]->getStatusCode());

        [$filtered, $empty] = $this->api('GET', '/results?filter[person_id]=p2', 'parent');
        self::assertSame(200, $filtered->getStatusCode());
        self::assertSame([], $empty['data']);

        [$writes] = $this->api('POST', '/results/publish', 'parent', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        self::assertSame(403, $writes->getStatusCode());
    }

    public function testUnComptePasRattacheAUnePersonneNeLitRien(): void
    {
        $this->publish();
        unset($this->actors->persons['parent']);

        [$response] = $this->api('GET', '/results', 'parent');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnResultatNonPublieNEstJamaisVisibleParLeParentMemeParIdentifiant(): void
    {
        $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        $own = $this->rows("SELECT id FROM results WHERE person_id = 'p1'")[0]['id'];

        [$response] = $this->api('GET', '/results/' . $own, 'parent');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testResultsDirectoryNeRenvoieQueLesResultatsPublies(): void
    {
        /** @var ResultsDirectory $directory */
        $directory = $this->container()->get(ResultsDirectory::class);

        self::assertSame([], $directory->publishedFor('t1', 'p1'));

        $this->publish();
        $results = $directory->publishedFor('t1', 'p1');

        self::assertCount(1, $results);
        self::assertInstanceOf(PublishedResult::class, $results[0]);
        self::assertEquals(['p1', 'e1', 'seq1', 'Séquence 1', 15.33, 1, 4], [
            $results[0]->personId, $results[0]->establishmentId, $results[0]->periodId, $results[0]->periodLabel, $results[0]->average, $results[0]->rank, $results[0]->classSize,
        ]);
        self::assertSame([], $directory->publishedFor('t2', 'p1'), 'Cloisonné par client.');
    }

    public function testUneCorrectionApprouvéeApresPublicationMetLesResultatsAJour(): void
    {
        $this->publish();
        $grade = $this->rows("SELECT id FROM grades WHERE person_id = 'p3' AND score = 8")[0]['id'];
        [, $created] = $this->api('POST', '/grade-corrections', 'censor', ['grade_id' => $grade, 'value' => 20, 'reason' => 'erreur de report']);
        $this->api('POST', '/grade-corrections/' . $created['data']['id'] . '/approve', 'director');

        $result = $this->rows("SELECT average, rank_in_class, status FROM results WHERE person_id = 'p3'")[0];

        self::assertEquals(15.67, (float) $result['average'], '(20 x 2 + 7) / 3');
        self::assertSame(1, (int) $result['rank_in_class']);
        self::assertSame('published', $result['status']);
    }
}
