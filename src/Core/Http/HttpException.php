<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use RuntimeException;

/**
 * Erreur métier ou de protocole convertie en réponse RFC 9457 (Problem Details).
 */
class HttpException extends RuntimeException
{
    private const TYPE_BASE = 'https://api.scholaris.cm/errors/';

    /**
     * @param array<string, list<string>> $errors erreurs de champ
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        string $detail = '',
        public readonly string $type = 'about:blank',
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail !== '' ? $detail : $title, $status);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'Requête invalide', $detail, self::TYPE_BASE . 'bad-request');
    }

    public static function unauthorized(string $detail = 'Authentification requise.'): self
    {
        return new self(401, 'Non authentifié', $detail, self::TYPE_BASE . 'unauthorized', headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function notFound(string $detail = 'Ressource introuvable.'): self
    {
        return new self(404, 'Introuvable', $detail, self::TYPE_BASE . 'not-found');
    }

    public static function forbidden(string $detail = 'Accès refusé.'): self
    {
        return new self(403, 'Accès refusé', $detail, self::TYPE_BASE . 'forbidden');
    }

    public static function conflict(string $detail): self
    {
        return new self(409, 'Conflit', $detail, self::TYPE_BASE . 'conflict');
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors, string $detail = 'Données invalides.'): self
    {
        return new self(422, 'Données invalides', $detail, self::TYPE_BASE . 'validation', $errors);
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(
            429,
            'Trop de requêtes',
            'Limite de débit atteinte, réessayez plus tard.',
            self::TYPE_BASE . 'rate-limit',
            headers: ['Retry-After' => (string) $retryAfter],
        );
    }
}
