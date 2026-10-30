<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/**
 * Contexte commun des contrôleurs ajoutés en N-002 (contribution, effectifs, registre, carnet) : principal, établissement de type
 * maternelle (404 sinon), permission parmi une liste (403 sinon), portée de sections, corps JSON objet. La porte du droit d'usage est
 * déjà passée par GatedAction.
 */
final class RequestContext
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly SectionScopeResolver $scopes,
    ) {
    }

    /**
     * @param list<string> $anyOf
     * @return array{0: Principal, 1: string, 2: SectionScope}
     */
    public function require(ServerRequestInterface $request, array $anyOf): array
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = $request->getAttribute('establishment_id');
        if (!is_string($establishmentId) || $establishmentId === '') {
            throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
        }
        $this->guard->assertNursery($principal, $principal->tenantId, $establishmentId);
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return [$principal, $establishmentId, $this->scopes->resolve($principal, $anyOf, $establishmentId)];
            }
        }

        throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $anyOf) . '.');
    }

    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return [];
        }
        $body = json_decode($raw, true);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $body;
    }

    /**
     * Champs inconnus refusés (422) : la liste blanche est celle de la route.
     *
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     */
    public static function onlyKnown(array $body, array $allowed): void
    {
        $errors = [];
        foreach (array_keys($body) as $field) {
            if (!in_array((string) $field, $allowed, true)) {
                $errors[(string) $field] = ['Champ non reconnu.'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }

    public static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
