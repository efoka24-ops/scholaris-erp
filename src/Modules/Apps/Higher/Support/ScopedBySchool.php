<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Policies\ScopeResolver;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Second temps du contrôle de périmètre (RG-ORG-2, RG-ORG-3) : transforme un Perimeter en filtre SQL imposé par les dépôts Higher,
 * applique le sélecteur « École » et contrôle une ligne isolée.
 *
 * Colonnes reconnues (une table n'en porte que certaines) : `school` et `department` (unités internes), `program` (filière),
 * `level` et `group` (structures). Une ligne est visible si l'une de ses colonnes renseignées tombe dans le périmètre.
 * Hors périmètre : 404 en lecture (l'existence est cachée), 403 journalisé en écriture.
 *
 *     $perimeter = $scoped->perimeter($principal, 'higher.enrolment.read', $establishmentId, $query['school_id'] ?? null);
 *     [$where, $params] = $scoped->sqlFilter($perimeter, ['school' => 'e.school_id', 'program' => 'e.program_id']);
 */
final class ScopedBySchool
{
    public const ALL_SCHOOLS = 'all';
    private const ORG_UNIT_KEYS = ['school', 'department'];
    private const STRUCTURE_KEYS = ['program', 'level', 'group'];

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly OrgUnitTree $tree,
        private readonly AuditLog $audit,
    ) {
    }

    /** Périmètre de la permission, restreint à l'école choisie (`school_id`, ou `all`/null pour toutes celles de la personne). */
    public function perimeter(Principal $principal, string $permission, string $establishmentId, ?string $school = null): Perimeter
    {
        return $this->selectSchool($this->resolver->resolve($principal, $permission, $establishmentId), $school);
    }

    /** RG-ORG-3 : une école hors du périmètre (ou qui n'est pas une école de l'établissement) répond 404. */
    public function selectSchool(Perimeter $perimeter, ?string $school): Perimeter
    {
        if ($school === null || $school === '' || $school === self::ALL_SCHOOLS || $perimeter->isEmpty()) {
            return $perimeter;
        }
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $units = $this->tree->descendantsOf($tenantId, $establishmentId, $school);
        $isSchool = $units !== [] && $this->tree->ancestorsOf($tenantId, $establishmentId, $school) === [];
        if (!$isSchool || (!$perimeter->wholeEstablishment && !in_array($school, $perimeter->schoolIds, true))) {
            throw HttpException::notFound('École introuvable.');
        }
        $structures = $this->tree->structuresOf($tenantId, $establishmentId, $school);
        if (!$perimeter->wholeEstablishment) {
            $units = array_values(array_intersect($perimeter->orgUnitIds, $units));
            $structures = array_values(array_intersect($perimeter->structureIds, $structures));
        }

        return new Perimeter($tenantId, $establishmentId, false, $units, $structures, [$school], $perimeter->roles);
    }

    /**
     * Filtre SQL à ajouter (avec AND) à la requête d'un dépôt Higher ; paramètres positionnels.
     *
     * @param array<string, string> $columns clé reconnue (school, department, program, level, group) => colonne SQL
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFilter(Perimeter $perimeter, array $columns): array
    {
        if ($perimeter->wholeEstablishment) {
            return ['1 = 1', []];
        }
        $clauses = [];
        $params = [];
        foreach ($columns as $key => $column) {
            if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column) !== 1) {
                throw new \InvalidArgumentException('Colonne de périmètre invalide : ' . $column);
            }
            $ids = match (true) {
                in_array($key, self::ORG_UNIT_KEYS, true) => $perimeter->orgUnitIds,
                in_array($key, self::STRUCTURE_KEYS, true) => $perimeter->structureIds,
                default => throw new \InvalidArgumentException('Clé de périmètre inconnue : ' . $key),
            };
            if ($ids === []) {
                continue;
            }
            $clauses[] = $column . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }

        return $clauses === [] ? ['1 = 0', []] : ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    /** @param array<string, ?string> $row clé reconnue => identifiant de la ligne */
    public function covers(Perimeter $perimeter, array $row): bool
    {
        if ($perimeter->wholeEstablishment) {
            return true;
        }
        foreach ($row as $key => $id) {
            if (in_array($key, self::ORG_UNIT_KEYS, true) && $perimeter->coversOrgUnit($id)) {
                return true;
            }
            if (in_array($key, self::STRUCTURE_KEYS, true) && $perimeter->coversStructure($id)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, ?string> $row */
    public function assertReadable(Perimeter $perimeter, array $row): void
    {
        if (!$this->covers($perimeter, $row)) {
            throw HttpException::notFound();
        }
    }

    /** @param array<string, ?string> $row */
    public function assertWritable(Principal $principal, Perimeter $perimeter, array $row, string $objectType, string $objectId): void
    {
        if ($this->covers($perimeter, $row)) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.scope.denied', $objectType, $objectId, null, ['scope' => $row]);

        throw HttpException::forbidden('Cette opération sort de votre périmètre (école, département, filière ou groupe).');
    }
}
