<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/**
 * Règles de mot de passe : 10 caractères minimum et refus des mots de passe compromis.
 * La liste des mots de passe courants est un fichier local (Resources/common-passwords.txt) : aucun appel réseau.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    /** @var array<string, true>|null */
    private ?array $common = null;

    public function __construct(private readonly string $commonPasswordsFile = __DIR__ . '/../Resources/common-passwords.txt')
    {
    }

    /**
     * @param list<string> $personalValues valeurs propres à la personne (téléphone, e-mail, nom) interdites dans le mot de passe
     * @return list<string> messages d'erreur (vide si le mot de passe est accepté)
     */
    public function violations(string $password, array $personalValues = []): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = 'Le mot de passe doit contenir au moins ' . self::MIN_LENGTH . ' caractères.';
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = 'Le mot de passe ne doit pas dépasser ' . self::MAX_LENGTH . ' caractères.';
        }
        if ($length >= self::MIN_LENGTH && $this->isCompromised($password)) {
            $errors[] = 'Ce mot de passe est trop courant ou a déjà été compromis : choisissez-en un autre.';
        }
        $lower = mb_strtolower($password);
        foreach ($personalValues as $value) {
            $value = mb_strtolower(trim($value));
            if (mb_strlen($value) >= 4 && str_contains($lower, $value)) {
                $errors[] = 'Le mot de passe ne doit pas contenir votre identifiant ou votre nom.';
                break;
            }
        }

        return $errors;
    }

    public function isCompromised(string $password): bool
    {
        $common = $this->common();
        $lower = mb_strtolower($password);
        $candidates = [
            $lower,
            (string) preg_replace('/[\d!?.*#$@_\-]+$/u', '', $lower),
            strtr($lower, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && isset($common[$candidate])) {
                return true;
            }
        }

        // Une même suite répétée ou un caractère unique répété ne résiste à aucune attaque.
        return preg_match('/^(.{1,3})\1+$/us', $lower) === 1;
    }

    /** @return array<string, true> */
    private function common(): array
    {
        if ($this->common !== null) {
            return $this->common;
        }
        $this->common = [];
        $lines = is_file($this->commonPasswordsFile) ? file($this->commonPasswordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach ($lines === false ? [] : $lines as $line) {
            $line = mb_strtolower(trim($line));
            if ($line !== '' && !str_starts_with($line, '#')) {
                $this->common[$line] = true;
            }
        }

        return $this->common;
    }
}
