<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

/** Remplace {{variable}} en une seule passe : valeurs échappées en HTML, sauf les fragments sûrs produits par le moteur. */
final class TemplateRenderer
{
    /**
     * @param array<string, string> $values valeurs textuelles (échappées)
     * @param array<string, string> $safeHtml fragments HTML produits par le moteur (QR, signature, cachet)
     */
    public static function render(string $html, array $values, array $safeHtml = []): string
    {
        return (string) preg_replace_callback(TemplateGuard::PLACEHOLDER, static function (array $m) use ($values, $safeHtml): string {
            if (isset($safeHtml[$m[1]])) {
                return $safeHtml[$m[1]];
            }

            return htmlspecialchars($values[$m[1]] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html);
    }
}
