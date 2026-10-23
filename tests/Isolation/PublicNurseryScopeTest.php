<?php

declare(strict_types=1);

namespace Tests\Isolation;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryWriteGate;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** Cloisonnement PublicNursery : un tenant ou une section étrangère ne doit jamais passer la garde. */
final class PublicNurseryScopeTest extends TestCase
{
    public function testLaSectionEtLEtablissementRestentBornesAuPerimetreDeclare(): void
    {
        $gate = new NurseryWriteGate(new class () implements ApplicationAccess {
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
        });
        $guard = new EstablishmentGuard($gate, new class () implements EstablishmentDirectory {
            public function forTenant(string $tenantId): array
            {
                return [];
            }
            public function findMany(string $tenantId, array $ids): array
            {
                return [];
            }
            public function find(string $tenantId, string $establishmentId): ?EstablishmentInfo
            {
                return match ($tenantId . ':' . $establishmentId) {
                    'tenant-a:nursery-a' => new EstablishmentInfo('nursery-a', 'tenant-a', 'École maternelle A', 'nursery', '#112233'),
                    'tenant-b:nursery-b' => new EstablishmentInfo('nursery-b', 'tenant-b', 'École maternelle B', 'nursery', '#445566'),
                    default => null,
                };
            }
        });
        $principal = new Principal('u1', 'tenant-a');

        $guard->assertNursery($principal, 'tenant-a', 'nursery-a');
        $scope = new SectionScope('section-a');
        $guard->assertSection($principal, $scope, 'section-a');

        try {
            $guard->assertNursery($principal, 'tenant-a', 'nursery-b');
            self::fail('404 attendu pour un établissement d’un autre tenant.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }

        try {
            $guard->assertSection($principal, $scope, 'section-b');
            self::fail('404 attendu pour une section hors périmètre.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }
}
