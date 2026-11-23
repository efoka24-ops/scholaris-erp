<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Domain;

use InvalidArgumentException;

/**
 * Format du matricule (RG-INS-1), paramétrable par `higher.enrolment.matricule_pattern`. Jetons : {AA} (année sur 2 chiffres),
 * {AAAA}, {ECOLE} (code de l'école), {FILIERE} (code de la filière) et UN SEUL numéro d'ordre {N3} à {N6}.
 * Défaut : `{AA}{ECOLE}-{FILIERE}-{N4}` => 26EC-CF-0042. La séquence est propre à la partie hors numéro d'ordre (année, école,
 * filière) : un numéro n'est jamais réattribué.
 */
final class MatriculeFormat
{
    public const DEFAULT = '{AA}{ECOLE}-{FILIERE}-{N4}';

    private readonly int $digits;

    public function __construct(public readonly string $pattern = self::DEFAULT)
    {
        if (preg_match_all('/\{N([3-6])\}/', $pattern, $m) !== 1 || preg_match('/^[A-Z0-9{}\-\/_.]{3,40}$/', $pattern) !== 1) {
            throw new InvalidArgumentException('Format de matricule invalide : un seul numéro d\'ordre {N3} à {N6} et des jetons {AA}, {AAAA}, {ECOLE}, {FILIERE}.');
        }
        $this->digits = (int) $m[1][0];
        $rest = (string) preg_replace('/\{(AA|AAAA|ECOLE|FILIERE|N[3-6])\}/', '', $pattern);
        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            throw new InvalidArgumentException('Jeton inconnu dans le format de matricule : ' . $pattern);
        }
    }

    /** Clé de séquence : le matricule sans son numéro d'ordre (ex. « 26EC-CF- »). */
    public function sequenceKey(int $year, string $schoolCode, string $programCode): string
    {
        return (string) preg_replace('/\{N[3-6]\}/', '#', $this->prefix($year, $schoolCode, $programCode));
    }

    public function format(int $year, string $schoolCode, string $programCode, int $number): string
    {
        if ($number < 1 || $number >= 10 ** $this->digits) {
            throw new InvalidArgumentException(sprintf('Numéro d\'ordre hors format (%d chiffres) : %d.', $this->digits, $number));
        }

        return (string) preg_replace('/\{N[3-6]\}/', str_pad((string) $number, $this->digits, '0', STR_PAD_LEFT), $this->prefix($year, $schoolCode, $programCode));
    }

    private function prefix(int $year, string $schoolCode, string $programCode): string
    {
        return strtr($this->pattern, [
            '{AAAA}' => sprintf('%04d', $year),
            '{AA}' => sprintf('%02d', $year % 100),
            '{ECOLE}' => strtoupper($schoolCode),
            '{FILIERE}' => strtoupper($programCode),
        ]);
    }
}
