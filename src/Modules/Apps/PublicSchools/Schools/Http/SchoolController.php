<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\SchoolFicheService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Fiche de l'école publique de l'établissement actif (EX-ECO-01, RG-TER-5). */
final class SchoolController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly SchoolAccess $access,
        private readonly SchoolFicheService $fiches,
        private readonly AuditLog $audit,
    ) {
    }

    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->read($principal, 'public_schools.school.read', $establishmentId);
        $fiche = $this->fiches->find($principal->tenantId, $establishmentId) ?? throw HttpException::notFound('Fiche école introuvable.');

        return $this->responder->json(['data' => self::resource($fiche)]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->write($principal, 'public_schools.school.write', $establishmentId);
        $before = $this->fiches->find($principal->tenantId, $establishmentId);
        $fiche = $this->fiches->save($principal->tenantId, $establishmentId, ApiRequest::body($request));
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            $before === null ? 'public_schools.school.created' : 'public_schools.school.updated',
            'school',
            (string) $fiche['id'],
            $before === null ? null : self::resource($before),
            self::resource($fiche),
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );

        return $this->responder->json(['data' => self::resource($fiche)], $before === null ? 201 : 200);
    }

    /**
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    public static function resource(array $fiche): array
    {
        return [
            'establishment_id' => (string) $fiche['establishment_id'],
            'official_code' => (string) $fiche['official_code'],
            'creation_act_ref' => $fiche['creation_act_ref'],
            'creation_act_date' => $fiche['creation_act_date'],
            'official' => (bool) $fiche['official'],
            'subsystem' => (string) $fiche['subsystem'],
            'cycle' => (string) $fiche['cycle'],
            'school_group_ref' => $fiche['school_group_ref'],
            'status' => (string) $fiche['status'],
            'latitude' => $fiche['latitude'],
            'longitude' => $fiche['longitude'],
            'phone' => $fiche['phone'],
            'email' => $fiche['email'],
        ];
    }
}
