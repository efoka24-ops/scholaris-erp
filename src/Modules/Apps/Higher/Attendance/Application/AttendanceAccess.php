<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Contrôle d'accès des présences et de la discipline : permission `higher.*` (refus par défaut) PUIS périmètre école, département, filière, niveau ou
 * groupe (RG-ORG-2) par Support\ScopedBySchool. Plusieurs permissions possibles : leurs périmètres s'additionnent.
 * Hors périmètre : 404 en lecture, 403 journalisé en écriture.
 */
final class AttendanceAccess
{
    public const COLUMNS = ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'level_id', 'group' => 'group_id'];

    public function __construct(
        private readonly ScopedBySchool $scoped,
        private readonly AuditLog $audit,
        private readonly UserPersonLink $links,
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

    public static function selectedSchool(ServerRequestInterface $request): ?string
    {
        $school = $request->getQueryParams()['school_id'] ?? null;

        return is_string($school) && $school !== '' ? $school : null;
    }

    /** Personne liée au compte (enseignant, délégué), null si aucune. */
    public function personOf(Principal $principal): ?string
    {
        return $this->links->personIdForUser($principal->tenantId, $principal->userId);
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

    /** @param list<string> $permissions */
    public function require(Principal $principal, string $establishmentId, array $permissions, ?string $school = null): Perimeter
    {
        $perimeter = $this->perimeter($principal, $establishmentId, $permissions, $school);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
        }

        return $perimeter;
    }

    /** @param list<string> $permissions */
    public function has(Principal $principal, string $establishmentId, array $permissions): bool
    {
        return !$this->perimeter($principal, $establishmentId, $permissions)->isEmpty();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    public static function scopeOf(array $row): array
    {
        $scope = [];
        foreach (self::COLUMNS as $key => $column) {
            $scope[$key] = isset($row[$column]) ? (string) $row[$column] : null;
        }

        return $scope;
    }

    /** @param array<string, mixed> $row */
    public function covers(Perimeter $perimeter, array $row): bool
    {
        return $this->scoped->covers($perimeter, self::scopeOf($row));
    }

    /** @param array<string, mixed> $row */
    public function assertReadable(Perimeter $perimeter, array $row): void
    {
        $this->scoped->assertReadable($perimeter, self::scopeOf($row));
    }

    /** @param array<string, mixed> $row */
    public function assertWritable(Principal $principal, Perimeter $perimeter, array $row, string $objectType, string $objectId): void
    {
        $this->scoped->assertWritable($principal, $perimeter, self::scopeOf($row), $objectType, $objectId);
    }

    /**
     * @param array<string, string>|null $columns
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFilter(Perimeter $perimeter, ?array $columns = null): array
    {
        return $this->scoped->sqlFilter($perimeter, $columns ?? self::COLUMNS);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(string $tenantId, ?string $actorId, string $action, string $objectType, string $objectId, ?array $before = null, ?array $after = null): void
    {
        $this->audit->append($tenantId, $actorId, $action, $objectType, $objectId, $before, $after);
    }
}
