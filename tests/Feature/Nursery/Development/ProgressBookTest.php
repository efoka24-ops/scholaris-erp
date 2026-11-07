<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Development;

/** EX-MAT-17 (US11) : carnet trimestriel brouillon, soumis, validé par la directrice puis publié ; invisible du parent avant publication. */
final class ProgressBookTest extends DevelopmentTestCase
{
    private string $parent;

    protected function setUp(): void
    {
        $this->bootDevelopment();
        $this->parent = 'parent-' . $this->ada['child'];
        $this->ok('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'in_progress', 'observed_on' => '2026-09-20'], 'educatrice-a');
        $this->ok('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'observed_on' => '2026-10-15'], 'educatrice-a');
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return $this->ok('POST', '/nursery/progress-books', ['learner_id' => $this->ada['child']], 'educatrice-a');
    }

    public function testLeBrouillonReprendLeDernierNiveauObserveParItemSansAucunCalcul(): void
    {
        $book = $this->draft();
        self::assertSame('draft', $book['status']);
        self::assertCount(1, $book['observations']);
        self::assertSame('acquired', $book['observations'][0]['level_code'], 'Le dernier niveau observé, tel quel.');
        $payload = (string) json_encode($book);
        foreach (['"average"', '"score"', '"rank"', '"total"', '"grade"'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $payload);
        }
        self::assertSame(409, $this->code('POST', '/nursery/progress-books', ['learner_id' => $this->ada['child']], 'educatrice-a'), 'Un seul carnet par enfant et par période.');
    }

    public function testCircuitCompletEtInvisibiliteAvantPublication(): void
    {
        $book = $this->draft();
        $id = (string) $book['id'];
        $this->ok('PUT', '/nursery/progress-books/' . $id, ['synthesis' => 'Une période riche en découvertes.', 'domains' => ['LANG' => 'S’exprime de mieux en mieux.']], 'educatrice-a');
        self::assertSame('submitted', $this->ok('POST', '/nursery/progress-books/' . $id . '/submit', [], 'educatrice-a')['status']);
        self::assertSame(409, $this->code('PUT', '/nursery/progress-books/' . $id, ['synthesis' => 'Modif'], 'educatrice-a'), 'Un carnet soumis ne se rédige plus.');
        self::assertSame([], $this->ok('GET', '/nursery/portal/progress-books?learner_id=' . $this->ada['child'], null, $this->parent), 'Soumis : invisible du parent.');

        self::assertSame(403, $this->code('POST', '/nursery/progress-books/' . $id . '/validate', [], 'educatrice-a'), 'Seule la directrice valide.');
        self::assertSame('validated', $this->ok('POST', '/nursery/progress-books/' . $id . '/validate', [])['status']);
        self::assertSame([], $this->ok('GET', '/nursery/portal/progress-books?learner_id=' . $this->ada['child'], null, $this->parent), 'Validé : toujours invisible du parent.');

        self::assertSame('published', $this->ok('POST', '/nursery/progress-books/' . $id . '/publish', [])['status']);
        $visible = $this->ok('GET', '/nursery/portal/progress-books?learner_id=' . $this->ada['child'], null, $this->parent);
        self::assertCount(1, $visible);
        self::assertSame('Une période riche en découvertes.', $visible[0]['synthesis']);
        self::assertContains($this->ada['parent'], $this->messenger->recipients());
        self::assertSame(409, $this->code('POST', '/nursery/progress-books/' . $id . '/return', []), 'Un carnet publié est figé.');
        self::assertSame(409, $this->code('POST', '/nursery/progress-books/' . $id . '/publish', []));
    }

    public function testUnTermeAEviterBloqueLaSoumissionEtLaValidation(): void
    {
        $this->setSetting(self::TA, self::EA, 'nursery.dev.discouraged_terms', ['paresseux']);
        $id = (string) $this->draft()['id'];
        $this->ok('PUT', '/nursery/progress-books/' . $id, ['synthesis' => 'Enfant paresseux.'], 'educatrice-a');
        self::assertSame(422, $this->code('POST', '/nursery/progress-books/' . $id . '/submit', [], 'educatrice-a'));
        $this->ok('PUT', '/nursery/progress-books/' . $id, ['synthesis' => 'Enfant qui prend son temps.'], 'educatrice-a');
        self::assertSame('submitted', $this->ok('POST', '/nursery/progress-books/' . $id . '/submit', [], 'educatrice-a')['status']);
    }

    public function testLaDirectriceRenvoieUnCarnetALEducatrice(): void
    {
        $id = (string) $this->draft()['id'];
        $this->ok('POST', '/nursery/progress-books/' . $id . '/submit', [], 'educatrice-a');
        self::assertSame('draft', $this->ok('POST', '/nursery/progress-books/' . $id . '/return', [])['status']);
        self::assertSame(409, $this->code('POST', '/nursery/progress-books/' . $id . '/validate', []), 'Il faut soumettre à nouveau.');
    }

    public function testPerimetreDeSectionEtIsolationEntreClients(): void
    {
        $id = (string) $this->draft()['id'];
        self::assertSame(404, $this->code('GET', '/nursery/progress-books/' . $id, null, 'educatrice-b'));
        self::assertSame(403, $this->code('POST', '/nursery/progress-books', ['learner_id' => $this->ada['child']], 'educatrice-b'));
        self::assertSame(403, $this->code('PUT', '/nursery/progress-books/' . $id, ['synthesis' => 'Pirate'], 'educatrice-b'));
        self::assertCount(0, $this->ok('GET', '/nursery/progress-books', null, 'educatrice-b'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('GET', '/nursery/progress-books/' . $id, null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/progress-books/' . $id . '/validate', [], 'directrice-b', self::TB, self::EB));
    }

    public function testUnParentNeVoitQueLesCarnetsDeSesEnfants(): void
    {
        $id = (string) $this->draft()['id'];
        $this->ok('POST', '/nursery/progress-books/' . $id . '/submit', [], 'educatrice-a');
        $this->ok('POST', '/nursery/progress-books/' . $id . '/validate', []);
        $this->ok('POST', '/nursery/progress-books/' . $id . '/publish', []);
        $other = 'parent-' . $this->bao['child'];
        self::assertSame(404, $this->code('GET', '/nursery/portal/progress-books?learner_id=' . $this->ada['child'], null, $other));
        self::assertSame(422, $this->code('GET', '/nursery/portal/progress-books', null, $other));
        self::assertSame(403, $this->code('GET', '/nursery/progress-books', null, $this->parent), 'Le parent n’a aucune permission du personnel.');
    }

    public function testSansTrimestreEnCoursAucunCarnetNeSeCree(): void
    {
        $this->periods->open = false;
        self::assertSame(422, $this->code('POST', '/nursery/progress-books', ['learner_id' => $this->ada['child']], 'educatrice-a'));
    }
}
