<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Câblage de production : avec les vraies liaisons des modules (aucune doublure), chaque contrôleur
 * déclaré par une route doit pouvoir être construit, et chaque contrat public doit avoir son implémentation.
 */
final class ContainerWiringTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
    }

    public function testChaqueControleurDeRouteEstConstruisible(): void
    {
        $failures = [];
        $seen = [];
        foreach ($this->kernel->routes()->all() as $route) {
            $class = $route['handler'][0];
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            try {
                $this->kernel->container()->get($class);
            } catch (\Throwable $e) {
                $failures[] = $class . ' : ' . $e->getMessage();
            }
        }

        self::assertGreaterThan(20, count($seen), 'Les routes des modules doivent être chargées.');
        self::assertSame([], $failures, 'Contrôleurs non constructibles (liaison manquante ?).');
    }

    public function testChaqueContratPublicAUneImplementation(): void
    {
        $contracts = [
            Authenticator::class, EstablishmentAccess::class, PermissionChecker::class,
            EstablishmentDirectory::class, PersonDirectory::class, EnrollmentDirectory::class, ConsentChecker::class,
            Messenger::class, DebtorDirectory::class, DocumentIssuer::class, AccountProvisioner::class,
        ];

        $missing = [];
        foreach ($contracts as $contract) {
            try {
                $this->kernel->container()->get($contract);
            } catch (\Throwable $e) {
                $missing[] = $contract . ' : ' . $e->getMessage();
            }
        }

        self::assertSame([], $missing);
    }

    public function testLesRefusParDefautDuNoyauSontRemplacesParLesModules(): void
    {
        $container = $this->kernel->container();

        self::assertNotInstanceOf(\Scholaris\Core\Security\NullAuthenticator::class, $container->get(Authenticator::class));
        self::assertNotInstanceOf(\Scholaris\Core\Security\DenyAllEstablishmentAccess::class, $container->get(EstablishmentAccess::class));
        self::assertNotInstanceOf(\Scholaris\Core\Security\DenyAllPermissions::class, $container->get(PermissionChecker::class));
    }
}
