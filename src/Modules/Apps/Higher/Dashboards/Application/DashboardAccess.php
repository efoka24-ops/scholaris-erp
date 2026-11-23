<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicLabels;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\Board;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;

/**
 * Accès aux tableaux de bord : `higher.dashboard.read` donne le périmètre (école, département, filière), une permission de donnée
 * du tableau l'ouvre (403 sinon). Le sélecteur d'école (RG-ORG-3) restreint le périmètre ; une école hors périmètre répond 404.
 */
final class DashboardAccess
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicLabels $labels,
    ) {
    }

    public function perimeter(Principal $principal, string $establishmentId, string $board, ?string $school): Perimeter
    {
        $permissions = Board::PERMISSIONS[$board] ?? throw HttpException::notFound('Tableau de bord inconnu.');
        $perimeter = $this->access->perimeter($principal, $establishmentId, [Board::READ], $school === 'all' ? null : $school);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . Board::READ . '.');
        }
        if ($this->access->perimeter($principal, $establishmentId, $permissions)->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
        }

        return $perimeter;
    }

    /**
     * Écoles que la personne peut choisir (sélecteur « École » : seulement si elle exerce dans plusieurs écoles).
     *
     * @return list<array{id: string, name: string}>
     */
    public function selectableSchools(Principal $principal, string $establishmentId): array
    {
        $whole = $this->access->perimeter($principal, $establishmentId, [Board::READ]);
        if (!$whole->showsSchoolSelector()) {
            return [];
        }
        $names = $whole->wholeEstablishment
            ? $this->labels->schools($principal->tenantId, $establishmentId)
            : $this->labels->names($principal->tenantId, $establishmentId, AcademicLabels::SCHOOL, $whole->schoolIds);
        $schools = [];
        foreach ($names as $id => $name) {
            $schools[] = ['id' => (string) $id, 'name' => $name];
        }

        return $schools;
    }
}
