<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

use DateTimeImmutable;

/** Validation de l'identité d'une personne, commune à l'API et à l'import CSV. */
final class PersonAttributes
{
    public const FIELDS = ['first_name', 'last_name', 'birth_date', 'birth_place', 'gender', 'nationality', 'phone', 'email', 'locale', 'national_id'];

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} valeurs nettoyées et erreurs par champ
     */
    public static function validate(array $data, string $today, bool $partial = false): array
    {
        $values = [];
        $errors = [];
        foreach (['first_name' => 100, 'last_name' => 100] as $field => $max) {
            if (!array_key_exists($field, $data)) {
                if (!$partial) {
                    $errors[$field] = ['Ce champ est obligatoire.'];
                }
                continue;
            }
            $text = self::text($data[$field], $max);
            if ($text === null || $text === '') {
                $errors[$field] = ['Ce champ est obligatoire et ne peut pas dépasser ' . $max . ' caractères.'];
                continue;
            }
            $values[$field] = $text;
        }
        foreach (['birth_place' => 120, 'nationality' => 60] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $text = self::optional($data[$field], $max);
                if ($text === false) {
                    $errors[$field] = ['Texte attendu, ' . $max . ' caractères au plus.'];
                } else {
                    $values[$field] = $text;
                }
            }
        }
        if (array_key_exists('birth_date', $data)) {
            $date = self::optional($data['birth_date'], 10);
            if ($date === false || ($date !== null && !self::validDate($date, $today))) {
                $errors['birth_date'] = ['Date de naissance au format AAAA-MM-JJ, passée et réelle.'];
            } else {
                $values['birth_date'] = $date;
            }
        }
        if (array_key_exists('gender', $data)) {
            $gender = self::optional($data['gender'], 10);
            if ($gender === false || ($gender !== null && !in_array($gender, ['male', 'female'], true))) {
                $errors['gender'] = ['Valeur attendue : male ou female.'];
            } else {
                $values['gender'] = $gender;
            }
        }
        if (array_key_exists('phone', $data)) {
            $phone = self::optional($data['phone'], 25);
            $clean = is_string($phone) ? preg_replace('/[\s.\-()]/', '', $phone) : $phone;
            if ($clean === false || (is_string($clean) && preg_match('/^\+?[0-9]{8,15}$/', $clean) !== 1)) {
                $errors['phone'] = ['Numéro de téléphone invalide (8 à 15 chiffres, « + » facultatif).'];
            } else {
                $values['phone'] = $clean;
            }
        }
        if (array_key_exists('email', $data)) {
            $email = self::optional($data['email'], 190);
            if ($email === false || (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $errors['email'] = ['Adresse e-mail invalide.'];
            } else {
                $values['email'] = is_string($email) ? mb_strtolower($email) : null;
            }
        }
        if (array_key_exists('locale', $data)) {
            if (!is_string($data['locale']) || !in_array($data['locale'], ['fr', 'en'], true)) {
                $errors['locale'] = ['Langue attendue : fr ou en.'];
            } else {
                $values['locale'] = $data['locale'];
            }
        }
        if (array_key_exists('national_id', $data)) {
            $national = self::optional($data['national_id'], 30);
            if ($national === false || (is_string($national) && preg_match('/^[A-Za-z0-9-]{6,30}$/', $national) !== 1)) {
                $errors['national_id'] = ['Matricule national invalide (6 à 30 lettres, chiffres ou tirets).'];
            } else {
                $values['national_id'] = $national;
            }
        }

        return [$values, $errors];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return mb_strlen($value) > $max ? null : $value;
    }

    /** @return string|false|null null = absent ou vide ; false = valeur invalide */
    private static function optional(mixed $value, int $max): string|false|null
    {
        if ($value === null) {
            return null;
        }
        $text = self::text($value, $max);
        if ($text === null) {
            return false;
        }

        return $text === '' ? null : $text;
    }

    private static function validDate(string $value, string $today): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value && $value <= $today && $value >= '1900-01-01';
    }
}
