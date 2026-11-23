<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Contracts\TerritorialAnnouncer;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;
use Tests\Support\Messaging\MessagingTestCase;

/** PP12 : une annonce territoriale n'atteint que les établissements du territoire. */
final class TerritorialAnnouncementTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging([
            TerritoryDirectory::class => new class () implements TerritoryDirectory {
                public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo { return null; }
                public function lineage(string $tenantId, string $territoryId, string $at): array { return []; }
                public function descendantsOf(string $tenantId, string $territoryId, string $at): array { return []; }
                public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo { return null; }
                public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
                {
                    return $tenantId === 't1' ? ['arr-a' => ['e1'], 'arr-b' => ['e2']][$territoryId] ?? [] : [];
                }
            },
        ]);
    }

    public function testLAnnonceDeLInspectionNAtteintQueLesEtablissementsDuTerritoire(): void
    {
        $reached = $this->kernel->container()->get(TerritorialAnnouncer::class)->announce('t1', 'arr-a', 'Visite d\'inspection', 'Visite le 20 octobre.', 'inspector');

        self::assertSame(['e1'], $reached);
        self::assertSame(['Visite d\'inspection'], array_column($this->api('GET', '/announcements', 'alice', 't1', 'e1')[1]['data'], 'title'));
        self::assertSame([], $this->api('GET', '/announcements', 'alice', 't1', 'e2')[1]['data']);
        self::assertSame([], $this->api('GET', '/announcements', 'bob', 't2', 'e3')[1]['data']);
    }

    public function testTerritoireInconnuOuAutreClientNePublieRien(): void
    {
        $announcer = $this->kernel->container()->get(TerritorialAnnouncer::class);

        self::assertSame([], $announcer->announce('t1', 'inconnu', 'Titre', 'Texte', 'inspector'));
        self::assertSame([], $announcer->announce('t2', 'arr-a', 'Titre', 'Texte', 'inspector'));
        self::assertSame([], $this->api('GET', '/announcements', 'alice', 't1', 'e1')[1]['data']);
    }
}
