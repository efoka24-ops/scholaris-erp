<?php

declare(strict_types=1);

namespace Scholaris\Core\Config;

/**
 * Configuration applicative : charge les fichiers config/*.php et lit les valeurs
 * par clé pointée (ex. "app.debug"). Les secrets viennent de l'environnement (.env).
 */
final class Config
{
    /** @param array<string, mixed> $items */
    private function __construct(private readonly array $items)
    {
    }

    public static function load(string $basePath): self
    {
        $items = [];
        foreach (glob($basePath . '/config/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }

        return new self($items);
    }

    /** @param array<string, mixed> $items */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
