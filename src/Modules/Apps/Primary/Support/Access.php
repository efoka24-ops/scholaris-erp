<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Policies\ScopeResolver;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Contrôle d'accès de l'application primaire : permission `primary.*` (refus par défaut) PUIS périmètre classe (RG-PRI-3).
 * Plusieurs permissions possibles pour une même opération : leurs périmètres s'additionnent. Hors périmètre : 404 en lecture
 * (l'existence est cachée), 403 journalisé en écriture.
 */
final class Access
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly ScopedByClass $scoped,
        private readonly AuditLog $audit,
    ) {
    }

    public static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }

    public static function establishmentId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');
        if (!is_string($id) || $id === '') {
            throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
        }

        return $id;
    }

    /** @param list<string> $permissions */
    public function perimeter(Principal $principal, string $establishmentId, array $permissions): Perimeter
    {
        $union = new Perimeter($principal->tenantId, $establishmentId, false);
        foreach ($permissions as $permission) {
            $union = $union->union($this->resolver->resolve($principal, $permission, $establishmentId));
        }

        return $union;
    }

    /**
     * Périmètre non vide exigé (sinon 403) : point d'entrée des lectures et des écritures.
     *
     * @param list<string> $permissions
     */
    public function require(Principal $principal, string $establishmentId, array $permissions): Perimeter
    {
        $perimeter = $this->perimeter($principal, $establishmentId, $permissions);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
        }

        return $perimeter;
    }

    /** Lecture d'une ligne rattachée à une classe : 404 hors périmètre. */
    public function assertReadableClass(Perimeter $perimeter, ?string $classId): void
    {
        $this->scoped->assertReadable($perimeter, ['class_id' => $classId]);
    }

    /** Écriture sur une classe : 403 journalisé hors périmètre. */
    public function assertWritableClass(Principal $principal, Perimeter $perimeter, ?string $classId, string $objectType, string $objectId): void
    {
        if ($perimeter->coversClass($classId)) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'primary.scope.denied', $objectType, $objectId, null, ['class_id' => $classId]);

        throw HttpException::forbidden('Cette classe est hors de votre périmètre.');
    }

    /** L'opération exige tout l'établissement (paramétrage du référentiel, par exemple). */
    public function assertWhole(Principal $principal, Perimeter $perimeter, string $objectType): void
    {
        if ($perimeter->wholeEstablishment) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'primary.scope.denied', $objectType, $perimeter->establishmentId, null, ['scope' => 'establishment']);

        throw HttpException::forbidden('Cette opération exige une affectation sur tout l\'établissement.');
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(Principal $principal, string $action, string $objectType, string $objectId, ?array $before = null, ?array $after = null): void
    {
        $this->audit->append($principal->tenantId, $principal->userId, $action, $objectType, $objectId, $before, $after);
    }
}
