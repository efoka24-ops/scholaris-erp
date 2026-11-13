<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

/**
 * Contrôle de périmètre des routes des élèves (RG-TER-2, RG-TER-3) :
 *  - gestion de l'école (classes, transferts, abandons) : l'école entière (le directeur), sinon 403 journalisé ;
 *  - inscription : l'école entière ou l'agent de saisie de l'inspection (affectation territoriale, EX-ELV-07), jamais une seule classe ;
 *  - lecture d'une liste ou d'une fiche : dans l'école selon la classe, ou par supervision territoriale (nominatif : arrondissement et
 *    département, permission nominative et finalité déclarée, lecture journalisée).
 */
final class PupilAccess
{
    public function __construct(private readonly ScopedByTerritory $scope)
    {
    }

    public function manage(Principal $principal, string $permission, string $establishmentId): Perimeter
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

    public function register(Principal $principal, string $establishmentId, ?string $classId): Perimeter
    {
        $permission = 'public_schools.pupils.write';
        $perimeter = $this->scope->perimeter($principal, $permission, $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . $permission . '.');
        }
        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial()) {
            $this->scope->assertWritable($principal, Perimeter::none($perimeter->tenantId, $establishmentId, $permission), $classId, 'school', $establishmentId);
        }

        return $perimeter;
    }

    /** Périmètre de lecture des élèves (404 si vide). */
    public function read(Principal $principal, string $establishmentId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, 'public_schools.pupils.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }

        return $perimeter;
    }

    /**
     * Garde de la lecture nominative d'un élève ou d'une liste : dans l'école, lecture ordinaire ; en supervision territoriale, permission
     * nominative, niveau arrondissement ou département et finalité déclarée, avec lecture journalisée.
     */
    public function named(Principal $principal, Perimeter $read, string $recordType, string $recordId, ?string $purpose): void
    {
        if ($read->isInSchool()) {
            return;
        }
        $named = $this->scope->perimeter($principal, 'public_schools.pupils.named.read', $read->establishmentId);
        $this->scope->assertNamedAccess($principal, $named, $recordType, $recordId, $purpose);
    }

    public function covers(Perimeter $perimeter, ?string $classId): bool
    {
        return $this->scope->covers($perimeter, $classId);
    }
}
