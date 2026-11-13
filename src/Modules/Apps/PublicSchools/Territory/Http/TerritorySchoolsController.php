<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Http;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Apps\PublicSchools\Territory\Infrastructure\PdoOfficialSchools;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Écoles d'un territoire (API-01) et consultation nominative d'une école par la supervision (RG-TER-2, RG-TER-3, T642) : la liste
 * des écoles ne contient aucune donnée d'élève ; les élèves d'une école ne se lisent qu'avec une permission nominative, un niveau
 * arrondissement ou département et une finalité déclarée, et chaque lecture est inscrite au journal des consultations nominatives.
 */
final class TerritorySchoolsController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly TerritoryDirectory $territories,
        private readonly SchoolRegistry $schools,
        private readonly PdoOfficialSchools $official,
        private readonly ClassRoster $classes,
        private readonly PupilRegistry $pupils,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $id = ApiRequest::attribute($request, 'id');
        $establishment = ApiRequest::establishment($request);
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        if ($this->territories->find($principal->tenantId, $id, $at) === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $perimeter = $this->scope->perimeter($principal, 'public_schools.school.read', $establishment);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        $this->assertTerritoryAllowed($principal, $perimeter, $establishment, $id, $at);

        $establishments = $this->territories->establishmentsIn($principal->tenantId, $id, $at);
        if (!$perimeter->isTerritorial()) {
            $establishments = in_array($establishment, $establishments, true) ? [$establishment] : [];
        }
        $flags = $this->official->flags($principal->tenantId, $establishments);
        $officialOnly = in_array($request->getQueryParams()['official_only'] ?? null, ['1', 'true'], true);
        $rows = [];
        foreach ($establishments as $schoolId) {
            $school = $this->schools->find($principal->tenantId, $schoolId);
            if ($school === null) {
                continue;
            }
            $isOfficial = $flags[$schoolId] ?? false;
            if ($officialOnly && !$isOfficial) {
                continue;
            }
            $rows[] = [
                'establishment_id' => $school->establishmentId,
                'official_code' => $school->officialCode,
                'subsystem' => $school->subsystem,
                'cycle' => $school->cycle,
                'status' => $school->status,
                'official' => $isOfficial,
            ];
        }

        return $this->responder->json(['data' => $rows]);
    }

    /**
     * Écoles d'accueil possibles pour un transfert (`search` : nom ou code officiel, facultatif ; `territory_id` : territoire à parcourir,
     * par défaut celui de l'école). Aucune donnée sensible : identifiant, nom, code officiel et arrondissement. Réservé à qui peut (404 sinon, comme les autres lectures hors périmètre)
     * demander un transfert (direction, agent de saisie de l'inspection) ; bornée au territoire de l'utilisateur ; écoles ouvertes seulement.
     */
    public function receiving(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishment = ApiRequest::establishment($request);
        $permission = 'public_schools.pupils.write';
        $perimeter = $this->scope->perimeter($principal, $permission, $establishment);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial()) {
            $this->scope->assertWritable($principal, Perimeter::none($perimeter->tenantId, $establishment, $permission), null, 'school', $establishment);
        }
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $query = $request->getQueryParams();
        $own = $this->territories->territoryOf($principal->tenantId, $establishment, $at);
        $territoryId = $query['territory_id'] ?? $own?->id;
        if ($territoryId === null) {
            return $this->responder->json(['data' => [], 'meta' => ['next_cursor' => null, 'per_page' => ListQuery::fromRequest($request)->perPage]]);
        }
        if (!is_string($territoryId) || $this->territories->find($principal->tenantId, $territoryId, $at) === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $this->assertTerritoryAllowed($principal, $perimeter, $establishment, $territoryId, $at);
        $search = $query['search'] ?? null;
        if ($search !== null && !is_string($search)) {
            throw HttpException::validation(['search' => ['Texte attendu.']]);
        }
        $words = self::words(is_string($search) ? $search : '');

        $candidates = [];
        foreach ($this->territories->establishmentsIn($principal->tenantId, $territoryId, $at) as $schoolId) {
            $school = $schoolId === $establishment ? null : $this->schools->find($principal->tenantId, $schoolId);
            if ($school !== null && $school->status === SchoolInfo::STATUS_OPEN && $school->cycle !== SchoolInfo::CYCLE_NURSERY) {
                $candidates[$schoolId] = $school;
            }
        }
        $rows = [];
        foreach ($this->establishments->findMany($principal->tenantId, array_keys($candidates)) as $info) {
            $school = $candidates[$info->id] ?? null;
            if ($school === null) {
                continue;
            }
            $haystack = self::fold($info->name . ' ' . $school->officialCode);
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $district = $this->territories->territoryOf($principal->tenantId, $info->id, $at);
            $rows[] = [
                'establishment_id' => $info->id,
                'name' => $info->name,
                'official_code' => $school->officialCode,
                'district_id' => $district?->id,
                'district' => $district?->labelFr,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => [mb_strtolower($left['name']), $left['establishment_id']] <=> [mb_strtolower($right['name']), $right['establishment_id']]);

        return $this->responder->json(ListPage::of($request, $rows));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return array_values(array_filter(explode(' ', self::fold($text)), static fn (string $word): bool => $word !== ''));
    }

    /** Minuscules sans accents : la recherche ignore la casse et les diacritiques. */
    private static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            $text = is_string($decomposed) ? (string) preg_replace('/\p{Mn}+/u', '', $decomposed) : $text;
        }

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
    /**
     * Élèves d'une école du territoire (nominatif) : `academic_year_id` et `purpose` (transfer, exam, control) requis en supervision.
     * L'identifiant de l'école vient du chemin ; les droits sont résolus pour cette école.
     */
    public function pupils(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $id = ApiRequest::attribute($request, 'id');
        $school = ApiRequest::attribute($request, 'school');
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        if ($this->territories->find($principal->tenantId, $id, $at) === null
            || !in_array($school, $this->territories->establishmentsIn($principal->tenantId, $id, $at), true)
            || $this->schools->find($principal->tenantId, $school) === null) {
            throw HttpException::notFound('École introuvable dans ce territoire.');
        }
        $yearId = $request->getQueryParams()['academic_year_id'] ?? null;
        if (!is_string($yearId) || trim($yearId) === '') {
            throw HttpException::validation(['academic_year_id' => ['Champ requis.']]);
        }
        $read = $this->scope->perimeter($principal, 'public_schools.pupils.read', $school);
        if ($read->isEmpty()) {
            throw HttpException::notFound();
        }
        $purpose = $request->getQueryParams()['purpose'] ?? null;
        if ($read->isInSchool()) {
            $perimeter = $read;
        } else {
            $perimeter = $this->scope->perimeter($principal, 'public_schools.pupils.named.read', $school);
            $this->scope->assertNamedAccess($principal, $perimeter, 'school_pupils', $school, is_string($purpose) ? $purpose : null);
        }

        $rows = [];
        foreach ($this->classes->classesOf($principal->tenantId, $school, $yearId) as $class) {
            if (!$this->scope->covers($perimeter, $class->structureId)) {
                continue;
            }
            foreach ($this->classes->pupilIdsIn($principal->tenantId, $school, $class->structureId, (new DateTimeImmutable())->format('Y-m-d')) as $personId) {
                $pupil = $this->pupils->find($principal->tenantId, $personId);
                if ($pupil === null) {
                    continue;
                }
                $rows[] = [
                    'person_id' => $personId,
                    'learner_number' => $pupil->learnerNumber,
                    'last_name' => $pupil->lastName,
                    'first_name' => $pupil->firstName,
                    'structure_id' => $class->structureId,
                    'class_label' => $class->label,
                ];
            }
        }

        return $this->responder->json(['data' => $rows]);
    }

    /** Défense en profondeur : le territoire demandé doit être dans le périmètre (supervision) ou dans la lignée de l'école. */
    private function assertTerritoryAllowed(Principal $principal, Perimeter $perimeter, string $establishment, string $id, string $at): void
    {
        $allowed = [];
        if ($perimeter->isTerritorial()) {
            foreach ($perimeter->territoryIds as $scopeId) {
                foreach ($this->territories->descendantsOf($principal->tenantId, $scopeId, $at) as $node) {
                    $allowed[$node->id] = true;
                }
            }
        } else {
            $own = $this->territories->territoryOf($principal->tenantId, $establishment, $at);
            if ($own !== null) {
                foreach ($this->territories->lineage($principal->tenantId, $own->id, $at) as $node) {
                    $allowed[$node->id] = true;
                }
            }
        }
        if (!isset($allowed[$id])) {
            throw HttpException::notFound();
        }
    }
}
