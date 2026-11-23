<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery\Support;

use function DI\factory;

use Psr\Container\ContainerInterface;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/**
 * Doublure de PermissionScopes pour les tests qui utilisent StubPermissions : une permission détenue couvre tout l'établissement,
 * sauf pour un utilisateur dont le test a limité le périmètre à des classes (`restrictToClasses`).
 */
final class NurseryScopes implements PermissionScopes
{
    /** @var array<string, list<string>> utilisateur => classes */
    private array $classes = [];

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    /** Définition PHP-DI à passer à bootOrganisation : construite avec le PermissionChecker du test. */
    public static function binding(): object
    {
        return factory(static fn (ContainerInterface $container): self => new self($container->get(PermissionChecker::class)));
    }

    public function restrictToClasses(string $userId, string ...$classIds): void
    {
        $this->classes[$userId] = array_values($classIds);
    }

    public function scopesFor(Principal $principal, string $permission, string $establishmentId): array
    {
        if (!$this->permissions->can($principal, $permission, $establishmentId)) {
            return [];
        }
        if (isset($this->classes[$principal->userId])) {
            return array_map(static fn (string $id): Scope => new Scope(Scope::CLASS_GROUP, $id, 'teacher'), $this->classes[$principal->userId]);
        }

        return [new Scope(Scope::ESTABLISHMENT, $establishmentId, 'director')];
    }
}
