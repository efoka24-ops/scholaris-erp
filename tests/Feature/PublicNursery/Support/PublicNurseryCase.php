<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery\Support;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

/**
 * Décor des tests N-002 de la maternelle publique : vrai socle (SQLite migré), école « est-dev » de type nursery du client
 * « tenant-dev », école d'un autre client « est-other », horloge figée, messagerie de sécurité espionne. Données fictives.
 */
abstract class PublicNurseryCase extends OrganisationTestCase
{
    public const T = 'tenant-dev';
    public const E = 'est-dev';
    public const NOW = '2026-10-08T09:00:00+01:00';

    protected NurseryApplicationAccess $apps;
    /** @var object{sent: list<array{person: string, template: string, variables: array<string, scalar>}>} */
    protected object $safety;
    protected NurseryScopes $scopes;

    protected function bootPublicNursery(string $now = self::NOW): void
    {
        $this->apps = new NurseryApplicationAccess();
        $this->safety = new class () implements SafetyMessenger {
            /** @var list<array{person: string, template: string, variables: array<string, scalar>}> */
            public array $sent = [];

            public function send(string $tenantId, string $personId, string $templateKey, array $variables = []): DeliveryResult
            {
                $this->sent[] = ['person' => $personId, 'template' => $templateKey, 'variables' => $variables];

                return new DeliveryResult(DeliveryResult::SENT, 'spy');
            }
        };
        $this->bootOrganisation([
            ApplicationAccess::class => $this->apps,
            SafetyMessenger::class => $this->safety,
            PermissionScopes::class => NurseryScopes::binding(),
            PublicNurseryClock::class => new PublicNurseryClock($now),
        ]);
        $this->scopes = $this->kernel->container()->get(PermissionScopes::class);
        $this->seedEstablishment(self::T, self::E, 'École maternelle publique fictive', 'nursery', 'director', 'teacher', 'parent-user', 'outsider');
        $this->seedEstablishment('tenant-other', 'est-other', 'Autre école fictive', 'nursery', 'other-director');
    }

    /** @return array<string, mixed> */
    protected function person(string $first, string $last, string $tenant = self::T): array
    {
        return $this->kernel->container()->get(PersonService::class)->create($tenant, ['first_name' => $first, 'last_name' => $last, 'birth_date' => '2021-01-01']);
    }

    protected function enrol(string $personId, string $section = 'section-a', string $establishment = self::E, string $tenant = self::T): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)')
            ->execute([(string) new Ulid(), $tenant, $personId, $establishment, $section, 'year-2026', 'active', self::NOW, self::NOW]);
    }

    protected function linkParent(string $learnerId, string $parentId): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO person_relations (id, tenant_id, person_id, related_person_id, kind, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), self::T, $learnerId, $parentId, 'financial_guarantor', 'admin', self::NOW]);
    }

    protected function assignDirector(string $personId): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO role_assignments (id, tenant_id, person_id, role, scope_type, scope_id, establishment_id, starts_at, ends_at, delegated_by, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), self::T, $personId, 'directeur', 'establishment', self::E, self::E, self::NOW, null, null, 'admin', self::NOW]);
    }

    protected function setting(string $key, mixed $value, string $establishment = self::E, string $tenant = self::T): void
    {
        $this->connections()->pdo()->prepare("INSERT INTO settings (id, scope_type, tenant_id, establishment_id, setting_key, value_json, updated_by, created_at, updated_at) VALUES (?, 'establishment', ?, ?, ?, ?, 'test', ?, ?)")
            ->execute([(string) new Ulid(), $tenant, $establishment, $key, json_encode($value, JSON_THROW_ON_ERROR), self::NOW, self::NOW]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function ok(string $method, string $path, string $user, ?array $body = null, string $tenant = self::T, string $establishment = self::E): array
    {
        [$response, $json] = $this->api($method, $path, $user, $tenant, $establishment, $body);
        self::assertContains($response->getStatusCode(), [200, 201], $method . ' ' . $path . ' => ' . $response->getStatusCode() . ' ' . json_encode($json));

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }
}
