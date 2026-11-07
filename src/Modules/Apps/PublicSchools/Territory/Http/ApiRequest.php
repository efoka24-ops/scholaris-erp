<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;

/** Lecture commune des requêtes des routes de l'école : principal authentifié, corps JSON, paramètres de chemin. */
final class ApiRequest
{
    private function __construct()
    {
    }

    public static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');

        return $principal instanceof Principal ? $principal : throw HttpException::unauthorized();
    }

    public static function establishment(ServerRequestInterface $request): string
    {
        return self::attribute($request, 'establishment_id');
    }

    public static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : throw HttpException::badRequest('Paramètre manquant : ' . $name . '.');
    }

    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return [];
        }
        $body = json_decode($raw, true);
        if (!is_array($body) || (array_is_list($body) && $body !== [])) {
            throw HttpException::validation(['body' => ['Objet JSON attendu.']]);
        }

        return $body;
    }

    public static function ip(ServerRequestInterface $request): ?string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : null;
    }

    public static function device(ServerRequestInterface $request): ?string
    {
        $agent = $request->getHeaderLine('User-Agent');

        return $agent === '' ? null : mb_substr($agent, 0, 255);
    }
}
