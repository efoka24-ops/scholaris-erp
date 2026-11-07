<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\CustodyRestrictionService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

final class CustodyRestrictionTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $this->bootOrganisation([
            ApplicationAccess::class => new class () implements ApplicationAccess {
                public function state(string $tenantId, string $appKey): ?string
                {
                    return self::ACTIVE;
                }
                public function allowsWrites(string $tenantId, string $appKey): bool
                {
                    return true;
                }
                public function allowsReads(string $tenantId, string $appKey): bool
                {
                    return true;
                }
            },
        ]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery', 'teacher', 'director', 'parent-user', 'inspector');
        $this->grantAll('teacher', 'public_nursery.pickup.handover');
        $this->grantAll('director', 'settings.manage');
        $this->grantAll('inspector', 'public_schools.aggregates.read');
    }

    public function testVisibleSectionEtDirecteurSeulementAvecConsultationJournalisee(): void
    {
        $parent = $this->person('Marie', 'Meka');
        $child = $this->person('Lina', 'Meka');
        $this->linkUser('parent-user', (string) $parent['id']);
        $this->linkParent((string) $child['id'], (string) $parent['id']);
        $this->enrol((string) $child['id']);

        $created = $this->api('POST', '/public-nursery/pickup/restrictions', 'director', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'restricted_name' => 'Parent interdit',
            'nature' => 'court_order',
            'decision_reference' => 'JUG-2026-004',
            'valid_from' => '2026-10-08',
        ])[1]['data'];

        [, $teacher] = $this->api('GET', '/public-nursery/pickup/restrictions?learner_id=' . $child['id'] . '&active_only=1', 'teacher', 'tenant-dev', 'est-dev');
        self::assertCount(1, $teacher['data']);
        self::assertSame('Parent interdit', $teacher['data'][0]['restricted_name']);

        [, $director] = $this->api('GET', '/public-nursery/pickup/restrictions?learner_id=' . $child['id'], 'director', 'tenant-dev', 'est-dev');
        self::assertSame($created['id'], $director['data'][0]['id']);

        self::assertSame(404, $this->code('GET', '/public-nursery/pickup/restrictions?learner_id=' . $child['id'], 'parent-user', 'tenant-dev', 'est-dev'));
        self::assertSame(404, $this->code('GET', '/public-nursery/pickup/restrictions?learner_id=' . $child['id'], 'inspector', 'tenant-dev', 'est-dev'));

        $audit = $this->connections()->pdo()->query("SELECT action, object_type, object_id, actor_id, after_json FROM audit_log WHERE tenant_id = 'tenant-dev' ORDER BY seq")->fetchAll();
        self::assertCount(3, $audit);
        self::assertSame('public_nursery.custody_restriction.recorded', $audit[0]['action']);
        self::assertSame('public_nursery.custody_restriction.read', $audit[1]['action']);
        self::assertSame('public_nursery.custody_restriction.read', $audit[2]['action']);
        self::assertSame('learner', $audit[1]['object_type']);
        self::assertSame((string) $child['id'], $audit[1]['object_id']);
        self::assertSame('teacher', $audit[1]['actor_id']);
        self::assertStringContainsString('"count":1', (string) $audit[1]['after_json']);
    }

    public function testServiceFiltreLesRestrictionsActivesEtPermetLaLevee(): void
    {
        $child = $this->person('Lina', 'Meka');
        $this->enrol((string) $child['id']);
        $service = $this->kernel->container()->get(CustodyRestrictionService::class);

        $first = $service->record('tenant-dev', 'est-dev', (string) $child['id'], [
            'restricted_name' => 'Parent interdit',
            'nature' => 'court_order',
            'decision_reference' => 'JUG-1',
            'valid_from' => '2026-10-01',
            'valid_to' => '2026-10-31',
        ], 'director');
        $second = $service->record('tenant-dev', 'est-dev', (string) $child['id'], [
            'restricted_name' => 'Voisin',
            'nature' => 'temporary_ban',
            'decision_reference' => 'JUG-2',
            'valid_from' => '2026-11-01',
        ], 'director');

        self::assertSame([$first['id']], array_column($service->activeFor('tenant-dev', 'est-dev', (string) $child['id'], '2026-10-08'), 'id'));
        self::assertSame([$second['id']], array_column($service->activeFor('tenant-dev', 'est-dev', (string) $child['id'], '2026-11-03'), 'id'));

        $lifted = $service->lift('tenant-dev', 'est-dev', (string) $second['id'], 'Ordonnance levée.', 'director');
        self::assertSame('lifted', $lifted['status']);
        self::assertSame([], $service->activeFor('tenant-dev', 'est-dev', (string) $child['id'], '2026-11-03'));
    }

    /** @return array<string, mixed> */
    private function person(string $first, string $last): array
    {
        return $this->kernel->container()->get(PersonService::class)->create('tenant-dev', ['first_name' => $first, 'last_name' => $last, 'birth_date' => '2021-01-01']);
    }

    private function linkUser(string $userId, string $personId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'tenant-dev', $personId, 'fr', 'active', $now, $now]);
    }

    private function linkParent(string $learnerId, string $parentId): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO person_relations (id, tenant_id, person_id, related_person_id, kind, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new \Scholaris\Core\Support\Ulid(), 'tenant-dev', $learnerId, $parentId, 'financial_guarantor', 'admin', '2026-10-08T09:00:00+01:00']);
    }

    private function enrol(string $personId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare('INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)')
            ->execute([(string) new \Scholaris\Core\Support\Ulid(), 'tenant-dev', $personId, 'est-dev', 'section-a', 'year-2026', 'active', $now, $now]);
    }
}
