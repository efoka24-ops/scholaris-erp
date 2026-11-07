<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Domain\HandoverDecision;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Organisation\OrganisationTestCase;

final class HandoverTest extends OrganisationTestCase
{
    private object $notifier;

    private function nowIso(): string
    {
        return '2026-10-08T09:00:00+01:00';
    }

    protected function setUp(): void
    {
        $this->notifier = new class () implements SafetyMessenger {
            /** @var list<array{tenant: string, person: string, template: string, variables: array<string, scalar>}> */
            public array $sent = [];

            public function send(string $tenantId, string $personId, string $templateKey, array $variables = []): \Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult
            {
                $this->sent[] = ['tenant' => $tenantId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables];

                return new \Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult(\Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult::SENT, 'spy');
            }
        };
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
            SafetyMessenger::class => $this->notifier,
        ]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'Maternelle publique pilote', 'nursery', 'teacher', 'director', 'parent-a-user', 'parent-b-user');
        $this->grantAll('teacher', 'public_nursery.pickup.handover');
        $this->grantAll('director', 'settings.manage');
    }

    public function testLaRemiseNonAutoriseeRefuseEtAlerteParentsEtDirection(): void
    {
        $parent = $this->person('Marie', 'Meka');
        $child = $this->person('Lina', 'Meka');
        $director = $this->person('Dora', 'Ngono');
        $this->linkUser('parent-a-user', (string) $parent['id']);
        $this->linkUser('director', (string) $director['id']);
        $this->linkParent((string) $child['id'], (string) $parent['id']);
        $this->enrol((string) $child['id']);
        $this->assignDirector((string) $director['id']);

        [$response, $json] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'pickup_id' => (string) new Ulid(),
            'school_day' => '2026-10-08',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(HandoverDecision::REFUSED, $json['data']['status']);
        self::assertSame('unauthorized', $json['data']['reason']);
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_gate_events WHERE event_type = 'refused' AND learner_id = '" . $child['id'] . "'")->fetchColumn());
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM pn_gate_events WHERE event_type = 'handed_over' AND learner_id = '" . $child['id'] . "'")->fetchColumn());

        $sent = $this->notifier->sent;
        self::assertCount(2, $sent);
        self::assertSame(['pn.not07.parent', 'pn.not07.director'], array_column($sent, 'template'));
        self::assertContains((string) $parent['id'], array_column($sent, 'person'));
        self::assertContains((string) $director['id'], array_column($sent, 'person'));
    }

    public function testUneRestrictionCibleeBloqueLaRemiseEtNeNotifieQueLeParentNonVise(): void
    {
        $targetParent = $this->person('Marie', 'Meka');
        $otherParent = $this->person('Paul', 'Meka');
        $child = $this->person('Lina', 'Meka');
        $director = $this->person('Dora', 'Ngono');
        $this->linkUser('parent-a-user', (string) $targetParent['id']);
        $this->linkUser('parent-b-user', (string) $otherParent['id']);
        $this->linkUser('director', (string) $director['id']);
        $this->linkParent((string) $child['id'], (string) $targetParent['id']);
        $this->linkParent((string) $child['id'], (string) $otherParent['id']);
        $this->enrol((string) $child['id']);
        $this->assignDirector((string) $director['id']);

        $pickup = $this->api('POST', '/public-nursery/portal/pickups', 'parent-a-user', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'name' => 'Tante Rose',
            'relationship' => 'tante',
            'grant_type' => 'permanent',
        ])[1]['data'];
        $this->api('POST', '/public-nursery/pickup/restrictions', 'director', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'restricted_name' => 'Marie Meka',
            'restricted_person_id' => $targetParent['id'],
            'restricted_pickup_id' => $pickup['id'],
            'nature' => 'court_order',
            'decision_reference' => 'JUG-2026-004',
            'valid_from' => '2026-10-08',
        ]);

        [$response, $json] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'pickup_id' => $pickup['id'],
            'pickup_person_id' => $targetParent['id'],
            'school_day' => '2026-10-08',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(HandoverDecision::REFUSED, $json['data']['status']);
        self::assertSame('restricted', $json['data']['reason']);

        $sent = $this->notifier->sent;
        self::assertCount(2, $sent);
        self::assertSame('pn.not07.parent', $sent[0]['template']);
        self::assertSame((string) $otherParent['id'], $sent[0]['person']);
        self::assertSame('pn.not07.director', $sent[1]['template']);
        self::assertSame((string) $director['id'], $sent[1]['person']);
    }

    public function testUneAutorisationPerimeeResteRefusee(): void
    {
        $parent = $this->person('Marie', 'Meka');
        $child = $this->person('Lina', 'Meka');
        $this->linkUser('parent-a-user', (string) $parent['id']);
        $this->linkParent((string) $child['id'], (string) $parent['id']);
        $this->enrol((string) $child['id']);

        $pickup = $this->api('POST', '/public-nursery/portal/pickups', 'parent-a-user', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'name' => 'Tante Rose',
            'relationship' => 'tante',
            'grant_type' => 'day',
            'valid_on' => '2026-10-07',
        ])[1]['data'];

        [$response, $json] = $this->api('POST', '/public-nursery/pickup/handovers', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => $child['id'],
            'pickup_id' => $pickup['id'],
            'school_day' => '2026-10-08',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(HandoverDecision::REFUSED, $json['data']['status']);
        self::assertSame('stale_authorization', $json['data']['reason']);
        self::assertSame('refused', (string) $this->connections()->pdo()->query("SELECT event_type FROM pn_gate_events WHERE learner_id = '" . $child['id'] . "' ORDER BY recorded_at DESC, id DESC LIMIT 1")->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function person(string $first, string $last): array
    {
        return $this->kernel->container()->get(PersonService::class)->create('tenant-dev', ['first_name' => $first, 'last_name' => $last, 'birth_date' => '2021-01-01']);
    }

    private function linkUser(string $userId, string $personId): void
    {
        $now = $this->nowIso();
        $this->connections()->pdo()->prepare('INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'tenant-dev', $personId, 'fr', 'active', $now, $now]);
    }

    private function linkParent(string $learnerId, string $parentId): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO person_relations (id, tenant_id, person_id, related_person_id, kind, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), 'tenant-dev', $learnerId, $parentId, 'financial_guarantor', 'admin', $this->nowIso()]);
    }

    private function enrol(string $personId): void
    {
        $now = $this->nowIso();
        $this->connections()->pdo()->prepare('INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, structure_id, academic_year_id, status, completeness, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?)')
            ->execute([(string) new Ulid(), 'tenant-dev', $personId, 'est-dev', 'section-a', 'year-2026', 'active', $now, $now]);
    }

    private function assignDirector(string $personId): void
    {
        $now = $this->nowIso();
        $this->connections()->pdo()->prepare('INSERT INTO role_assignments (id, tenant_id, person_id, role, scope_type, scope_id, establishment_id, starts_at, ends_at, delegated_by, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), 'tenant-dev', $personId, 'directeur', 'establishment', 'est-dev', 'est-dev', $now, null, null, 'admin', $now]);
    }
}
