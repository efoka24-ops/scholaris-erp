<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Documents\Application\VerificationService;

/** GET /public/verify/{token} : route publique (limitée en débit par le noyau), sans note ni donnée personnelle superflue. */
final class VerifyController
{
    public function __construct(private readonly JsonResponder $responder, private readonly VerificationService $verification)
    {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $data = $this->verification->verify((string) $request->getAttribute('token'), is_string($remote) ? $remote : null);

        return $this->responder->json(['data' => $data])->withHeader('Cache-Control', 'no-store');
    }
}
