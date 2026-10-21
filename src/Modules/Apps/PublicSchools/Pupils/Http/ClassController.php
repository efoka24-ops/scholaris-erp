<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassHeadcountService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassProfileService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DistributionService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilClock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Classes de l'école (CLA, EX-CLA-01..04) : niveaux jumelés, double flux, titulaire daté, effectifs par sexe et âge, alerte d'effectif. */
final class ClassController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PupilAccess $access,
        private readonly ClassRoster $classes,
        private readonly ClassProfileService $profiles,
        private readonly ClassHeadcountService $headcounts,
        private readonly DistributionService $distribution,
        private readonly PupilClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $created = $this->profiles->create($principal->tenantId, $establishmentId, $principal->userId, ApiRequest::body($request));
        $this->record($request, 'public_schools.class.created', $created['structure_id'], $created);

        return $this->responder->json(['data' => $created], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $perimeter = $this->access->read($principal, $establishmentId);
        $yearId = $request->getQueryParams()['academic_year_id'] ?? null;
        if (!is_string($yearId) || trim($yearId) === '') {
            throw HttpException::validation(['academic_year_id' => ['Champ requis.']]);
        }
        $today = $this->clock->today();
        $rows = [];
        foreach ($this->classes->classesOf($principal->tenantId, $establishmentId, $yearId) as $class) {
            if (!$this->access->covers($perimeter, $class->structureId)) {
                continue;
            }
            $count = $this->headcounts->of($principal->tenantId, $establishmentId, $class->structureId, $today);
            $rows[] = self::resource($class) + ['headcount' => $count['total'], 'capacity_alert' => $count['alert'], 'alert_threshold' => $count['threshold']];
        }

        return $this->responder->json(['data' => $rows]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $structureId = ApiRequest::attribute($request, 'id');
        $perimeter = $this->access->read($principal, $establishmentId);
        if (!$this->access->covers($perimeter, $structureId)) {
            throw HttpException::notFound();
        }
        $class = $this->classes->find($principal->tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Classe introuvable.');

        return $this->responder->json(['data' => self::resource($class)]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $structureId = ApiRequest::attribute($request, 'id');
        $body = ApiRequest::body($request);
        $unknown = array_diff(array_keys($body), ['name', 'capacity', 'level_codes', 'shift']);
        if ($unknown !== []) {
            throw HttpException::validation(array_fill_keys(array_map('strval', $unknown), ['Champ non modifiable ici.']));
        }
        $this->profiles->update($principal->tenantId, $establishmentId, $structureId, $principal->userId, $body);
        $this->record($request, 'public_schools.class.updated', $structureId, ['fields' => array_keys($body)]);
        $class = $this->classes->find($principal->tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Classe introuvable.');

        return $this->responder->json(['data' => self::resource($class)]);
    }

    public function assignHomeroom(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $structureId = ApiRequest::attribute($request, 'id');
        $body = ApiRequest::body($request);
        $staffId = $body['staff_id'] ?? null;
        $startsOn = $body['starts_on'] ?? null;
        if (($staffId !== null && !is_string($staffId)) || !is_string($startsOn)) {
            throw HttpException::validation(['starts_on' => ['starts_on (AAAA-MM-JJ) requis ; staff_id texte ou null.']]);
        }
        $result = $this->profiles->assignHomeroom($principal->tenantId, $establishmentId, $structureId, $staffId, $startsOn, $principal->userId);
        $this->record($request, 'public_schools.class.homeroom_assigned', $structureId, $result);

        return $this->responder->json(['data' => $result]);
    }

    public function homeroomHistory(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $structureId = ApiRequest::attribute($request, 'id');
        $perimeter = $this->access->read($principal, $establishmentId);
        if (!$this->access->covers($perimeter, $structureId)) {
            throw HttpException::notFound();
        }

        return $this->responder->json(['data' => $this->profiles->homeroomHistory($principal->tenantId, $establishmentId, $structureId)]);
    }

    public function headcount(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $structureId = ApiRequest::attribute($request, 'id');
        $perimeter = $this->access->read($principal, $establishmentId);
        if (!$this->access->covers($perimeter, $structureId)) {
            throw HttpException::notFound();
        }
        $this->classes->find($principal->tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Classe introuvable.');
        $date = $request->getQueryParams()['date'] ?? $this->clock->today();
        $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw HttpException::validation(['date' => ['Date AAAA-MM-JJ attendue.']]);
        }

        return $this->responder->json(['data' => $this->headcounts->of($principal->tenantId, $establishmentId, $structureId, $date)]);
    }

    /** EX-CLA-04 : répartition proposée entre les classes données (`class_ids` séparés par des virgules) ; lecture seule. */
    public function distributionProposal(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $ids = $request->getQueryParams()['class_ids'] ?? null;
        if (!is_string($ids) || trim($ids) === '') {
            throw HttpException::validation(['class_ids' => ['Identifiants de classes séparés par des virgules requis.']]);
        }

        return $this->responder->json(['data' => $this->distribution->propose(
            $principal->tenantId,
            $establishmentId,
            array_values(array_filter(array_map('trim', explode(',', $ids)), static fn (string $id): bool => $id !== '')),
            $this->clock->today(),
        )]);
    }

    /** @return array<string, mixed> */
    private static function resource(ClassInfo $class): array
    {
        return [
            'structure_id' => $class->structureId,
            'establishment_id' => $class->establishmentId,
            'label' => $class->label,
            'level_codes' => $class->levelCodes,
            'twinned' => count($class->levelCodes) > 1,
            'shift' => $class->shift,
            'homeroom_staff_id' => $class->homeroomStaffId,
        ];
    }

    /** @param array<string, mixed> $after */
    private function record(ServerRequestInterface $request, string $action, string $id, array $after): void
    {
        $principal = ApiRequest::principal($request);
        $this->audit->append($principal->tenantId, $principal->userId, $action, 'class', $id, null, $after, ApiRequest::ip($request), ApiRequest::device($request));
    }
}
