<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Sync\Application\DevicePinService;
use Scholaris\Modules\Socle\Sync\Application\SyncAccess;
use Scholaris\Modules\Socle\Sync\Http\Requests\PinRequest;

/** PP7 (T608) : code personnel de l'utilisateur connecté sur un appareil partagé de l'établissement. */
final class DevicePinController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly SyncAccess $access,
        private readonly DevicePinService $pins,
    ) {
    }

    public function setPin(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $deviceId = $this->context->routeParam($request, 'id');
        $this->pins->setPin($principal, $this->context->establishmentId(), $deviceId, (new PinRequest())->code($request));

        return $this->responder->json(['data' => ['device_id' => $deviceId, 'pin_set' => true]])->withHeader('Cache-Control', 'no-store');
    }

    public function unlock(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $deviceId = $this->context->routeParam($request, 'id');
        $result = $this->pins->unlock($principal, $this->context->establishmentId(), $deviceId, (new PinRequest())->code($request));

        return $this->responder->json(['data' => ['device_id' => $deviceId] + $result])->withHeader('Cache-Control', 'no-store');
    }

    private function authorize(ServerRequestInterface $request): Principal
    {
        $principal = $this->context->principal($request);
        $this->access->require($principal, $this->context->establishmentId(), SyncAccess::SUBMIT);

        return $principal;
    }
}
