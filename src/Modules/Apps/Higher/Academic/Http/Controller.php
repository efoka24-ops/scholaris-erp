<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;

/**
 * Base des contrôleurs d'Academic et de Curriculum : contexte (personne, établissement), lecture d'une ligne dans le périmètre
 * (404 hors périmètre), listes paginées filtrées par périmètre. Les écritures passent par les services, qui contrôlent
 * permission et périmètre ; la porte du droit d'usage (WriteGate) est déjà passée (HigherRoutes).
 */
abstract class Controller
{
    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly Access $access,
        protected readonly Store $store,
        protected readonly Listing $listing,
    ) {
    }

    /** @return array{0: Principal, 1: string} */
    protected function context(ServerRequestInterface $request): array
    {
        return [Access::principal($request), Access::establishmentId($request)];
    }

    protected static function id(ServerRequestInterface $request, string $name = 'id'): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Ligne lisible par la personne (permission + périmètre), sinon 404.
     *
     * @param list<string> $permissions
     * @param \Closure(array<string, mixed>): array<string, ?string> $scope
     * @return array<string, mixed>
     */
    protected function readable(ServerRequestInterface $request, string $table, string $id, array $permissions, \Closure $scope, string $missing = 'Ressource introuvable.'): array
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->store->findOrFail($table, $principal->tenantId, $establishmentId, $id, $missing);
        $this->access->assertReadable($perimeter, $scope($row));

        return $row;
    }

    /**
     * @param list<string> $permissions
     * @param array<string, string> $scopeColumns
     * @param list<string> $filters
     * @param list<string> $sorts
     * @param \Closure(array<string, mixed>): array<string, mixed> $map
     */
    protected function page(ServerRequestInterface $request, string $table, array $permissions, array $scopeColumns, array $filters, array $sorts, string $defaultSort, \Closure $map): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, $permissions, Access::selectedSchool($request));

        return $this->responder->json($this->listing->page($request, $table, $principal->tenantId, $establishmentId, $perimeter, $scopeColumns, $filters, $sorts, $defaultSort, $map));
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => $data], $status);
    }
}
