<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Sync\Application\BatchService;
use Scholaris\Modules\Socle\Sync\Application\SyncAccess;
use Scholaris\Modules\Socle\Sync\Infrastructure\BatchRepository;

/**
 * Lots hors ligne. `POST /sync/batches` : corps gzip (Content-Encoding: gzip), en-têtes X-Device-Id, X-Batch-Signature
 * (HMAC-SHA256, voir BatchSignature) et Idempotency-Key = identifiant du lot. 200 si le lot est traité, 202 s'il est en file.
 */
final class BatchController
{
    private const BATCH_ID = '/^[A-Za-z0-9_\-:.]{8,128}$/D';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly SyncAccess $access,
        private readonly BatchService $batches,
    ) {
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $this->access->require($principal, $this->context->establishmentId(), SyncAccess::SUBMIT);

        $batchId = trim($request->getHeaderLine('Idempotency-Key'));
        if (preg_match(self::BATCH_ID, $batchId) !== 1) {
            throw HttpException::badRequest('L\'en-tête Idempotency-Key (identifiant du lot) est obligatoire : 8 à 128 caractères (lettres, chiffres, _ - : .).');
        }
        if (strtolower(trim($request->getHeaderLine('Content-Encoding'))) !== 'gzip') {
            throw new HttpException(415, 'Type de contenu non pris en charge', 'Le lot doit être compressé en gzip (Content-Encoding: gzip).', 'https://api.scholaris.cm/errors/unsupported-media-type');
        }

        $view = $this->batches->submit(
            $principal,
            $this->context->establishmentId(),
            trim($request->getHeaderLine('X-Device-Id')),
            $batchId,
            $request->getHeaderLine('X-Batch-Signature'),
            (string) $request->getBody(),
        );

        return $this->responder->json(['data' => $view], $view['status'] === BatchRepository::PROCESSED ? 200 : 202);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $this->access->require($principal, $this->context->establishmentId(), SyncAccess::SUBMIT);

        return $this->responder->json(['data' => $this->batches->get($principal->tenantId, $this->context->establishmentId(), $this->context->routeParam($request, 'id'))]);
    }
}
