<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Registry\Http\Requests;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** Entrées whitelistes du registre ; les clés supplémentaires ne sont jamais propagées au stockage. */
final class RegistryInput
{
    /** @return array{name: string, school_code: string, territory_id: string} */
    public static function profile(ServerRequestInterface $request): array
    {
        $data = self::body($request);
        $errors = [];
        foreach (['name' => 160, 'school_code' => 80, 'territory_id' => 26] as $field => $length) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '' || mb_strlen($data[$field]) > $length) {
                $errors[$field][] = 'Valeur requise et limitée à ' . $length . ' caractères.';
            }
        }
        if (is_string($data['territory_id'] ?? null) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $data['territory_id']) !== 1) {
            $errors['territory_id'][] = 'Identifiant ULID invalide.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return ['name' => trim($data['name']), 'school_code' => trim($data['school_code']), 'territory_id' => $data['territory_id']];
    }

    /** @return array<string, mixed> */
    public static function snapshot(ServerRequestInterface $request): array
    {
        return self::body($request);
    }

    /** @return array{reason: string, data: array<string, mixed>} */
    public static function correction(ServerRequestInterface $request): array
    {
        $data = self::body($request);
        $reason = $data['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Motif requis (500 caractères maximum).']]);
        }
        unset($data['reason']);

        return ['reason' => trim($reason), 'data' => $data];
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        try {
            $decoded = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('Le corps doit être un objet JSON valide.');
        }
        if (!is_array($decoded)) {
            throw HttpException::badRequest('Le corps doit être un objet JSON.');
        }

        return $decoded;
    }
}