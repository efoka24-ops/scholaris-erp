<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Http\Body;

/** Création d'une affectation de rôle ou d'une délégation (mêmes champs). */
final class AssignmentRequest
{
    /**
     * @return array{person_id: string, role: string, scope_type: string, scope_id: ?string, establishment_id: ?string, starts_at: ?string, ends_at: ?string}
     */
    public static function from(ServerRequestInterface $request): array
    {
        $body = Body::of($request);
        $errors = [];
        $personId = trim($body->string('person_id') ?? '');
        $role = trim($body->string('role') ?? '');
        $scopeType = trim($body->string('scope_type') ?? '');
        if ($personId === '') {
            $errors['person_id'] = ['La personne est obligatoire.'];
        }
        if ($role === '') {
            $errors['role'] = ['Le rôle est obligatoire.'];
        }
        if ($scopeType === '') {
            $errors['scope_type'] = ['Le périmètre est obligatoire.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'person_id' => $personId,
            'role' => $role,
            'scope_type' => $scopeType,
            'scope_id' => self::optional($body->string('scope_id')),
            'establishment_id' => self::optional($body->string('establishment_id')),
            'starts_at' => self::optional($body->string('starts_at')),
            'ends_at' => self::optional($body->string('ends_at')),
        ];
    }

    private static function optional(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
