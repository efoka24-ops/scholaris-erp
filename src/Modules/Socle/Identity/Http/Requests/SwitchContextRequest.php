<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** Changement de contexte : identifiant d'établissement (ou « group ») et rôle souhaité. */
final class SwitchContextRequest
{
    private function __construct(public readonly string $contextId, public readonly ?string $role)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $id = trim($body->string('establishment_id') ?? '');
        if ($id === '' || mb_strlen($id) > 40) {
            throw HttpException::validation(['establishment_id' => ['Indiquez l\'établissement (ou « group » pour tout le groupe).']]);
        }
        $role = $body->string('role');

        return new self($id, $role === null || $role === '' ? null : $role);
    }
}
