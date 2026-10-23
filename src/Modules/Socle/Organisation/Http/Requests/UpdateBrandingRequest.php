<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Organisation\Domain\AccentColor;

/**
 * Identité visuelle : couleur d'accent (#RRGGBB, contraste d'au moins 4,5:1 avec du texte noir), logo, en-têtes,
 * signataires et numéros d'autorisation. Le logo est un chemin logique de stockage privé, propre au client et à l'établissement.
 */
final class UpdateBrandingRequest extends ValidatedRequest
{
    public const LOGO_MAX_BYTES = 1048576;
    private const LOGO_TYPES = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
    ];

    private string $logoPrefix = '';

    public function forEstablishment(string $tenantId, string $establishmentId): self
    {
        $clone = clone $this;
        $clone->logoPrefix = sprintf('tenants/%s/establishments/%s/logo/', $tenantId, $establishmentId);

        return $clone;
    }

    protected function fields(): array
    {
        return [
            'accent' => Field::custom(static fn (mixed $value): string => AccentColor::parse(is_string($value) ? $value : '')->hex()),
            'logo' => Field::custom(fn (mixed $value): array => $this->logo($value))->nullable(),
            'initial' => Field::string(2),
            'headers' => Field::stringList(6, 200),
            'signatories' => Field::custom(static function (mixed $value): array {
                if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
                    throw new InvalidArgumentException('Ce champ doit être une liste de 10 signataires au plus.');
                }
                $signatories = [];
                foreach ($value as $item) {
                    if (!is_array($item) || array_diff(array_keys($item), ['name', 'title']) !== []) {
                        throw new InvalidArgumentException('Chaque signataire est décrit par « name » et « title ».');
                    }
                    $signatories[] = [
                        'name' => Field::string(120)->parse($item['name'] ?? null),
                        'title' => Field::string(120)->parse($item['title'] ?? null),
                    ];
                }

                return $signatories;
            }),
            'authorization_numbers' => Field::stringList(20, 80),
        ];
    }

    /** @return array{path: string, mime: string, size: int} */
    private function logo(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['path', 'mime', 'size']) !== []) {
            throw new InvalidArgumentException('Le logo est décrit par « path », « mime » et « size ».');
        }
        $mime = (string) Field::enum(array_keys(self::LOGO_TYPES))->parse($value['mime'] ?? null);
        $size = (int) Field::int(1, self::LOGO_MAX_BYTES)->parse($value['size'] ?? null);
        $path = (string) Field::string(255)->parse($value['path'] ?? null);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $valid = $this->logoPrefix !== ''
            && str_starts_with($path, $this->logoPrefix)
            && preg_match('#^[a-z0-9][a-z0-9_./-]*$#i', $path) === 1
            && !str_contains($path, '..')
            && !str_contains($path, '//')
            && in_array($extension, self::LOGO_TYPES[$mime], true);
        if (!$valid) {
            throw new InvalidArgumentException('Le chemin du logo doit être situé sous « ' . $this->logoPrefix . ' » et son extension doit correspondre au type de fichier.');
        }

        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }
}
