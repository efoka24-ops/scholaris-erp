<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Support;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Policies\RoleMatrix;
use Scholaris\Modules\Apps\PublicSchools\Policies\ScopeResolver;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Second temps du contrôle de périmètre (RG-TER-2, RG-TER-3) : transforme un Perimeter en filtre SQL imposé par les dépôts,
 * contrôle une ligne isolée et décide si une supervision territoriale peut lire du NOMINATIF.
 *
 *  - L'école, la classe : lecture et écriture selon l'affectation (directeur : toute l'école ; titulaire : sa classe).
 *  - Hors périmètre : 404 en lecture (l'existence est cachée), 403 journalisé en écriture.
 *  - Supervision territoriale : nominatif seulement si (a) la permission est nominative (`*.named.*`, jamais accordée aux rôles à
 *    agrégats seuls), (b) l'affectation est un arrondissement ou un département (région et national : agrégats seuls),
 *    (c) une finalité déclarée (`transfer`, `exam`, `control`) accompagne la lecture ; chaque lecture est inscrite au journal des
 *    consultations nominatives (NamedAccessJournal), visible du directeur de l'école consultée.
 *
 *     $perimeter = $scoped->perimeter($principal, 'public_schools.pupils.named.read', $establishmentId);
 *     $scoped->assertNamedAccess($principal, $perimeter, 'pupil', $pupilId, 'transfer');
 *     [$where, $params] = $scoped->sqlFilter($perimeter, ['class' => 'e.structure_id']);
 */
final class ScopedByTerritory
{
    /** Finalités déclarables d'une lecture nominative territoriale (PP3). */
    public const PURPOSES = ['transfer', 'exam', 'control'];

    /** Types de territoire (taxonomie d'Organisation) autorisés à lire du nominatif : arrondissement et département. */
    public const NAMED_TERRITORY_TYPES = ['arrondissement', 'departement'];

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly TerritoryDirectory $territories,
        private readonly NamedAccessJournal $journal,
        private readonly AuditLog $audit,
    ) {
    }

    public function perimeter(Principal $principal, string $permission, string $establishmentId): Perimeter
    {
        return $this->resolver->resolve($principal, $permission, $establishmentId);
    }

    /**
     * Filtre SQL à ajouter (avec AND) à la requête d'un dépôt ; paramètres positionnels. Les dépôts bornent déjà par
     * `tenant_id` et `establishment_id`. Seule la clé `class` est reconnue.
     *
     * @param array<string, string> $columns clé reconnue (`class`) => colonne SQL
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFilter(Perimeter $perimeter, array $columns): array
    {
        if ($perimeter->isEmpty()) {
            return ['1 = 0', []];
        }
        if ($perimeter->wholeSchool || $perimeter->isTerritorial()) {
            return ['1 = 1', []];
        }
        $clauses = [];
        $params = [];
        foreach ($columns as $key => $column) {
            if ($key !== 'class') {
                throw new \InvalidArgumentException('Clé de périmètre inconnue : ' . $key);
            }
            if (preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/', $column) !== 1) {
                throw new \InvalidArgumentException('Colonne de périmètre invalide : ' . $column);
            }
            $clauses[] = $column . ' IN (' . implode(', ', array_fill(0, count($perimeter->classIds), '?')) . ')';
            array_push($params, ...$perimeter->classIds);
        }

        return $clauses === [] ? ['1 = 0', []] : ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    public function covers(Perimeter $perimeter, ?string $classId): bool
    {
        return !$perimeter->isEmpty() && $perimeter->coversClass($classId);
    }

    /** @throws HttpException 404 hors périmètre */
    public function assertReadable(Perimeter $perimeter, ?string $classId): void
    {
        if (!$this->covers($perimeter, $classId)) {
            throw HttpException::notFound();
        }
    }

    /** @throws HttpException 403 journalisé hors périmètre */
    public function assertWritable(Principal $principal, Perimeter $perimeter, ?string $classId, string $objectType, string $objectId): void
    {
        if ($this->covers($perimeter, $classId)) {
            return;
        }
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            'public_schools.scope.denied',
            $objectType,
            $objectId,
            null,
            ['establishment_id' => $perimeter->establishmentId, 'class_id' => $classId],
        );

        throw HttpException::forbidden('Cette opération sort de votre périmètre (école, classe ou territoire).');
    }

    /**
     * Vrai si le périmètre permet de lire du nominatif : on exerce dans l'école, ou la supervision est une affectation de niveau
     * arrondissement ou département avec une permission nominative. Région et national : toujours faux (agrégats seuls).
     */
    public function canReadNamed(Perimeter $perimeter, ?DateTimeImmutable $at = null): bool
    {
        if ($perimeter->isEmpty()) {
            return false;
        }
        if ($perimeter->isInSchool()) {
            return true;
        }
        if (!RoleMatrix::isNamed($perimeter->permission)) {
            return false;
        }
        $now = ($at ?? new DateTimeImmutable())->format(DATE_ATOM);
        foreach ($perimeter->territoryIds as $territoryId) {
            $info = $this->territories->find($perimeter->tenantId, $territoryId, $now);
            if ($info !== null && in_array($info->type, self::NAMED_TERRITORY_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * À appeler AVANT de rendre un enregistrement nominatif. Exercer dans l'école : lecture ordinaire. Supervision territoriale :
     * niveau, permission nominative et finalité exigés, puis lecture journalisée.
     *
     * @throws HttpException 404 périmètre vide ; 403 agrégats seuls ; 422 finalité absente ou inconnue
     */
    public function assertNamedAccess(Principal $principal, Perimeter $perimeter, string $recordType, string $recordId, ?string $purpose, ?DateTimeImmutable $at = null): void
    {
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        if ($perimeter->isInSchool()) {
            return;
        }
        if (!$this->canReadNamed($perimeter, $at)) {
            throw HttpException::forbidden('Données nominatives non accessibles à ce niveau : agrégats seuls.');
        }
        if ($purpose === null || !in_array($purpose, self::PURPOSES, true)) {
            throw HttpException::validation(['purpose' => ['La finalité de la consultation nominative est obligatoire : ' . implode(', ', self::PURPOSES) . '.']]);
        }

        $this->journal->record($principal->tenantId, $perimeter->establishmentId, $principal->userId, $recordType, $recordId, $purpose);
    }
}
