<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\RegisterPhotoService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Photo du registre papier d'une inscription groupée (C-105, EX-ELV-07) : envoi multipart (champ `file`) ou JSON (`content_base64`),
 * puis lecture par lien signé à durée limitée. Mêmes droits que l'inscription (directeur ou agent de saisie, R20) ; chaque lecture est
 * journalisée car la photo montre des noms d'élèves.
 */
final class RegisterPhotoController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PupilAccess $access,
        private readonly RegisterPhotoService $photos,
        private readonly AuditLog $audit,
    ) {
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->register($principal, $establishmentId, null);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file instanceof UploadedFileInterface) {
            $content = (string) $file->getStream();
        } else {
            $fields = ApiRequest::body($request);
            $decoded = is_string($fields['content_base64'] ?? null) ? base64_decode($fields['content_base64'], true) : false;
            if ($decoded === false) {
                throw HttpException::validation(['file' => ['Photo du registre attendue (champ file ou content_base64).']]);
            }
            $content = $decoded;
        }
        $photo = $this->photos->add($principal->tenantId, $establishmentId, $content, $principal->userId);
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            'public_schools.pupils.register_photo_added',
            'pub_register_photo',
            $photo['id'],
            null,
            ['establishment_id' => $establishmentId],
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );

        return $this->responder->json(['data' => $photo], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->register($principal, $establishmentId, null);

        return $this->responder->json(['data' => $this->photos->link($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'))]);
    }

    public function content(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->register($principal, $establishmentId, null);
        $query = $request->getQueryParams();
        $expires = $query['expires'] ?? null;
        $signature = $query['signature'] ?? null;
        if (!is_string($expires) || preg_match('/^\d{1,12}$/', $expires) !== 1 || !is_string($signature)) {
            throw HttpException::forbidden('Lien de photo invalide.');
        }
        $batchId = ApiRequest::attribute($request, 'id');
        $media = $this->photos->read($principal->tenantId, $establishmentId, $batchId, (int) $expires, $signature);
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            'public_schools.pupils.register_photo_read',
            'pub_registration_batch',
            $batchId,
            null,
            ['establishment_id' => $establishmentId],
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );

        return new Response(200, ['Content-Type' => $media->mime, 'Cache-Control' => 'private, no-store'], Stream::create($media->content));
    }
}
