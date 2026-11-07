<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** POST /auth/login-authenticator : identifiant + code à 6 chiffres de l'application d'authentification, option « Rester connecté ». */
final class AuthenticatorLoginRequest
{
    private function __construct(
        public readonly string $identifier,
        public readonly string $code,
        public readonly bool $remember,
        public readonly ?string $deviceId,
        public readonly bool $web,
    ) {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $errors = [];
        $identifier = trim($body->string('identifier') ?? '');
        $code = preg_replace('/\s+/', '', $body->string('code') ?? '') ?? '';
        if ($identifier === '' || mb_strlen($identifier) > 190) {
            $errors['identifier'] = ['Saisissez votre adresse e-mail ou votre numéro de téléphone.'];
        }
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $errors['code'] = ['Saisissez le code à 6 chiffres affiché par votre application d\'authentification.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($identifier, $code, $body->bool('remember'), $body->string('device_id'), $body->string('client') === 'web');
    }
}
