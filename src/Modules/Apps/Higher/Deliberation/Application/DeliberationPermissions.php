<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

/** Permissions de la délibération (RoleMatrix « deliberations », rôles R02 à R21 ; migration des rôles Higher). */
final class DeliberationPermissions
{
    /** Lecture : R01, R02, R03, R04, R05, R07, R21. */
    public const READ = 'higher.deliberation.read';
    /** Préparation, simulation, rattrapage, publication, relevés : R05, R07. */
    public const PREPARE = 'higher.deliberation.prepare';
    /** Présidence du jury, points de jury, décisions motivées : R04. */
    public const PRESIDE = 'higher.deliberation.preside';
    /** Signature du PV : R21 (permission du socle). */
    public const SIGN = 'assessment.deliberation.sign';
    /** Réouverture d'une délibération signée : R03 seul (EX-DEL-08). */
    public const REOPEN = 'assessment.reopen';
    /** Portail du membre de jury : R21. */
    public const JURY_PORTAL = 'higher.jury.portal';
    /** Résultats publiés de l'étudiant : R12. */
    public const SELF = 'self.read';

    /** Rôle R03 (seul habilité à rouvrir) et rôle R02 (alerte à la réouverture). */
    public const ROLE_ACADEMIC_DIRECTOR = 'higher_dir_academique';
    public const ROLE_GENERAL_DIRECTOR = 'direction_groupe';

    /** Clés de modèles du Messenger (NOT-12, NOT-13, alerte au directeur général). */
    public const NOT12 = 'higher.not12.results_published';
    public const NOT13 = 'higher.not13.resit_convocation';
    public const REOPEN_ALERT = 'higher.deliberation_reopened';

    private function __construct()
    {
    }
}
