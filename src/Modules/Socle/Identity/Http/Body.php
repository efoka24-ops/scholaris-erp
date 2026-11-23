<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** Corps JSON d'une requête, lu avec des accesseurs typés (les valeurs de mauvais type sont ignorées). */
final class Body
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            $parsed = $request->getParsedBody();

            return new self(is_array($parsed) ? $parsed : []);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON valide.');
        }

        return new self($decoded);
    }

    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }
}
