<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** Nouveau mot de passe : ticket obtenu après vérification du code + mot de passe (la règle des 10 caractères est appliquée par le service). */
final class SetPasswordRequest
{
    private function __construct(public readonly string $ticket, public readonly string $password)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $ticket = $body->string('ticket') ?? '';
        $password = $body->string('password') ?? '';
        $errors = [];
        if ($ticket === '') {
            $errors['ticket'] = ['Le ticket de vérification est obligatoire.'];
        }
        if ($password === '') {
            $errors['password'] = ['Choisissez un mot de passe.'];
        }
        $confirmation = $body->string('password_confirmation');
        if ($confirmation !== null && $confirmation !== $password) {
            $errors['password_confirmation'] = ['La confirmation ne correspond pas au mot de passe.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($ticket, $password);
    }
}
