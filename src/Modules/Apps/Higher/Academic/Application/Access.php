<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Contrôle d'accès d'Academic et de Curriculum : permission `higher.*` (refus par défaut) PUIS périmètre école, département,
 * filière ou groupe (RG-ORG-2) par Support\ScopedBySchool. Plusieurs permissions possibles pour une même opération : leurs
 * périmètres s'additionnent. Hors périmètre : 404 en lecture, 403 journalisé en écriture.
 */
final class Access
{
    public function __construct(
        private readonly ScopedBySchool $scoped,
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

    /** Sélecteur « École » (RG-ORG-3) : paramètre `school_id` (identifiant ou `all`). */
    public static function selectedSchool(ServerRequestInterface $request): ?string
    {
        $school = $request->getQueryParams()['school_id'] ?? null;

        return is_string($school) && $school !== '' ? $school : null;
    }

    /** @param list<string> $permissions */
    public function perimeter(Principal $principal, string $establishmentId, array $permissions, ?string $school = null): Perimeter
    {
        $whole = false;
        $units = $structures = $schools = $roles = [];
        foreach ($permissions as $permission) {
            $p = $this->scoped->perimeter($principal, $permission, $establishmentId);
            $whole = $whole || $p->wholeEstablishment;
            $units = array_merge($units, $p->orgUnitIds);
            $structures = array_merge($structures, $p->structureIds);
            $schools = array_merge($schools, $p->schoolIds);
            $roles = array_merge($roles, $p->roles);
        }
        $union = new Perimeter(
            $principal->tenantId,
            $establishmentId,
            $whole,
            array_values(array_unique($units)),
            array_values(array_unique($structures)),
            array_values(array_unique($schools)),
            array_values(array_unique($roles)),
        );

        return $this->scoped->selectSchool($union, $school);
    }

    /**
     * Périmètre non vide exigé (sinon 403) : point d'entrée des lectures et des écritures.
     *
     * @param list<string> $permissions
     */
    public function require(Principal $principal, string $establishmentId, array $permissions, ?string $school = null): Perimeter
    {
        $perimeter = $this->perimeter($principal, $establishmentId, $permissions, $school);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
        }

        return $perimeter;
    }

    /** @param array<string, ?string> $row */
    public function assertReadable(Perimeter $perimeter, array $row): void
    {
        $this->scoped->assertReadable($perimeter, $row);
    }

    /** @param array<string, ?string> $row */
    public function assertWritable(Principal $principal, Perimeter $perimeter, array $row, string $objectType, string $objectId): void
    {
        $this->scoped->assertWritable($principal, $perimeter, $row, $objectType, $objectId);
    }

    /** L'opération exige tout l'établissement (création d'une école, par exemple). */
    public function assertWholeEstablishment(Principal $principal, Perimeter $perimeter, string $objectType): void
    {
        if ($perimeter->wholeEstablishment) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.scope.denied', $objectType, $perimeter->establishmentId, null, ['scope' => 'establishment']);

        throw HttpException::forbidden('Cette opération exige une affectation sur tout l\'établissement.');
    }

    /**
     * @param array<string, string> $columns clé de périmètre => colonne SQL
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFilter(Perimeter $perimeter, array $columns): array
    {
        return $this->scoped->sqlFilter($perimeter, $columns);
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
