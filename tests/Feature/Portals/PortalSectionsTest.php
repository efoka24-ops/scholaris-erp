<?php

declare(strict_types=1);

namespace Tests\Feature\Portals;

use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;
use Tests\Support\Portals\PortalsTestCase;

/** S20 : sections de portail enregistrées par une application et résultats publiés enrichis (crédits, mention, détail par UE). */
final class PortalSectionsTest extends PortalsTestCase
{
    protected function setUp(): void
    {
        $this->bootPortals(['portals.sections.higher' => [StudentPathSection::class, FailingSection::class]]);
        $this->actors->link('t1', 'lea', 'c1');
        $this->results->add('t1', new PublishedResult(
            'r3',
            'c1',
            'e1',
            'per2',
            'Semestre 1',
            11.55,
            null,
            null,
            'Admis',
            30,
            30,
            'Passable',
            [['code' => 'UE2', 'average' => 7.10, 'letter' => 'D', 'points' => 1.0, 'status' => 'acquise par compensation']],
        ));
    }

    public function testLaSectionDeLApplicationApparaitDansLePortailEtudiant(): void
    {
        [$response, $json] = $this->api('/portal/learner', 'lea');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];

        self::assertSame(['programme' => 'Comptabilité et finance', 'subject' => 'c1', 'portal' => 'learner'], $data['sections']['student_path']);
        self::assertFalse($data['sections']['failing']['available']);

        $semester = array_values(array_filter($data['results']['items'], static fn (array $r): bool => $r['id'] === 'r3'))[0];
        self::assertSame(30, $semester['credits_earned']);
        self::assertSame(30, $semester['credits_total']);
        self::assertSame('Passable', $semester['mention']);
        self::assertSame('acquise par compensation', $semester['details'][0]['status']);
        self::assertArrayNotHasKey('session', $semester);

        // Résultat du secondaire : aucune clé nouvelle.
        $sequence = array_values(array_filter($data['results']['items'], static fn (array $r): bool => $r['id'] === 'r1'))[0];
        self::assertSame(['id', 'establishment_id', 'period_id', 'period_label', 'average', 'rank', 'class_size', 'decision'], array_keys($sequence));
    }

    public function testLeGarantVoitLaSectionSelonLAccesEtSansSectionLaCleEstAbsente(): void
    {
        $family = $this->api('/portal/family', 'parent')[1]['data'];
        $child = array_values(array_filter($family['children'], static fn (array $c): bool => $c['person']['id'] === 'c1'))[0];
        self::assertSame('family', $child['sections']['student_path']['portal']);
        $other = array_values(array_filter($family['children'], static fn (array $c): bool => $c['person']['id'] === 'c2'))[0];
        self::assertArrayNotHasKey('student_path', $other['sections']);
    }
}

final class StudentPathSection implements PortalSection
{
    public function key(): string
    {
        return 'student_path';
    }

    public function portals(): array
    {
        return [self::LEARNER, self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        return $context->subjectPersonId === 'c1' && $context->full
            ? ['programme' => 'Comptabilité et finance', 'subject' => $context->subjectPersonId, 'portal' => $context->portal]
            : null;
    }
}

final class FailingSection implements PortalSection
{
    public function key(): string
    {
        return 'failing';
    }

    public function portals(): array
    {
        return [self::LEARNER, self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        throw new \RuntimeException('panne');
    }
}
