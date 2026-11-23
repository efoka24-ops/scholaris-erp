<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** POST /auth/reconfirm : le mot de passe de la personne connectée. */
final class ReconfirmRequest
{
    private function __construct(public readonly string $password)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $password = Body::of($request)->string('password') ?? '';
        if ($password === '' || mb_strlen($password) > 1024) {
            throw HttpException::validation(['password' => ['Saisissez votre mot de passe.']]);
        }

        return new self($password);
    }
}
