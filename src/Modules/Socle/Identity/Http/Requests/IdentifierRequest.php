<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** Demande de code (activation ou « mot de passe oublié ») : identifiant et canal souhaité (sms ou email). */
final class IdentifierRequest
{
    private function __construct(public readonly Identifier $identifier, public readonly ?string $channel)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = Body::of($request);
        $identifier = Identifier::fromInput($body->string('identifier') ?? '');
        $channel = $body->string('channel');
        $errors = [];
        if (!$identifier->valid) {
            $errors['identifier'] = ['Saisissez un numéro de téléphone (+237…) ou une adresse e-mail valide.'];
        }
        if ($channel !== null && !in_array($channel, ['sms', 'email'], true)) {
            $errors['channel'] = ['Le canal doit être « sms » ou « email ».'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return new self($identifier, $channel);
    }
}
