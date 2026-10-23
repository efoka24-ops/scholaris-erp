<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Sync\Application\DeviceService;
use Scholaris\Modules\Socle\Sync\Application\SyncAccess;
use Scholaris\Modules\Socle\Sync\Http\Requests\DeviceRequest;

/** Appareils de synchronisation de l'établissement : inscription (clé rendue une seule fois), liste, révocation. */
final class DeviceController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly SyncAccess $access,
        private readonly DeviceService $devices,
    ) {
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $data = (new DeviceRequest())->fromHttp($request);
        $device = $this->devices->register($principal, $this->context->establishmentId(), $data['label']);

        return $this->responder->json(['data' => self::present($device) + ['key' => $device['key']]], 201)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $query = ListQuery::fromRequest($request);
        $rows = $this->devices->page($principal->tenantId, $this->context->establishmentId(), ListQuery::decodeCursor($query->cursor), $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }
        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json([
            'data' => array_map(self::present(...), $rows),
            'links' => $links,
            'meta' => ['next_cursor' => $next],
        ]);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $device = $this->devices->revoke($principal, $this->context->establishmentId(), $this->context->routeParam($request, 'id'));

        return $this->responder->json(['data' => self::present($device)]);
    }

    private function authorize(ServerRequestInterface $request): Principal
    {
        $principal = $this->context->principal($request);
        $this->access->require($principal, $this->context->establishmentId(), SyncAccess::MANAGE_DEVICES);

        return $principal;
    }

    /**
     * Jamais la clé ni son texte chiffré.
     *
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private static function present(array $device): array
    {
        return [
            'id' => (string) $device['id'],
            'establishment_id' => (string) $device['establishment_id'],
            'label' => (string) $device['label'],
            'created_at' => (string) $device['created_at'],
            'last_seen_at' => $device['last_seen_at'] === null ? null : (string) $device['last_seen_at'],
            'revoked_at' => $device['revoked_at'] === null ? null : (string) $device['revoked_at'],
            'locked_at' => ($device['locked_at'] ?? null) === null ? null : (string) $device['locked_at'],
        ];
    }
}
