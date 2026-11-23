<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** POST /auth/change-password : mot de passe actuel (ou provisoire), nouveau mot de passe, confirmation facultative. */
final class ChangePasswordRequest
{
    private function __construct(public readonly string $current, public readonly string $new)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $current = $body->string('current_password') ?? '';
        $new = $body->string('new_password') ?? '';
        $errors = [];
        if ($current === '' || mb_strlen($current) > 1024) {
            $errors['current_password'] = ['Saisissez votre mot de passe actuel.'];
        }
        if ($new === '' || mb_strlen($new) > 1024) {
            $errors['new_password'] = ['Choisissez un nouveau mot de passe.'];
        }
        $confirmation = $body->string('new_password_confirmation');
        if ($confirmation !== null && $confirmation !== $new) {
            $errors['new_password_confirmation'] = ['La confirmation ne correspond pas au nouveau mot de passe.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($current, $new);
    }
}
