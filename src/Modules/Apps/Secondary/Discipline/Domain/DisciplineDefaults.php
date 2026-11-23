<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Domain;

/**
 * Valeurs et listes de la discipline du secondaire (D12). Aucun seuil ici : le barème est le règlement de l'établissement
 * (`discipline_scale`, RG-PRE-1) ; l'exemple du CDC est celui de Councils (contrat ConductRules), jamais appliqué d'office.
 */
final class DisciplineDefaults
{
    /**
     * Durée comptée pour une séance d'appel validée : l'appel du secondaire se fait par heure de cours (D13, Q30 : un créneau = une
     * heure de cours de l'emploi du temps), le cumul en heures est donc le nombre de séances d'absence.
     */
    public const HOURS_PER_SESSION = 1.0;

    /** Niveau de décision d'un palier (`level`) => droits qui permettent de décider (RG-PRE-2). Niveau inconnu : chef d'établissement. */
    public const LEVEL_PERMISSIONS = [
        'surveillant_general' => ['secondary.discipline.manage', 'secondary.discipline.validate'],
        'chef_etablissement' => ['secondary.discipline.validate'],
    ];
    public const DEFAULT_LEVEL_PERMISSIONS = ['secondary.discipline.validate'];

    /** Palier qui ne s'applique que par le conseil de discipline (RG-PRE-3) : seul le rejet motivé reste possible hors conseil. */
    public const COUNCIL_LEVEL = 'conseil_discipline';

    /** Décisions du conseil de discipline (codes ; la liste des sanctions intermédiaires reste celle du barème). */
    public const FINAL_EXCLUSION = 'final_exclusion';
    public const NO_SANCTION = 'no_sanction';

    /** Statut d'une proposition remplacée par un palier supérieur de la même période avant toute décision. */
    public const SUPERSEDED = 'superseded';

    /** Statuts d'un conseil de discipline. */
    public const CONVENED = 'convened';
    public const DECIDED = 'decided';

    /** Notifications aux familles (modèles amorcés par la migration 202610281602). */
    public const NOT03 = 'NOT-03';
    public const NOT06 = 'NOT-06';
    public const TEMPLATE_NOT03 = 'sec.not03.absence_threshold';
    public const TEMPLATE_NOT06 = 'sec.not06.discipline_council';

    private function __construct()
    {
    }
}
