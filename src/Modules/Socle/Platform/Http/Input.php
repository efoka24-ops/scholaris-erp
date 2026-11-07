<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Corps JSON validé strictement : champs inconnus refusés, chaînes nettoyées (sans caractères de contrôle ni chevrons),
 * longueurs bornées. Les erreurs sont cumulées puis levées en une seule réponse 422.
 */
final class Input
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param list<string> $allowed champs acceptés */
    public static function of(ServerRequestInterface $request, array $allowed): self
    {
        $raw = trim((string) $request->getBody());
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON valide.');
        }
        $input = new self($data);
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowed, true)) {
                $input->errors[(string) $field] = ['Champ non autorisé.'];
            }
        }

        return $input;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    /** Chaîne obligatoire (ou facultative si $required est faux : null si absente ou vide). */
    public function text(string $field, int $min, int $max, bool $required = true, ?string $pattern = null): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_string($value)) {
            $this->errors[$field][] = 'Texte attendu.';

            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (preg_match('/[\p{Cc}\p{Cf}<>]/u', $value) === 1) {
            $this->errors[$field][] = 'Caractères non autorisés.';

            return null;
        }
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $this->errors[$field][] = "Entre {$min} et {$max} caractères.";

            return null;
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $this->errors[$field][] = 'Format invalide.';

            return null;
        }

        return $value;
    }

    /** Texte libre multiligne (les retours à la ligne sont conservés). */
    public function longText(string $field, int $max, bool $required = false): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F<>]/u', $value) === 1 || preg_match('//u', $value) !== 1) {
            $this->errors[$field][] = 'Texte invalide.';

            return null;
        }
        $value = trim($value);
        if ($value === '' && $required) {
            $this->errors[$field][] = 'Champ obligatoire.';

            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->errors[$field][] = "Au plus {$max} caractères.";

            return null;
        }

        return $value === '' ? null : $value;
    }

    /** @param list<string> $choices */
    public function choice(string $field, array $choices, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || !in_array($value, $choices, true)) {
            $this->errors[$field][] = 'Valeur attendue : ' . implode(', ', $choices) . '.';

            return null;
        }

        return $value;
    }

    public function integer(string $field, int $min, int $max, bool $required = true): ?int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->errors[$field][] = "Entier entre {$min} et {$max} attendu.";

            return null;
        }

        return $value;
    }

    public function email(string $field, bool $required = true): ?string
    {
        $value = $this->text($field, 3, 190, $required);
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field][] = 'Adresse e-mail invalide.';

            return null;
        }

        return mb_strtolower($value);
    }

    /** Téléphone au format international (espaces, points et tirets retirés), 8 à 15 chiffres. */
    public function phone(string $field, bool $required = true): ?string
    {
        $value = $this->text($field, 8, 25, $required);
        if ($value === null) {
            return null;
        }
        $clean = (string) preg_replace('/[\s.\-()]/', '', $value);
        if (preg_match('/^\+?[0-9]{8,15}$/', $clean) !== 1) {
            $this->errors[$field][] = 'Numéro de téléphone invalide.';

            return null;
        }

        return $clean;
    }

    public function date(string $field, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || !\Scholaris\Modules\Socle\Platform\Domain\Calendar::isDate($value)) {
            $this->errors[$field][] = 'Date AAAA-MM-JJ attendue.';

            return null;
        }

        return $value;
    }

    public function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** @throws HttpException 422 si une erreur a été relevée */
    public function validate(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
