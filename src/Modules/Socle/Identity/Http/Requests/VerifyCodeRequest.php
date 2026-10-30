<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** Vérification du code reçu : identifiant + code à 6 chiffres. */
final class VerifyCodeRequest
{
    private function __construct(public readonly Identifier $identifier, public readonly string $code)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $identifier = Identifier::fromInput($body->string('identifier') ?? '');
        $code = trim($body->string('code') ?? '');
        $errors = [];
        if (!$identifier->valid) {
            $errors['identifier'] = ['Saisissez un numéro de téléphone (+237…) ou une adresse e-mail valide.'];
        }
        if ($code === '') {
            $errors['code'] = ['Saisissez le code reçu.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($identifier, mb_substr($code, 0, 32));
    }
}
