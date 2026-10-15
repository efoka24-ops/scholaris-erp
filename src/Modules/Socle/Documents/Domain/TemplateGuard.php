<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

use Scholaris\Core\Http\HttpException;

/** Contrôle d'un modèle HTML : variables en liste blanche, aucun script, aucun gestionnaire d'événement, aucune URL exécutable. */
final class TemplateGuard
{
    /** Variables fournies par le moteur, utilisables dans tout modèle. */
    public const SYSTEM = ['number', 'verification_url', 'qr', 'holder_name', 'establishment_name', 'issued_on', 'year', 'signature', 'stamp'];

    public const PLACEHOLDER = '/\{\{\s*([^{}]*?)\s*\}\}/';

    /** @return list<string> noms de variables utilisés par le modèle */
    public static function placeholders(string $html): array
    {
        preg_match_all(self::PLACEHOLDER, $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param list<string> $declared variables déclarées par le type de document
     * @throws HttpException 422
     */
    public static function assertValid(string $html, array $declared): void
    {
        $problems = self::dangers($html);

        $allowed = array_merge(self::SYSTEM, $declared);
        $unknown = array_values(array_diff(self::placeholders($html), $allowed));
        if ($unknown !== []) {
            $problems[] = 'Variables non autorisées : ' . implode(', ', $unknown) . '. Variables permises : ' . implode(', ', $allowed) . '.';
        }

        if ($problems !== []) {
            throw HttpException::validation(['html' => $problems], 'Modèle refusé.');
        }
    }

    /** @return list<string> */
    public static function dangers(string $html): array
    {
        $problems = [];
        $flat = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (preg_match('/<\s*\/?\s*(script|iframe|frame|frameset|object|embed|applet|link|meta|base|form)\b/i', $html) === 1) {
            $problems[] = 'Balise interdite (script, iframe, object, embed, link, meta, base, form).';
        }
        if (preg_match('/[\s"\'\/]on[a-z]+\s*=/i', $html) === 1) {
            $problems[] = 'Attribut d\'événement interdit (onclick, onload, etc.).';
        }
        if (str_contains($flat, 'javascript:') || str_contains($flat, 'vbscript:') || str_contains($flat, 'data:text/html')) {
            $problems[] = 'URL exécutable interdite (javascript:, vbscript:, data:text/html).';
        }
        if (preg_match('/<\?|<%|\{%|expression\(|srcdoc\s*=/i', $html) === 1) {
            $problems[] = 'Code exécutable interdit (PHP, balises de gabarit serveur, expression CSS).';
        }

        return $problems;
    }
}
