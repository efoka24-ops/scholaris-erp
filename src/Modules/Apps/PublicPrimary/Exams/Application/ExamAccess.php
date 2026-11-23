<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryScope;
use Scholaris\Modules\Apps\PublicPrimary\Support\Reach;
use Scholaris\Modules\Apps\PublicSchools\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Droits des examens (matrice du CDC, « Listes d'examen ») :
 *  - école : le directeur écrit la liste (`public_primary.exams.write`), l'enseignant la lit (`public_primary.exams.read`, sa classe) ;
 *  - inspection d'arrondissement : contrôle et validation (`public_primary.exams.validate`, territoire de type arrondissement) ;
 *  - délégation départementale : consolidation, centres, gel (`validate` au niveau département, `consolidate`), import (`import`) ;
 *  - national (rôle de niveau client, R02) : sessions, consolidation, import.
 * Les listes d'examen sont la seule exception à RG-TER-3 : la supervision les lit nominativement, chaque lecture est journalisée
 * (finalité `exam`, NamedAccessJournal) et visible du directeur.
 */
final class ExamAccess
{
    public const READ = 'public_primary.exams.read';
    public const WRITE = 'public_primary.exams.write';
    public const VALIDATE = 'public_primary.exams.validate';
    public const CONSOLIDATE = 'public_primary.exams.consolidate';
    public const IMPORT = 'public_primary.exams.import';
    public const ATTEST = 'public_primary.exams.attest';
    public const ALL = [self::WRITE, self::VALIDATE, self::CONSOLIDATE, self::IMPORT, self::ATTEST, self::READ];

    public const INSPECTION_TYPE = 'arrondissement';
    public const DELEGATION_TYPE = 'departement';

    public function __construct(
        private readonly PrimaryScope $scope,
        private readonly TerritoryDirectory $territories,
        private readonly NamedAccessJournal $journal,
    ) {
    }

    /** Premier périmètre non vide parmi les permissions ; 404 si aucun (l'existence est cachée). */
    public function reader(Principal $principal, string $establishmentId, string ...$permissions): Reach
    {
        $reach = $this->scope->first($principal, $establishmentId, ...($permissions === [] ? self::ALL : $permissions));
        if ($reach->isEmpty()) {
            throw HttpException::notFound();
        }

        return $reach;
    }

    /** Écriture du directeur (école entière) : 403 journalisé sinon. */
    public function director(Principal $principal, string $establishmentId, string $objectType, string $objectId): Reach
    {
        $reach = $this->scope->reach($principal, self::WRITE, $establishmentId);
        if ($reach->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . self::WRITE . '.');
        }
        $this->scope->assertWholeSchool($principal, $reach, $objectType, $objectId, 'La liste des candidats est tenue par le directeur.');

        return $reach;
    }

    /** Vrai si le périmètre vient d'un rôle national (niveau client : direction des examens R02). */
    public function isNational(Reach $reach): bool
    {
        foreach ($reach->roles as $role) {
            if (RoleMatrix::levelOf($role) === 'tenant') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> types de territoire de l'affectation (`arrondissement`, `departement`…) */
    public function territoryTypes(Reach $reach): array
    {
        return $this->scope->territoryTypes($reach);
    }

    /**
     * Périmètre territorial d'une opération de consolidation : 404 si la permission manque, 403 si le territoire demandé sort de
     * l'affectation (il doit être l'affectation elle-même ou l'un de ses descendants) ; un rôle national atteint tout territoire.
     *
     * @return array{reach: Reach, establishments: list<string>, national: bool}
     */
    public function territory(Principal $principal, string $establishmentId, string $territoryId, string ...$permissions): array
    {
        $reach = $this->reader($principal, $establishmentId, ...$permissions);
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        if ($this->territories->find($principal->tenantId, $territoryId, $at) === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $national = $reach->wholeSchool && $this->isNational($reach);
        if (!$national) {
            $lineage = array_map(static fn ($node): string => $node->id, $this->territories->lineage($principal->tenantId, $territoryId, $at));
            if (!$reach->isTerritorial() || array_intersect($lineage, $reach->territoryIds) === []) {
                throw HttpException::forbidden('Ce territoire est hors de votre périmètre.');
            }
        }

        return ['reach' => $reach, 'establishments' => $this->territories->establishmentsIn($principal->tenantId, $territoryId, $at), 'national' => $national];
    }

    /**
     * `territory_id` d'un corps de requête : permission contrôlée AVANT la validation (404 sans droit), puis 422 s'il manque.
     *
     * @param array<string, mixed> $input
     */
    public function territoryInput(Principal $principal, string $establishmentId, array $input, string ...$permissions): string
    {
        $this->reader($principal, $establishmentId, ...$permissions);
        $territoryId = $input['territory_id'] ?? null;
        if (!is_string($territoryId) || trim($territoryId) === '') {
            throw HttpException::validation(['territory_id' => ['Territoire requis.']]);
        }

        return trim($territoryId);
    }

    /**
     * Territoires (et descendants) de l'affectation ; null pour un rôle national (tous).
     *
     * @return list<string>|null
     */
    public function territoryIdsOf(Principal $principal, Reach $reach): ?array
    {
        if ($reach->wholeSchool && $this->isNational($reach)) {
            return null;
        }
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $ids = [];
        foreach ($reach->territoryIds as $territoryId) {
            foreach ($this->territories->descendantsOf($principal->tenantId, $territoryId, $at) as $node) {
                $ids[$node->id] = $node->id;
            }
        }
        ksort($ids);

        return array_values($ids);
    }

    /** @return list<string> le territoire et ses descendants, à l'instant présent */
    public function descendantIds(string $tenantId, string $territoryId): array
    {
        $at = (new DateTimeImmutable())->format(DATE_ATOM);

        return array_map(static fn ($node): string => $node->id, $this->territories->descendantsOf($tenantId, $territoryId, $at));
    }

    /** Lecture nominative d'une liste par la supervision : journalisée (finalité `exam`), visible du directeur (RG-TER-2). */
    public function journal(Principal $principal, Reach $reach, string $establishmentId, string $recordType, string $recordId): void
    {
        if ($reach->isInSchool() && !$this->isNational($reach)) {
            return;
        }
        $this->journal->record($principal->tenantId, $establishmentId, $principal->userId, $recordType, $recordId, 'exam');
    }
}
