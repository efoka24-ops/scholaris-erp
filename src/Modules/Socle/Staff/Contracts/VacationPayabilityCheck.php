<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/**
 * Condition de payabilité enfichable (D16), déclarée par une application pour ses règles propres (IPES : RG-ENS-1 séance
 * émargée, contre-signée et cahier de textes renseigné ; RG-ENS-4 heures au-delà du volume payées après accord écrit ;
 * RG-ENS-5 solde conditionné à la saisie complète des notes).
 *
 * Enregistrement : une entrée de conteneur dont la clé commence par REGISTRY_PREFIX (ex. `staff.vacation.payability_checks.higher`)
 * et dont la valeur est une liste d'instances ou de noms de classe (`[\DI\get(MonControle::class)]`). Les contrôles s'ajoutent à
 * ceux du socle ; aucun contrôle déclaré = comportement du socle inchangé.
 */
interface VacationPayabilityCheck
{
    public const REGISTRY_PREFIX = 'staff.vacation.payability_checks.';

    /**
     * Appelé avant la validation d'une heure (POST /vacation-hours/{id}/validate).
     *
     * @return list<string> motifs de refus ; vide = l'heure peut être validée
     */
    public function hourRefusals(PayableHour $hour, string $validatorId): array;

    /**
     * Appelé avant l'approbation d'un relevé mensuel (POST /vacation-statements/{id}/approve).
     *
     * @return list<string> motifs de refus ; vide = le relevé peut être approuvé
     */
    public function statementRefusals(PayableStatement $statement, string $approverId): array;
}
