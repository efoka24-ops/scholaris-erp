<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryWriteGate;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

final class GateTest extends TestCase
{
    public function testLeGardeCommandeLesEcrituresSelonLEtatDeLApplication(): void
    {
        $access = new class () implements ApplicationAccess {
            public function __construct(private ?string $state = self::ACTIVE)
            {
            }

            public function state(string $tenantId, string $appKey): ?string
            {
                return $this->state;
            }
            public function allowsWrites(string $tenantId, string $appKey): bool
            {
                return in_array($this->state, [self::TRIAL, self::ACTIVE, self::PAST_DUE], true);
            }
            public function allowsReads(string $tenantId, string $appKey): bool
            {
                return $this->state !== self::CLOSED;
            }
        };
        $gate = new NurseryWriteGate($access);

        self::assertTrue($gate->allowsWrites('t1'));
        self::assertTrue($gate->allowsReads('t1'));
        $gate->assertWritable('t1');
        $gate->assertReadable('t1');
    }

    public function testLeGardeRefuseLesEcrituresQuandSuspenduOuFerme(): void
    {
        $gate = new NurseryWriteGate(new class () implements ApplicationAccess {
            public function state(string $tenantId, string $appKey): ?string
            {
                return self::SUSPENDED;
            }
            public function allowsWrites(string $tenantId, string $appKey): bool
            {
                return false;
            }
            public function allowsReads(string $tenantId, string $appKey): bool
            {
                return true;
            }
        });
        try {
            $gate->assertWritable('t1');
            self::fail('423 attendu.');
        } catch (HttpException $e) {
            self::assertSame(423, $e->status);
        }

        $closed = new NurseryWriteGate(new class () implements ApplicationAccess {
            public function state(string $tenantId, string $appKey): ?string
            {
                return self::CLOSED;
            }
            public function allowsWrites(string $tenantId, string $appKey): bool
            {
                return false;
            }
            public function allowsReads(string $tenantId, string $appKey): bool
            {
                return false;
            }
        });
        try {
            $closed->assertWritable('t1');
            self::fail('403 attendu.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $closed->assertReadable('t1');
            self::fail('404 attendu.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testLaGardeDetaillantLEtablissementEtLaSectionRefuseLePrimaireEtLEtranger(): void
    {
        $establishments = new class () implements EstablishmentDirectory {
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
                return match ($establishmentId) {
                    'n1' => new EstablishmentInfo('n1', $tenantId, 'Ecole maternelle', 'nursery', '#112233'),
                    'p1' => new EstablishmentInfo('p1', $tenantId, 'Ecole primaire', 'primary', '#445566'),
                    default => null,
                };
            }
        };
        $guard = new EstablishmentGuard(new NurseryWriteGate(new class () implements ApplicationAccess {
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
        }), $establishments);
        $principal = new Principal('u1', 't1');

        $guard->assertNursery($principal, 't1', 'n1');
        $this->expectException(HttpException::class);
        $guard->assertNursery($principal, 't1', 'p1');
    }

    public function testLaSectionDoitAppartenirAuPerimetre(): void
    {
        $guard = new EstablishmentGuard(new NurseryWriteGate(new class () implements ApplicationAccess {
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
        }), new class () implements EstablishmentDirectory {
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
                return new EstablishmentInfo($establishmentId, $tenantId, 'Ecole', 'nursery', '#112233');
            }
        });

        $scope = new SectionScope('section-a');
        $guard->assertSection('u1', $scope, 'section-a');

        try {
            $guard->assertSection('u1', $scope, 'section-b');
            self::fail('404 attendu.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }
}
