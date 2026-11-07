<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Tests\Support\Organisation\OrganisationTestCase;

final class ObservationRemindersCommandTest extends OrganisationTestCase
{
    public function testLaCommandeRepereLesObservationsTropAnciennes(): void
    {
        $this->bootOrganisation();
        $pdo = $this->connections()->pdo();
        $pdo->exec("INSERT INTO pn_observations (id, tenant_id, establishment_id, learner_id, structure_id, frame_id, domain_id, item_id, level_code, remark, observed_on, observer_id, client_uuid, created_at, updated_at) VALUES ('obs-1', 'tenant-dev', 'est-dev', 'learner-1', 'section-a', 'frame-1', 'domain-1', 'item-1', 'in_progress', NULL, '2026-10-01', 'teacher', 'uuid-1', '2026-10-01T08:00:00+01:00', '2026-10-01T08:00:00+01:00')");
        $pdo->exec("INSERT INTO pn_observations (id, tenant_id, establishment_id, learner_id, structure_id, frame_id, domain_id, item_id, level_code, remark, observed_on, observer_id, client_uuid, created_at, updated_at) VALUES ('obs-2', 'tenant-dev', 'est-dev', 'learner-2', 'section-a', 'frame-1', 'domain-1', 'item-1', 'in_progress', NULL, '2026-10-15', 'teacher', 'uuid-2', '2026-10-15T08:00:00+01:00', '2026-10-15T08:00:00+01:00')");

        ob_start();
        $code = $this->kernel->container()->get('console.command.' . \Scholaris\Modules\Apps\PublicNursery\Development\Console\ObservationRemindersCommand::NAME)->run(['2026-10-16', '--days=7']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('1 élève(s)', $output);
        self::assertStringContainsString('learner-1', $output);
        self::assertStringNotContainsString('learner-2', $output);
    }
}
