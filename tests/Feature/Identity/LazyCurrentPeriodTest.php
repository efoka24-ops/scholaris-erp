<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Socle\Identity\Infrastructure\LazyCurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Tests\Support\Identity\IdentityTestCase;

/** T500 : `allAt()` exposé par le contrat CurrentPeriod et relayé par l'adaptateur d'Identity ; `at()` inchangé. */
final class LazyCurrentPeriodTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->pdo()->prepare('INSERT INTO academic_years (id, tenant_id, establishment_id, label, starts_on, ends_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['Y1', 't1', 'e1', '2026-2027', '2026-09-01', '2027-06-30', 'open', 'x', 'x']);
        $this->period('P1', 'trimester', 1, '2026-09-01', '2026-12-15', null);
        $this->period('S1', 'sequence', 1, '2026-09-01', '2026-10-31', 'P1');
        $this->period('S2', 'sequence', 2, '2026-11-01', '2026-12-15', 'P1');
    }

    private function period(string $id, string $kind, int $ordinal, string $from, string $to, ?string $parent): void
    {
        $this->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, parent_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, 't1', 'e1', 'Y1', $kind, $ordinal, $from, $to, $parent, 'x', 'x']);
    }

    private function lazy(): LazyCurrentPeriod
    {
        return new LazyCurrentPeriod($this->kernel->container());
    }

    public function testLInterfaceExposeToutesLesPeriodesDuJour(): void
    {
        $periods = $this->lazy()->allAt('t1', 'e1', '2026-10-08T08:00:00+01:00');

        self::assertSame(['P1', 'S1'], array_map(static fn ($p): string => $p->id, $periods));
        self::assertSame([null, 'P1'], array_map(static fn ($p): ?string => $p->parentId, $periods));
        self::assertSame('2026-2027', $periods[0]->academicYearLabel);
    }

    public function testLAdaptateurEtLeServiceDOrganisationDonnentLaMemeReponse(): void
    {
        $direct = $this->kernel->container()->get(CurrentPeriod::class);

        self::assertEquals($direct->allAt('t1', 'e1', '2026-11-20'), $this->lazy()->allAt('t1', 'e1', '2026-11-20'));
        self::assertSame(['P1', 'S2'], array_map(static fn ($p): string => $p->id, $this->lazy()->allAt('t1', 'e1', '2026-11-20')));
    }

    public function testAucunePeriodeDateInvalideOuAutreEtablissementDonneUneListeVide(): void
    {
        self::assertSame([], $this->lazy()->allAt('t1', 'e1', '2027-01-10'));
        self::assertSame([], $this->lazy()->allAt('t1', 'e1', 'pas une date'));
        self::assertSame([], $this->lazy()->allAt('t1', 'e2', '2026-10-08'));
        self::assertSame([], $this->lazy()->allAt('t2', 'e1', '2026-10-08'));
    }

    public function testAtResteInchange(): void
    {
        self::assertSame('P1', $this->lazy()->at('t1', 'e1', '2026-10-08')?->id);
        self::assertNull($this->lazy()->at('t1', 'e1', '2027-01-10'));
    }

    public function testSansLiaisonOrganisationAucunePeriodeNEstInventee(): void
    {
        $empty = new class () implements ContainerInterface {
            public function get(string $id): never
            {
                throw new \LogicException($id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $lazy = new LazyCurrentPeriod($empty);

        self::assertSame([], $lazy->allAt('t1', 'e1', '2026-10-08'));
        self::assertNull($lazy->at('t1', 'e1', '2026-10-08'));
    }
}
