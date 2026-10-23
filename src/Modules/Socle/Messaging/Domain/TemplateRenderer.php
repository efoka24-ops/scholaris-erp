<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

/**
 * Remplacement de variables strictement borné : seules les variables de la liste blanche existent, la substitution
 * se fait en un seul passage (une valeur contenant « :autre » n'est jamais réinterprétée) et les valeurs sont nettoyées.
 */
final class TemplateRenderer
{
    public const PLACEHOLDER = '/:([a-z][a-z0-9_]*)/';
    private const MAX_VALUE_LENGTH = 200;

    /**
     * Variables autorisées dans les modèles. Les variables propres au destinataire (prénom, enfant, solde...)
     * sont fournies par la résolution de l'audience.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'receipt_number', 'amount', 'balance', 'due_date', 'payment_reference', 'first_name', 'last_name',
        'full_name', 'child_name', 'class_name', 'establishment_name', 'date', 'absence_date', 'link',
        'reference', 'school_year', 'due_on', 'label', 'offset',
        'subject', 'time', 'change_label', 'details',
        'period_label', 'exam_label', 'place',
    ];

    /** @return list<string> variables utilisées par le texte, sans doublon */
    public static function placeholders(string $body): array
    {
        preg_match_all(self::PLACEHOLDER, $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @throws TemplateException si le texte emploie une variable hors liste blanche */
    public static function assertBodyAllowed(string $body): void
    {
        $unknown = array_diff(self::placeholders($body), self::ALLOWED);
        if ($unknown !== []) {
            throw new TemplateException(TemplateException::VARIABLE_NOT_ALLOWED, 'Variable non autorisée : :' . implode(', :', $unknown) . '.');
        }
    }

    /**
     * @param array<string, scalar> $variables
     * @throws TemplateException
     */
    public static function assertVariablesAllowed(array $variables): void
    {
        $unknown = array_diff(array_map('strval', array_keys($variables)), self::ALLOWED);
        if ($unknown !== []) {
            throw new TemplateException(TemplateException::VARIABLE_NOT_ALLOWED, 'Variable non autorisée : ' . implode(', ', $unknown) . '.');
        }
    }

    /**
     * @param array<string, scalar> $variables
     * @throws TemplateException
     */
    public static function render(string $body, array $variables): string
    {
        self::assertBodyAllowed($body);

        return (string) preg_replace_callback(self::PLACEHOLDER, static function (array $m) use ($variables): string {
            if (!array_key_exists($m[1], $variables)) {
                throw new TemplateException(TemplateException::MISSING_VARIABLE, 'Variable manquante : :' . $m[1] . '.');
            }

            return self::clean((string) $variables[$m[1]]);
        }, $body);
    }

    private static function clean(string $value): string
    {
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}<>]+/u', ' ', $value);

        return trim(mb_substr($value, 0, self::MAX_VALUE_LENGTH));
    }
}
