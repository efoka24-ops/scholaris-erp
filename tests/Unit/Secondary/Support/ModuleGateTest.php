<?php

declare(strict_types=1);

namespace Tests\Unit\Secondary\Support;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Http\ProfileController;
use Scholaris\Modules\Apps\Secondary\Support\GatedAction;
use Scholaris\Modules\Apps\Secondary\Support\ModuleGate;
use Scholaris\Modules\Apps\Secondary\Support\WriteGate;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Secondary\FakeApplicationAccess;

/** T813, T814, D10 : module actif = route servie ; jamais activé = 409 `module_not_enabled` ; coupé après usage = lecture seule. */
final class ModuleGateTest extends TestCase
{
    /** @param list<string> $modules @param list<string> $readOnly */
    private static function profiles(array $modules, array $readOnly = []): EstablishmentProfiles
    {
        return new class ($modules, $readOnly) implements EstablishmentProfiles {
            /** @param list<string> $modules @param list<string> $readOnly */
            public function __construct(private readonly array $modules, private readonly array $readOnly)
            {
            }

            public function forEstablishment(string $tenantId, string $establishmentId): EstablishmentProfile
            {
                return new EstablishmentProfile($tenantId, $establishmentId, 'fictif', 1, true, 'public', ['public'], ['first', 'second'], ['general'], ['fr'], $this->modules, $this->readOnly, [], 'proviseur', 'to_confirm');
            }
        };
    }

    private static function problem(\Closure $action): ?HttpException
    {
        try {
            $action();
        } catch (HttpException $exception) {
            return $exception;
        }

        return null;
    }

    public function testModuleActifInactifEtLectureSeule(): void
    {
        $gate = new ModuleGate(self::profiles([Modules::WORKSHOPS], [Modules::INTERNSHIPS]));
        $gate->assertAvailable('t', 'e', Modules::WORKSHOPS, true);
        $gate->assertAvailable('t', 'e', Modules::INTERNSHIPS, false);
        self::assertTrue($gate->isActive('t', 'e', Modules::WORKSHOPS));

        $refused = self::problem(static fn () => $gate->assertAvailable('t', 'e', Modules::INTERNSHIPS, true));
        self::assertNotNull($refused);
        self::assertSame(409, $refused->status);
        self::assertSame(ModuleGate::CODE, $refused->extensions['code'] ?? null);

        foreach ([false, true] as $write) {
            $never = self::problem(static fn () => $gate->assertAvailable('t', 'e', Modules::LIBRARY, $write));
            self::assertNotNull($never);
            self::assertSame(409, $never->status);
            self::assertSame(ModuleGate::TYPE, $never->type);
            self::assertSame(['code' => 'module_not_enabled', 'module' => 'library'], $never->extensions);
        }
    }

    public function testUnCodeDeModuleInconnuEstUneErreurDeProgrammation(): void
    {
        $this->expectException(\LogicException::class);
        (new ModuleGate(self::profiles([])))->assertAvailable('t', 'e', 'cantine', false);
    }

    public function testGatedActionAppliqueLaGardeDeModuleApresLeDroitDUsage(): void
    {
        $access = new FakeApplicationAccess();
        $access->set('t', ApplicationAccess::ACTIVE);
        $container = new class () implements ContainerInterface {
            public int $calls = 0;

            public function get(string $id): object
            {
                $owner = $this;

                return new class ($owner) {
                    public function __construct(private readonly object $owner)
                    {
                    }

                    public function show(): ResponseInterface
                    {
                        ++$this->owner->calls;

                        return new Response(200);
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $request = (new ServerRequest('GET', '/api/v1/secondary/x'))->withAttribute('principal', new Principal('u', 't'))->withAttribute('establishment_id', 'e');

        $inactive = new GatedAction($container, new WriteGate($access), new ModuleGate(self::profiles([])));
        $name = GatedAction::encode('read', [ProfileController::class, 'show'], Modules::WORKSHOPS);
        self::assertSame(409, self::problem(static fn () => $inactive->{$name}($request))?->status);
        self::assertSame(0, $container->calls, 'Le contrôleur n\'est jamais appelé pour un module inactif.');

        $active = new GatedAction($container, new WriteGate($access), new ModuleGate(self::profiles([Modules::WORKSHOPS])));
        self::assertSame(200, $active->{$name}($request)->getStatusCode());
        self::assertSame(1, $container->calls);

        $access->set('t', ApplicationAccess::SUSPENDED);
        $write = GatedAction::encode('write', [ProfileController::class, 'show'], Modules::WORKSHOPS);
        self::assertSame(423, self::problem(static fn () => $active->{$write}($request))?->status, 'Le droit d\'usage passe avant le module.');
    }

    public function testDecodeRefuseUnModuleHorsListeFermee(): void
    {
        self::assertSame(Modules::WORKSHOPS, GatedAction::decode(GatedAction::encode('read', [ProfileController::class, 'show'], Modules::WORKSHOPS))[3]);
        self::assertNull(GatedAction::decode(GatedAction::encode('read', [ProfileController::class, 'show']))[3]);
        $this->expectException(\LogicException::class);
        GatedAction::decode('read|' . ProfileController::class . '|show|cantine');
    }
}
