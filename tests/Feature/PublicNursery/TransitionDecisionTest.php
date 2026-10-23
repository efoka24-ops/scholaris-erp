<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\NurseryPromotionPlanner;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\TransitionService;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

final class TransitionDecisionTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $access = new class () implements ApplicationAccess {
            /** @var array<string, string|null> */
            public array $states = ['tenant-dev' => self::ACTIVE, 'tenant-private' => self::CLOSED];

            public function state(string $tenantId, string $appKey): ?string
            {
                return $this->states[$tenantId] ?? self::CLOSED;
            }

            public function allowsWrites(string $tenantId, string $appKey): bool
            {
                return in_array($this->state($tenantId, $appKey), [self::TRIAL, self::ACTIVE, self::PAST_DUE], true);
            }

            public function allowsReads(string $tenantId, string $appKey): bool
            {
                return $this->state($tenantId, $appKey) !== self::CLOSED;
            }
        };
        $this->bootOrganisation([ApplicationAccess::class => $access]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery');
        $this->seedEstablishment('tenant-private', 'est-private', 'Maternelle privée', 'nursery');
    }

    public function testLaPropositionAutomatiqueDependDuNiveauEtDuPortailPublic(): void
    {
        $ps = $this->person('Lina', 'Test');
        $ms = $this->person('Noa', 'Test');
        $gs = $this->person('Eva', 'Test');
        $this->year('tenant-dev', 'est-dev', 'y2026', '2026-2027');
        $this->year('tenant-dev', 'est-dev', 'y2027', '2027-2028');
        $this->structure('tenant-dev', 'est-dev', 's-ps', 'PS', 'level');
        $this->structure('tenant-dev', 'est-dev', 's-ms', 'MS', 'level');
        $this->structure('tenant-dev', 'est-dev', 's-gs', 'GS', 'level');
        $this->enrol('tenant-dev', 'est-dev', (string) $ps['id'], 's-ps', 'y2026');
        $this->enrol('tenant-dev', 'est-dev', (string) $ms['id'], 's-ms', 'y2026');
        $this->enrol('tenant-dev', 'est-dev', (string) $gs['id'], 's-gs', 'y2026');

        $result = $this->kernel->container()->get(NurseryPromotionPlanner::class)->promote('tenant-dev', 'est-dev', 'y2026', 'y2027');

        self::assertSame(3, $result->counts['proposed']);
        $rows = $this->connections()->pdo()->query('SELECT learner_id, from_level, proposal, status, school_year FROM pn_transition_decisions ORDER BY learner_id')->fetchAll();
        $byLearner = array_column($rows, null, 'learner_id');
        self::assertSame(['ps', 'promote', 'proposed', '2026-2027'], [$byLearner[$ps['id']]['from_level'], $byLearner[$ps['id']]['proposal'], $byLearner[$ps['id']]['status'], $byLearner[$ps['id']]['school_year']]);
        self::assertSame('promote', $byLearner[$ms['id']]['proposal']);
        self::assertSame('to_primary', $byLearner[$gs['id']]['proposal']);
    }

    public function testLeMaintienExigeUneDemandeParentaleEtUnAvisInspecteur(): void
    {
        $learner = $this->person('Sia', 'Test');
        $this->year('tenant-dev', 'est-dev', 'y2026', '2026-2027');
        $service = $this->kernel->container()->get(TransitionService::class);
        $proposal = $service->propose('tenant-dev', 'est-dev', (string) $learner['id'], '2026-2027', 'gs', 'Prête pour le primaire.');

        try {
            $service->decide('tenant-dev', 'est-dev', (string) $proposal['id'], 'director', ['decision' => 'retain']);
            self::fail('422 attendu.');
        } catch (\Scholaris\Core\Http\HttpException $e) {
            self::assertSame(422, $e->status);
        }

        $decided = $service->decide('tenant-dev', 'est-dev', (string) $proposal['id'], 'director', [
            'decision' => 'retain',
            'parent_request_ref' => 'REQ-PARENT-001',
            'parent_request_on' => '2027-06-15',
            'inspector_opinion' => 'favourable',
            'inspector_id' => 'inspector-1',
            'inspector_on' => '2027-06-18',
        ]);
        self::assertSame(['retain', 'decided', 'REQ-PARENT-001', 'favourable'], [$decided['decision'], $decided['status'], $decided['parent_request_ref'], $decided['inspector_opinion']]);
    }

    public function testLaMaternellePriveeNestPasCibleeParLePlannerConditionnel(): void
    {
        $providers = $this->kernel->container()->get(ClosureProviders::class);
        $public = array_map(static fn (object $planner): string => $planner::class, $providers->promotersFor('tenant-dev', 'est-dev', 'nursery'));
        $private = array_map(static fn (object $planner): string => $planner::class, $providers->promotersFor('tenant-private', 'est-private', 'nursery'));

        self::assertContains(NurseryPromotionPlanner::class, $public);
        self::assertNotContains(NurseryPromotionPlanner::class, $private);
    }

    /** @return array<string, mixed> */
    private function person(string $first, string $last): array
    {
        return $this->kernel->container()->get(PersonService::class)->create('tenant-dev', ['first_name' => $first, 'last_name' => $last, 'birth_date' => '2021-01-01']);
    }

    private function year(string $tenantId, string $establishmentId, string $id, string $label): void
    {
        $now = '2026-09-01T08:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO academic_years (id, tenant_id, establishment_id, label, starts_on, ends_on, status, closed_at, closed_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
        )->execute([$id, $tenantId, $establishmentId, $label, '2026-09-01', '2027-06-30', 'open', $now, $now]);
    }

    private function structure(string $tenantId, string $establishmentId, string $id, string $name, string $kind): void
    {
        $now = '2026-09-01T08:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO pedagogical_structures (id, tenant_id, establishment_id, org_unit_id, kind, name, capacity, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, NULL, ?, ?)',
        )->execute([$id, $tenantId, $establishmentId, $kind, $name, $now, $now]);
    }

    private function enrol(string $tenantId, string $establishmentId, string $personId, string $structureId, string $yearId): void
    {
        $now = '2026-09-01T08:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)',
        )->execute([(string) new Ulid(), $tenantId, $personId, $establishmentId, $structureId, $yearId, 'active', $now, $now]);
    }
}
