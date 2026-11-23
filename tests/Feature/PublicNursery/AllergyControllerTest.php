<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryScopes;
use Tests\Support\Organisation\OrganisationTestCase;

final class AllergyControllerTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'pn-allergy-test-secret';
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
            PermissionScopes::class => NurseryScopes::binding(),
        ]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery', 'teacher', 'canteen', 'outsider', 'parent-user');
        $this->grantAll('teacher', 'people.health.read');
        $this->grantAll('canteen', 'people.health.read');
        $this->grantAll('outsider', 'people.read');
    }

    public function testLeParentDeclareEtLaSectionLitLesAllergiesAlorsQueLesAutresRolesSontRefuses(): void
    {
        $parent = $this->person('Marie', 'Meka');
        $child = $this->person('Lina', 'Meka');
        $other = $this->person('Noa', 'Meka');
        $this->linkUser('parent-user', (string) $parent['id']);
        $this->linkParent((string) $child['id'], (string) $parent['id']);
        $this->enrol((string) $child['id'], 'section-a');
        $this->enrol((string) $other['id'], 'section-a');

        [$put, $body] = $this->api('POST', '/public-nursery/portal/allergies', 'parent-user', 'tenant-dev', 'est-dev', ['learner_id' => $child['id'], 'allergies' => 'arachides']);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame('arachides', $body['data']['allergies']);

        [, $teacher] = $this->api('GET', '/public-nursery/portal/allergies/section?section_id=section-a', 'teacher', 'tenant-dev', 'est-dev');
        self::assertSame([
            ['learner_id' => $child['id'], 'learner_name' => 'Lina Meka', 'allergies' => 'arachides'],
            ['learner_id' => $other['id'], 'learner_name' => 'Noa Meka', 'allergies' => null],
        ], $teacher['data']);

        self::assertSame(200, $this->api('GET', '/public-nursery/portal/allergies/section?section_id=section-a', 'canteen', 'tenant-dev', 'est-dev')[0]->getStatusCode());
        self::assertSame(403, $this->code('GET', '/public-nursery/portal/allergies/section?section_id=section-a', 'outsider', 'tenant-dev', 'est-dev'));
        self::assertSame(403, $this->code('GET', '/public-nursery/portal/allergies/section?section_id=section-a', 'parent-user', 'tenant-dev', 'est-dev'));
    }

    /** @return array<string, mixed> */
    private function person(string $first, string $last): array
    {
        return $this->kernel->container()->get(PersonService::class)->create('tenant-dev', ['first_name' => $first, 'last_name' => $last, 'birth_date' => '2021-01-01']);
    }

    private function linkUser(string $userId, string $personId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, 'tenant-dev', $personId, 'fr', 'active', $now, $now]);
    }

    private function linkParent(string $learnerId, string $parentId): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO person_relations (id, tenant_id, person_id, related_person_id, kind, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([(string) new Ulid(), 'tenant-dev', $learnerId, $parentId, 'financial_guarantor', 'admin', '2026-10-08T09:00:00+01:00']);
    }

    private function enrol(string $personId, string $structureId): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)',
        )->execute([(string) new Ulid(), 'tenant-dev', $personId, 'est-dev', $structureId, 'year-2026', 'active', $now, $now]);
    }
}
