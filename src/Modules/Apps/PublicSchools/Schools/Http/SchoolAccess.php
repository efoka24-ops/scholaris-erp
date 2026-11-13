<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

/**
 * Contrôle de périmètre des routes de l'école (RG-TER-2) : la fiche, les infrastructures et les photos concernent l'école entière.
 * Lecture : l'école elle-même ou une supervision qui la contient, sinon 404 (l'existence est cachée). Écriture : l'école entière
 * (le directeur), sinon 403 ; une supervision territoriale ne modifie jamais la fiche.
 */
final class SchoolAccess
{
    public function __construct(private readonly ScopedByTerritory $scope)
    {
    }

    public function read(Principal $principal, string $permission, string $establishmentId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, $permission, $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }

        return $perimeter;
    }

    public function write(Principal $principal, string $permission, string $establishmentId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, $permission, $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . $permission . '.');
        }
        if (!$perimeter->wholeSchool) {
            $this->scope->assertWritable($principal, Perimeter::none($perimeter->tenantId, $establishmentId, $permission), null, 'school', $establishmentId);
        }

        return $perimeter;
    }

    /** Écriture de l'inspection : l'école entière ou une supervision territoriale qui contient l'école (photos de visite). */
    public function inspect(Principal $principal, string $permission, string $establishmentId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, $permission, $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . $permission . '.');
        }
        $this->scope->assertWritable($principal, $perimeter, null, 'school', $establishmentId);

        return $perimeter;
    }
}
