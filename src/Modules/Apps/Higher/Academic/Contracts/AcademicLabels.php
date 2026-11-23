<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/**
 * Libellés des unités académiques en une lecture par type (tableaux de bord, exports) : évite de lire école, département, filière,
 * niveau ou groupe un par un. Borné au client et à l'établissement ; un identifiant inconnu est simplement absent du résultat.
 */
interface AcademicLabels
{
    public const SCHOOL = 'school';
    public const DEPARTMENT = 'department';
    public const PROGRAM = 'program';
    public const LEVEL = 'level';
    public const GROUP = 'group';

    /**
     * @param self::SCHOOL|self::DEPARTMENT|self::PROGRAM|self::LEVEL|self::GROUP $kind
     * @param list<string> $ids
     * @return array<string, string> libellé (« CODE - Nom ») par identifiant
     */
    public function names(string $tenantId, string $establishmentId, string $kind, array $ids): array;

    /** @return array<string, string> écoles de l'établissement (identifiant => libellé), par code */
    public function schools(string $tenantId, string $establishmentId): array;
}
