<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Tests\Support\Identity\IdentityTestCase;

/** T154 : période en cours sur les tuiles de /me/contexts (contrat Organisation\Contracts\CurrentPeriod). */
final class ContextPeriodTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Collège Beta', 'secondary', '#F26B1D');
        $this->directory->add('t1', 'e2', 'Ecole Alpha', 'primary', '#1D8AF2');
        $this->directory->add('t2', 'x1', 'Autre client', 'secondary');
    }

    private function year(string $tenant, string $establishment, string $id, string $label): void
    {
        $this->pdo()->prepare('INSERT INTO academic_years (id, tenant_id, establishment_id, label, starts_on, ends_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenant, $establishment, $label, '2026-09-01', '2027-06-30', 'open', 'x', 'x']);
    }

    private function period(string $tenant, string $establishment, string $id, string $year, string $kind, int $ordinal, string $from, string $to): void
    {
        $this->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenant, $establishment, $year, $kind, $ordinal, $from, $to, 'x', 'x']);
    }

    public function testLaTuileDonneLeTrimestreEnCoursEtOmetLaPeriodeInconnue(): void
    {
        $this->year('t1', 'e1', 'Y1', '2026-2027');
        $this->period('t1', 'e1', 'P1', 'Y1', 'trimester', 1, '2026-09-01', '2026-12-15');
        $this->period('t1', 'e1', 'P2', 'Y1', 'trimester', 2, '2027-01-04', '2027-03-31');
        $this->period('t1', 'e1', 'S1', 'Y1', 'sequence', 1, '2026-09-01', '2026-10-31');
        $this->createUser('t1', 'Dan', '+237691000030', [['parent', 'establishment', 'e1'], ['parent', 'establishment', 'e2']]);
        $token = $this->token('+237691000030');

        $tiles = $this->json($this->call('GET', '/api/v1/me/contexts', $this->auth($token)))['data'];

        // Le 8 octobre 2026 : le trimestre (non la séquence) est la période en cours de e1.
        self::assertSame(['id' => 'P1', 'kind' => 'trimester', 'ordinal' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-15', 'academic_year' => '2026-2027'], $tiles[0]['period']);
        // e2 n'a aucune période : le champ est omis, jamais inventé.
        self::assertSame('e2', $tiles[1]['id']);
        self::assertArrayNotHasKey('period', $tiles[1]);
    }

    public function testEntreDeuxPeriodesOuAilleursAucunePeriode(): void
    {
        $this->year('t1', 'e1', 'Y1', '2026-2027');
        $this->period('t1', 'e1', 'P1', 'Y1', 'trimester', 1, '2026-11-01', '2026-12-15');
        $current = $this->kernel->container()->get(CurrentPeriod::class);

        self::assertNull($current->at('t1', 'e1', '2026-10-08'));
        self::assertSame('P1', $current->at('t1', 'e1', '2026-11-01T08:00:00+01:00')?->id);
        self::assertNull($current->at('t1', 'e1', 'pas une date'));
    }

    public function testLesPeriodesDUnAutreClientOuEtablissementNeFuitentPas(): void
    {
        $this->year('t2', 'x1', 'Y2', '2026-2027');
        $this->period('t2', 'x1', 'PX', 'Y2', 'trimester', 1, '2026-09-01', '2026-12-15');
        $current = $this->kernel->container()->get(CurrentPeriod::class);

        self::assertNull($current->at('t1', 'x1', '2026-10-08'));
        self::assertNull($current->at('t1', 'e1', '2026-10-08'));
        self::assertSame('PX', $current->at('t2', 'x1', '2026-10-08')?->id);
    }
}
