<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** Code de double authentification : TOTP, SMS (`method`) ou code de secours ; option « faire confiance à cet appareil 30 jours ». */
final class CodeRequest
{
    private function __construct(
        public readonly string $code,
        public readonly string $method,
        public readonly bool $trustDevice,
    ) {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $code = trim($body->string('code') ?? '');
        $method = $body->string('method') ?? 'totp';
        $errors = [];
        if ($code === '' || mb_strlen($code) > 32) {
            $errors['code'] = ['Saisissez votre code.'];
        }
        if (!in_array($method, ['totp', 'sms'], true)) {
            $errors['method'] = ['La méthode doit être « totp » ou « sms ».'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($code, $method, $body->bool('trust_device'));
    }
}
