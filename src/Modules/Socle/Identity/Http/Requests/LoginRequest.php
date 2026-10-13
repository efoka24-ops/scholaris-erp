<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** POST /auth/login : « Numéro de téléphone ou e-mail » + mot de passe, option « Rester connecté ». */
final class LoginRequest
{
    private function __construct(
        public readonly string $identifier,
        public readonly string $password,
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
        $password = $body->string('password') ?? '';
        if ($identifier === '' || mb_strlen($identifier) > 190) {
            $errors['identifier'] = ['Saisissez votre numéro de téléphone ou votre adresse e-mail.'];
        }
        if ($password === '' || mb_strlen($password) > 1024) {
            $errors['password'] = ['Saisissez votre mot de passe.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($identifier, $password, $body->bool('remember'), $body->string('device_id'), $body->string('client') === 'web');
    }
}
