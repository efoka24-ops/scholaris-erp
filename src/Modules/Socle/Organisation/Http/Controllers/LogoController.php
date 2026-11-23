<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Application\LogoService;
use Scholaris\Modules\Socle\Organisation\Domain\LogoProcessor;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Infrastructure\EstablishmentMapper;

/** Logo de l'établissement : lecture (organisation.read), téléversement, remplacement et suppression (organisation.manage), journalisés. */
final class LogoController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly OrganisationPolicy $policy,
        private readonly LogoService $logos,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->context->establishment((string) $request->getAttribute('id'));
        $this->policy->authorizeRead($this->context->principal($request), (string) $row['id']);
        $logo = $this->logos->read($this->context->tenantId(), (string) $row['id']) ?? throw HttpException::notFound('Cet établissement n\'a pas de logo.');

        return new Response(200, [
            'Content-Type' => $logo->mime,
            'Content-Length' => (string) strlen($logo->content),
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], Stream::create($logo->content));
    }

    /** Envoi multipart (champ `file`, PNG ou JPEG, 1 Mo au plus). Un logo existant est remplacé. */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $before = $this->context->establishment((string) $request->getAttribute('id'));
        $this->policy->authorizeManage($this->context->principal($request), (string) $before['id']);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['file' => ['Fichier image attendu (champ « file », PNG ou JPEG, 1 Mo au plus).']]);
        }
        if (($file->getSize() ?? 0) > LogoProcessor::MAX_BYTES) {
            throw HttpException::validation(['file' => ['Le logo doit peser 1 Mo au plus.']]);
        }

        $branding = $this->logos->replace($this->context->tenantId(), (string) $before['id'], (string) $file->getStream());
        $this->context->audit(
            $request,
            'establishment.logo_updated',
            'establishment',
            (string) $before['id'],
            ['logo' => EstablishmentMapper::decode((string) $before['branding'])['logo'] ?? null],
            ['logo' => $branding['logo'] ?? null],
        );

        return $this->responder->json(['data' => $branding]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $before = $this->context->establishment((string) $request->getAttribute('id'));
        $this->policy->authorizeManage($this->context->principal($request), (string) $before['id']);

        $branding = $this->logos->remove($this->context->tenantId(), (string) $before['id']);
        $previous = EstablishmentMapper::decode((string) $before['branding'])['logo'] ?? null;
        if ($previous !== null) {
            $this->context->audit($request, 'establishment.logo_removed', 'establishment', (string) $before['id'], ['logo' => $previous], ['logo' => null]);
        }

        return $this->responder->json(['data' => $branding]);
    }
}
