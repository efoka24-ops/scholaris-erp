<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Domain;

/**
 * RG-STG-2 : jury d'au moins N membres (paramètre `higher.defense.jury_min`, 3 par défaut), un seul président, l'encadreur
 * académique siège (rôle `supervisor`) et ne préside pas ; une personne ne siège qu'une fois.
 */
final class JuryRules
{
    public const PRESIDENT = 'president';
    public const SUPERVISOR = 'supervisor';
    public const MEMBER = 'member';
    public const ROLES = [self::PRESIDENT, self::SUPERVISOR, self::MEMBER];
    public const DEFAULT_MINIMUM = 3;

    private function __construct()
    {
    }

    /**
     * @param list<array{person_id: string, jury_role: string}> $members
     * @return list<string> motifs de refus (vide = jury conforme)
     */
    public static function violations(array $members, string $supervisorPersonId, int $minimum): array
    {
        $errors = [];
        if (count($members) < $minimum) {
            $errors[] = sprintf('Le jury compte %d membre(s) : %d au moins sont exigés (RG-STG-2).', count($members), $minimum);
        }
        $people = array_column($members, 'person_id');
        if (count(array_unique($people)) !== count($people)) {
            $errors[] = 'Une même personne figure deux fois dans le jury.';
        }
        $presidents = array_values(array_filter($members, static fn (array $m): bool => $m['jury_role'] === self::PRESIDENT));
        if (count($presidents) !== 1) {
            $errors[] = 'Le jury doit avoir un et un seul président.';
        }
        $supervisor = array_values(array_filter($members, static fn (array $m): bool => $m['person_id'] === $supervisorPersonId));
        if ($supervisor === []) {
            $errors[] = 'L\'encadreur académique doit siéger dans le jury (RG-STG-2).';
        } elseif ($supervisor[0]['jury_role'] === self::PRESIDENT) {
            $errors[] = 'L\'encadreur académique ne peut pas présider le jury (RG-STG-2).';
        }
        foreach ($members as $member) {
            if ($member['jury_role'] === self::SUPERVISOR && $member['person_id'] !== $supervisorPersonId) {
                $errors[] = 'Seul l\'encadreur académique de la convention siège comme encadreur.';
                break;
            }
        }

        return $errors;
    }
}
