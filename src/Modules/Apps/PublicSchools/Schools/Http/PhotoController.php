<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\SchoolPhotoService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Photos géolocalisées de visite (EX-ECO-04) : envoi par l'inspection, lecture par lien signé à durée limitée. */
final class PhotoController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly SchoolAccess $access,
        private readonly SchoolPhotoService $photos,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->read($principal, 'public_schools.inspection.read', $establishmentId);

        return $this->responder->json(['data' => $this->photos->list($principal->tenantId, $establishmentId)]);
    }

    /** Envoi multipart (champ `file`) ou JSON (`content_base64`) avec latitude, longitude, taken_at et visit_id facultatif. */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->inspect($principal, 'public_schools.inspection.write', $establishmentId);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file instanceof UploadedFileInterface) {
            $fields = (array) $request->getParsedBody();
            $content = (string) $file->getStream();
        } else {
            $fields = ApiRequest::body($request);
            $decoded = is_string($fields['content_base64'] ?? null) ? base64_decode($fields['content_base64'], true) : false;
            if ($decoded === false) {
                throw HttpException::validation(['content_base64' => ['Photo encodée en base64 attendue.']]);
            }
            $content = $decoded;
        }
        $visit = $fields['visit_id'] ?? null;
        $photo = $this->photos->add(
            $principal->tenantId,
            $establishmentId,
            $content,
            $fields['latitude'] ?? null,
            $fields['longitude'] ?? null,
            $fields['taken_at'] ?? null,
            is_string($visit) && $visit !== '' ? $visit : null,
            $principal->userId,
        );
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            'public_schools.school_photo.added',
            'school_photo',
            (string) $photo['id'],
            null,
            ['establishment_id' => $establishmentId, 'visit_id' => $photo['visit_id']],
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );

        return $this->responder->json(['data' => $photo], 201);
    }

    public function content(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->read($principal, 'public_schools.inspection.read', $establishmentId);
        $query = $request->getQueryParams();
        $expires = $query['expires'] ?? null;
        $signature = $query['signature'] ?? null;
        if (!is_string($expires) || preg_match('/^\d{1,12}$/', $expires) !== 1 || !is_string($signature)) {
            throw HttpException::forbidden('Lien de photo invalide.');
        }
        $media = $this->photos->read($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'), (int) $expires, $signature);

        return new Response(200, ['Content-Type' => $media->mime, 'Cache-Control' => 'private, no-store'], Stream::create($media->content));
    }
}
