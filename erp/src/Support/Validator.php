<?php

declare(strict_types=1);

namespace Scholaris\Support;

use Scholaris\Http\Request;

/**
 * Validation des formulaires.
 *
 * Deliberement minimale : les regles couvrent ce dont l'application a besoin,
 * et rien de plus. Elle renvoie a la fois les erreurs et les valeurs nettoyees,
 * pour qu'un controleur n'ait jamais a relire $_POST apres validation.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $valid = [];

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Champ obligatoire.
     */
    public function required(string $field, string $label): self
    {
        $value = $this->request->string($field);

        if ($value === '') {
            $this->errors[$field] = "Le champ {$label} est obligatoire.";
        } else {
            $this->valid[$field] = $value;
        }

        return $this;
    }

    /** Champ facultatif : absent devient null plutot que chaine vide. */
    public function optional(string $field): self
    {
        $value = $this->request->string($field);
        $this->valid[$field] = $value === '' ? null : $value;

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->request->string($field);

        if ($value === '') {
            $this->errors[$field] = "Le champ {$label} est obligatoire.";
        } elseif (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "Le champ {$label} doit etre une adresse email valide.";
        } else {
            $this->valid[$field] = $value;
        }

        return $this;
    }

    /**
     * Valeur devant appartenir a une liste fermee.
     *
     * Remplace les enums MySQL : le schema stocke des VARCHAR pour rester
     * portable, la contrainte est donc appliquee ici.
     *
     * @param  list<string>  $allowed
     */
    public function in(string $field, string $label, array $allowed, bool $required = true): self
    {
        $value = $this->request->string($field);

        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "Le champ {$label} est obligatoire.";
            } else {
                $this->valid[$field] = null;
            }

            return $this;
        }

        if (! in_array($value, $allowed, true)) {
            $this->errors[$field] = "La valeur du champ {$label} n est pas autorisee.";

            return $this;
        }

        $this->valid[$field] = $value;

        return $this;
    }

    public function date(string $field, string $label, bool $required = true): self
    {
        $value = $this->request->string($field);

        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "Le champ {$label} est obligatoire.";
            } else {
                $this->valid[$field] = null;
            }

            return $this;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            $this->errors[$field] = "Le champ {$label} doit etre une date valide.";

            return $this;
        }

        $this->valid[$field] = date('Y-m-d', $timestamp);

        return $this;
    }

    public function integer(string $field, string $label, int $min = 0, ?int $max = null): self
    {
        $value = $this->request->string($field);

        if ($value === '' || ! is_numeric($value)) {
            $this->errors[$field] = "Le champ {$label} doit etre un nombre.";

            return $this;
        }

        $number = (int) $value;

        if ($number < $min || ($max !== null && $number > $max)) {
            $this->errors[$field] = "Le champ {$label} est hors des valeurs admises.";

            return $this;
        }

        $this->valid[$field] = $number;

        return $this;
    }

    public function decimal(string $field, string $label, float $min = 0): self
    {
        $value = str_replace(',', '.', $this->request->string($field));

        if ($value === '' || ! is_numeric($value)) {
            $this->errors[$field] = "Le champ {$label} doit etre un montant.";

            return $this;
        }

        if ((float) $value < $min) {
            $this->errors[$field] = "Le champ {$label} ne peut pas etre inferieur a {$min}.";

            return $this;
        }

        $this->valid[$field] = (float) $value;

        return $this;
    }

    public function minLength(string $field, string $label, int $length): self
    {
        $value = $this->request->string($field);

        if (mb_strlen($value) < $length) {
            $this->errors[$field] = "Le champ {$label} doit contenir au moins {$length} caracteres.";

            return $this;
        }

        $this->valid[$field] = $value;

        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;

        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Valeurs validees, limitees aux champs demandes.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function only(array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $this->valid)) {
                $result[$field] = $this->valid[$field];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->valid;
    }
}
