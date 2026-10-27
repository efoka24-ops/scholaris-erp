<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Tests\Feature\PublicNursery\Support\PublicNurseryCase;

/** FR-M12 : le carnet DOC-18 a ses routes HTTP (brouillon dans la portée, validation, publication, réédition, PDF publié seulement). */
final class ProgressBookHttpTest extends PublicNurseryCase
{
    private string $child;

    protected function setUp(): void
    {
        $this->bootPublicNursery();
        $this->grantAll('teacher', 'public_nursery.dev.observe');
        $this->grantAll('director', 'public_schools.school.write');
        $this->child = (string) $this->person('Lina', 'Fictive')['id'];
        $this->enrol($this->child);
    }

    /** @return array<string, mixed> */
    private function draft(string $synthesis = 'Belle curiosité (exemple).'): array
    {
        return $this->ok('POST', '/public-nursery/development/progress-books', 'teacher', [
            'learner_id' => $this->child, 'structure_id' => 'section-a', 'period_id' => 'T1', 'school_year' => '2026-2027',
            'items' => [['domain' => 'Langage', 'label' => 'S’exprime (exemple)', 'level' => 'in_progress', 'comment' => '']], 'synthesis' => $synthesis,
        ]);
    }

    public function testCircuitCompletEtPdfDuCarnetPublieSeulement(): void
    {
        $book = $this->draft();
        self::assertSame('draft', $book['status']);
        self::assertSame(403, $this->code('POST', '/public-nursery/development/progress-books/' . $book['id'] . '/validate', 'teacher', self::T, self::E, []));
        self::assertSame(404, $this->code('GET', '/public-nursery/development/progress-books/' . $book['id'] . '/pdf', 'director', self::T, self::E), 'Non publié : aucun PDF.');
        self::assertSame('validated', $this->ok('POST', '/public-nursery/development/progress-books/' . $book['id'] . '/validate', 'director', [])['status']);
        self::assertSame('published', $this->ok('POST', '/public-nursery/development/progress-books/' . $book['id'] . '/publish', 'director', [])['status']);
        $pdf = $this->call('GET', '/api/v1/public-nursery/development/progress-books/' . $book['id'] . '/pdf', ['Authorization' => 'Bearer director:' . self::T, 'X-Establishment-Id' => self::E], '');
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $re = $this->ok('POST', '/public-nursery/development/progress-books/' . $book['id'] . '/reissue', 'director', ['edition_label' => 'rectified']);
        self::assertSame(2, $re['edition']);
        self::assertSame(422, $this->code('POST', '/public-nursery/development/progress-books/' . $book['id'] . '/reissue', 'director', self::T, self::E, ['edition_label' => 'autre']));
    }

    public function testTermeAEviterPorteeEtIsolation(): void
    {
        $this->setting('public_nursery.dev.discouraged_terms', ['paresseux']);
        $book = $this->draft('Enfant paresseux.');
        self::assertSame(422, $this->code('POST', '/public-nursery/development/progress-books/' . $book['id'] . '/validate', 'director', self::T, self::E, []));
        $this->scopes->restrictToClasses('teacher', 'section-b');
        self::assertSame(404, $this->code('GET', '/public-nursery/development/progress-books/' . $book['id'], 'teacher', self::T, self::E));
        self::assertSame(403, $this->code('POST', '/public-nursery/development/progress-books', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'structure_id' => 'section-a', 'period_id' => 'T1', 'school_year' => '2026-2027']));
        $this->grantAll('other-director', 'public_schools.school.write');
        self::assertSame(404, $this->code('GET', '/public-nursery/development/progress-books/' . $book['id'], 'other-director', 'tenant-other', 'est-other'));
        self::assertSame(422, $this->code('POST', '/public-nursery/development/progress-books', 'teacher', self::T, self::E, ['champ' => 'x']), 'Champ inconnu refusé.');
    }
}
