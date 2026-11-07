<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;

/**
 * Base des contrôleurs de la Maternelle privée : contexte (personne, établissement), réponses JSON. La porte du droit d'usage
 * (WriteGate) est déjà passée (NurseryRoutes / GatedAction) ; les contrôleurs vérifient permission et périmètre par Access.
 */
abstract class Controller
{
    public function __construct(protected readonly JsonResponder $responder, protected readonly Access $access)
    {
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
