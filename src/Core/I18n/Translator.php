<?php

declare(strict_types=1);

namespace Scholaris\Core\I18n;

/** Traductions fr/en lues dans lang/<locale>.php (clés plates). Repli sur le français, puis sur la clé. */
final class Translator
{
    public const SUPPORTED = ['fr', 'en'];
    public const DEFAULT = 'fr';

    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    public function __construct(private readonly string $langPath)
    {
    }

    /** @param array<string, scalar> $replace */
    public function translate(string $key, string $locale = self::DEFAULT, array $replace = []): string
    {
        $text = $this->catalogue($locale)[$key] ?? $this->catalogue(self::DEFAULT)[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /** Choisit la meilleure langue supportée à partir de l'en-tête Accept-Language. */
    public static function negotiate(string $acceptLanguage): string
    {
        $best = self::DEFAULT;
        $bestQuality = -1.0;
        foreach (explode(',', $acceptLanguage) as $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(substr(trim($pieces[0]), 0, 2));
            if (!in_array($tag, self::SUPPORTED, true)) {
                continue;
            }
            $quality = 1.0;
            if (isset($pieces[1]) && preg_match('/q=([0-9.]+)/', $pieces[1], $m) === 1) {
                $quality = (float) $m[1];
            }
            if ($quality > $bestQuality) {
                $best = $tag;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        if (!isset($this->catalogues[$locale])) {
            $file = $this->langPath . '/' . $locale . '.php';
            $this->catalogues[$locale] = is_file($file) ? (array) require $file : [];
        }

        return $this->catalogues[$locale];
    }
}
