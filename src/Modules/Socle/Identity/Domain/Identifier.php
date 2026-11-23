<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/**
 * Identifiant de connexion saisi par la personne : numéro de téléphone (+237…) ou adresse e-mail.
 * Un seul champ côté interface ; le serveur reconnaît le type. Une saisie invalide reste utilisable
 * (valeur nettoyée, `valid` faux) pour que les réponses ne distinguent jamais « inconnu » de « incorrect ».
 */
final class Identifier
{
    private function __construct(
        public readonly string $value,
        public readonly bool $isEmail,
        public readonly bool $valid,
    ) {
    }

    public static function fromInput(string $raw): self
    {
        $raw = trim($raw);
        if (str_contains($raw, '@')) {
            $email = mb_strtolower($raw);

            return new self($email, true, filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 190);
        }

        $phone = self::normalizePhone($raw);

        return $phone === null ? new self(mb_strtolower($raw), false, false) : new self($phone, false, true);
    }

    /** Forme canonique « +237XXXXXXXXX » ; null si le numéro n'est pas plausible. */
    public static function normalizePhone(string $raw): ?string
    {
        $clean = (string) preg_replace('/[\s().\-]/', '', trim($raw));
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }
        if (preg_match('/^[26]\d{8}$/', $clean) === 1) {
            return '+237' . $clean;
        }
        if (preg_match('/^237[26]\d{8}$/', $clean) === 1) {
            return '+' . $clean;
        }

        return preg_match('/^\+\d{8,15}$/', $clean) === 1 ? $clean : null;
    }

    public static function normalizeEmail(string $raw): ?string
    {
        $email = mb_strtolower(trim($raw));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 190 ? $email : null;
    }

    /** Clé stable (hachée) pour les compteurs d'essais : n'expose jamais l'identifiant en base. */
    public function key(): string
    {
        return hash('sha256', 'id|' . $this->value);
    }

    /** « +237 6•• •• 34 56 » ou « j•••@exemple.cm ». */
    public function mask(): string
    {
        return $this->isEmail ? self::maskEmail($this->value) : self::maskPhone($this->value);
    }

    public static function maskPhone(string $phone): string
    {
        if (preg_match('/^\+237(\d)\d{4}(\d{2})(\d{2})$/', $phone, $m) === 1) {
            return sprintf('+237 %s•• •• %s %s', $m[1], $m[2], $m[3]);
        }
        $digits = substr($phone, -4);

        return strlen($phone) > 4 ? str_repeat('•', strlen($phone) - 4) . $digits : '••••';
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return '•••';
        }

        return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }
}
