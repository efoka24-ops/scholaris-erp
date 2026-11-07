<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Tests\Feature\PublicNursery\Support\PublicNurseryCase;

/**
 * C-106 (NOT-14) : fermeture exceptionnelle de l'école déclarée par la direction : chaque parent est prévenu UNE fois (déclaration
 * idempotente), jamais pour un jour passé, motif obligatoire, aucune alerte de non-récupération ce jour-là, isolation entre clients.
 */
final class ClosureTest extends PublicNurseryCase
{
    private string $parentA;
    private string $parentB;

    protected function setUp(): void
    {
        $this->bootPublicNursery();
        $this->grantAll('director', 'public_schools.school.write');
        $this->grantAll('teacher', 'public_nursery.pickup.handover');
        $child = (string) $this->person('Lina', 'Fictive')['id'];
        $sibling = (string) $this->person('Paul', 'Fictif')['id'];
        $this->parentA = (string) $this->person('Mere', 'Fictive')['id'];
        $this->parentB = (string) $this->person('Pere', 'Fictif')['id'];
        $this->enrol($child);
        $this->enrol($sibling);
        $this->linkParent($child, $this->parentA);
        $this->linkParent($sibling, $this->parentA);
        $this->linkParent($sibling, $this->parentB);
        $this->setting('public_nursery.day.end_time', '13:30');
    }

    public function testLesParentsSontPrevenusUneSeuleFoisMemeAvecDeuxEnfants(): void
    {
        $result = $this->ok('POST', '/public-nursery/pickup/closures', 'director', ['date' => '2026-10-09', 'reason' => 'Deuil national (fictif)']);
        self::assertFalse($result['already_declared']);
        self::assertSame(2, $result['parents']);
        self::assertSame(['pn.not14.parent', 'pn.not14.parent'], array_column($this->safety->sent, 'template'));
        self::assertEqualsCanonicalizing([$this->parentA, $this->parentB], array_column($this->safety->sent, 'person'));
        self::assertSame('Deuil national (fictif)', $this->safety->sent[0]['variables']['details']);

        $again = $this->ok('POST', '/public-nursery/pickup/closures', 'director', ['date' => '2026-10-09', 'reason' => 'Autre motif']);
        self::assertTrue($again['already_declared']);
        self::assertCount(2, $this->safety->sent, 'Aucun second message.');
        self::assertSame(['2026-10-09'], array_column($this->ok('GET', '/public-nursery/pickup/closures', 'director'), 'date'));
    }

    public function testAucuneAlerteDeNonRecuperationUnJourDeFermeture(): void
    {
        $zone = new \DateTimeZone('Africa/Douala');
        $gate = $this->kernel->container()->get(\Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService::class);
        $child = (string) $this->connections()->pdo()->query('SELECT person_id FROM enrollments ORDER BY id LIMIT 1')->fetchColumn();
        $this->ok('POST', '/public-nursery/pickup/arrivals', 'teacher', ['learner_id' => $child, 'school_day' => '2026-10-08']);
        $this->ok('POST', '/public-nursery/pickup/closures', 'director', ['date' => '2026-10-08', 'reason' => 'Fermeture imprévue (fictive)']);
        $this->safety->sent = [];

        self::assertSame(['parent' => 0, 'director' => 0], $gate->watch(self::T, self::E, new \DateTimeImmutable('2026-10-08T14:20:00', $zone)));
        self::assertSame([], $this->safety->sent);
    }

    public function testMotifObligatoireJourPasseDroitsEtIsolation(): void
    {
        self::assertSame(422, $this->code('POST', '/public-nursery/pickup/closures', 'director', self::T, self::E, ['date' => '2026-10-09']));
        self::assertSame(422, $this->code('POST', '/public-nursery/pickup/closures', 'director', self::T, self::E, ['date' => '2026-10-07', 'reason' => 'Trop tard']));
        self::assertSame(422, $this->code('POST', '/public-nursery/pickup/closures', 'director', self::T, self::E, ['date' => 'demain', 'reason' => 'x']));
        self::assertSame(403, $this->code('POST', '/public-nursery/pickup/closures', 'teacher', self::T, self::E, ['date' => '2026-10-09', 'reason' => 'x']), 'Direction seulement.');
        $this->ok('POST', '/public-nursery/pickup/closures', 'director', ['date' => '2026-10-09', 'reason' => 'Motif fictif']);
        $this->grantAll('other-director', 'public_schools.school.write');
        self::assertSame([], $this->ok('GET', '/public-nursery/pickup/closures', 'other-director', null, 'tenant-other', 'est-other'), 'Isolation entre clients.');
    }
}
