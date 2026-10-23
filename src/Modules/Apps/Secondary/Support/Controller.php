<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;

/**
 * Base des contrôleurs Secondary : contexte (personne, établissement), réponses JSON, lecture d'une ligne de l'établissement
 * (404 sinon). Les écritures contrôlent permission et périmètre par Access ; la porte du droit d'usage (WriteGate) est déjà
 * passée (SecondaryRoutes / GatedAction).
 */
abstract class Controller
{
    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly Access $access,
        protected readonly Store $store,
    ) {
    }

    /** @return array{0: Principal, 1: string} */
    protected function context(ServerRequestInterface $request): array
    {
        return [Access::principal($request), Access::establishmentId($request)];
    }

    protected static function id(ServerRequestInterface $request, string $name = 'id'): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => $data], $status);
    }

    /** @param array<string, mixed> $page */
    protected function page(array $page): ResponseInterface
    {
        return $this->responder->json($page);
    }
}
