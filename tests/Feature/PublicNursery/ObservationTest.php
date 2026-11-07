<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryApplicationAccess;
use Tests\Feature\PublicNursery\Support\NurseryScopes;
use Tests\Support\Organisation\OrganisationTestCase;

final class ObservationTest extends OrganisationTestCase
{
    public function testUneObservationValideEstIdempotenteSurClientUuid(): void
    {
        $this->bootOrganisation([ApplicationAccess::class => new NurseryApplicationAccess(), PermissionScopes::class => NurseryScopes::binding()]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.dev.observe');

        $payload = [
            'learner_id' => 'learner-1',
            'structure_id' => 'section-a',
            'frame_id' => 'frame-1',
            'domain_id' => 'domain-1',
            'item_id' => 'item-1',
            'level_code' => 'in_progress',
            'remark' => 'Observation précise',
            'observed_on' => '2026-10-16',
            'observer_id' => 'teacher',
            'client_uuid' => 'uuid-obs-1',
        ];

        [$firstResponse] = $this->api('POST', '/public-nursery/sync/observations', 'teacher', 'tenant-dev', 'est-dev', $payload);
        self::assertSame(201, $firstResponse->getStatusCode());
        [$secondResponse] = $this->api('POST', '/public-nursery/sync/observations', 'teacher', 'tenant-dev', 'est-dev', $payload);
        self::assertSame(201, $secondResponse->getStatusCode());
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM pn_observations')->fetchColumn());
    }

    public function testUnNiveauInvalideEstRefuse(): void
    {
        $this->bootOrganisation([ApplicationAccess::class => new NurseryApplicationAccess(), PermissionScopes::class => NurseryScopes::binding()]);
        $this->seedEstablishment('tenant-dev', 'est-dev', 'École pilote', 'nursery', 'teacher');
        $this->grantAll('teacher', 'public_nursery.dev.observe');

        [$response] = $this->api('POST', '/public-nursery/sync/observations', 'teacher', 'tenant-dev', 'est-dev', [
            'learner_id' => 'learner-1',
            'structure_id' => 'section-a',
            'frame_id' => 'frame-1',
            'domain_id' => 'domain-1',
            'item_id' => 'item-1',
            'level_code' => 'excellent',
            'observed_on' => '2026-10-16',
            'observer_id' => 'teacher',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }
}
