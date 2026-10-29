<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Policies;

/**
 * Matrice des rôles de l'application secondaire (spec 005 §2, CDC « Matrice des droits ») : 24 rôles créés UNE fois pour les 7
 * types d'établissement, préfixe `sec_`, sauf R09 qui est le rôle commun `enseignant` (décision du propriétaire). Un rôle n'est
 * proposé que si son module est actif (`module`, null = noyau) ; le libellé du chef d'établissement (proviseur ou principal) est
 * du vocabulaire du profil, pas un rôle de plus.
 *
 * Migration de données : database/migrations/202610281601_roles_secondary.sql (T808, SS9). `permissionsOf()` (ROLES + EXTRA) est
 * égal, rôle par rôle, aux droits qu'elle accorde (pour `enseignant` : ses seuls droits `secondary.*`, il garde ceux du socle).
 * tests/Architecture/SecondaryConventionsTest compare au fichier, `RolePermissionsTest` à la base migrée.
 */
final class RoleMatrix
{
    /** @var array<string, array{code: string, module: ?string, external: bool, permissions: list<string>}> */
    public const ROLES = [
        'R01' => ['code' => 'sec_promoteur', 'module' => null, 'external' => false, 'permissions' => ['secondary.dashboard.read', 'secondary.finance.read', 'secondary.fees.validate']],
        'R02' => ['code' => 'sec_reseau', 'module' => 'network_indicators', 'external' => false, 'permissions' => ['secondary.network.read']],
        'R03' => ['code' => 'sec_chef_etablissement', 'module' => null, 'external' => false, 'permissions' => ['secondary.settings.manage', 'secondary.reportcard.validate', 'secondary.council.preside', 'secondary.discipline.council.preside', 'secondary.document.sign']],
        'R04' => ['code' => 'sec_censeur', 'module' => null, 'external' => false, 'permissions' => ['secondary.timetable.manage', 'secondary.grades.validate', 'secondary.grades.lock', 'secondary.syllabus.visa', 'secondary.council.prepare']],
        'R05' => ['code' => 'sec_surveillant_general', 'module' => null, 'external' => false, 'permissions' => ['secondary.attendance.manage', 'secondary.discipline.manage', 'secondary.conduct.enter']],
        'R06' => ['code' => 'sec_chef_travaux', 'module' => 'workshops', 'external' => false, 'permissions' => ['secondary.workshops.manage', 'secondary.stock.manage', 'secondary.safety.manage', 'secondary.internships.manage']],
        'R07' => ['code' => 'sec_animateur_pedagogique', 'module' => null, 'external' => false, 'permissions' => ['secondary.syllabus.manage', 'secondary.commontests.manage']],
        'R08' => ['code' => 'sec_professeur_principal', 'module' => null, 'external' => false, 'permissions' => ['secondary.class.synthesis', 'secondary.appraisal.general']],
        'R09' => ['code' => 'enseignant', 'module' => null, 'external' => false, 'permissions' => ['secondary.attendance.take', 'secondary.lesson.log', 'secondary.grades.enter', 'secondary.appraisal.subject']],
        'R10' => ['code' => 'sec_conseiller_orientation', 'module' => 'orientation', 'external' => false, 'permissions' => ['secondary.orientation.manage']],
        'R11' => ['code' => 'sec_intendant', 'module' => null, 'external' => false, 'permissions' => ['secondary.billing.manage', 'secondary.fees.catalog']],
        'R12' => ['code' => 'sec_caissier', 'module' => null, 'external' => false, 'permissions' => ['secondary.billing.cash']],
        'R13' => ['code' => 'sec_scolarite', 'module' => null, 'external' => false, 'permissions' => ['secondary.enrol.manage', 'secondary.documents.issue', 'secondary.exams.lists']],
        'R14' => ['code' => 'sec_infirmier', 'module' => 'infirmary', 'external' => false, 'permissions' => ['secondary.infirmary.manage']],
        'R15' => ['code' => 'sec_responsable_services', 'module' => 'boarding', 'external' => false, 'permissions' => ['secondary.services.manage']],
        'R16' => ['code' => 'sec_bibliothecaire', 'module' => 'library', 'external' => false, 'permissions' => ['secondary.library.manage']],
        'R17' => ['code' => 'sec_eleve', 'module' => null, 'external' => false, 'permissions' => ['secondary.self.read']],
        'R18' => ['code' => 'sec_delegue_classe', 'module' => null, 'external' => false, 'permissions' => ['secondary.attendance.countersign']],
        'R19' => ['code' => 'sec_parent', 'module' => null, 'external' => false, 'permissions' => ['secondary.family.read']],
        'R20' => ['code' => 'sec_bureau_apee', 'module' => null, 'external' => false, 'permissions' => ['secondary.apee.read']],
        'R21' => ['code' => 'sec_inspecteur', 'module' => null, 'external' => true, 'permissions' => ['secondary.inspection.read']],
        'R22' => ['code' => 'sec_delegation', 'module' => null, 'external' => true, 'permissions' => ['secondary.territory.read']],
        'R23' => ['code' => 'sec_brigade_controle', 'module' => 'authorizations', 'external' => true, 'permissions' => ['secondary.control.read']],
        'R24' => ['code' => 'sec_admin_fonctionnel', 'module' => null, 'external' => false, 'permissions' => ['identity.manage', 'secondary.settings.manage', 'secondary.profile.manage', 'secondary.documents.template.manage']],
    ];

    /**
     * Droits ajoutés aux droits propres de ROLES : lecture du profil et des listes de référence (squelette, vague 0), puis
     * matrice du CDC appliquée par la migration des rôles (W0S-5) : L = `<module>.read`, É et V = écriture ou validation du
     * module avec sa lecture ; `family.pay` du parent ; `identity.roles.assign` de l'administrateur fonctionnel (comptes
     * externes datés, Q13).
     *
     * @var array<string, list<string>>
     */
    public const EXTRA = [
        'sec_promoteur' => ['secondary.profile.read', 'secondary.catalogue.read'],
        'sec_chef_etablissement' => ['secondary.settings.read', 'secondary.enrol.read', 'secondary.enrol.validate', 'secondary.timetable.read', 'secondary.timetable.validate', 'secondary.syllabus.read', 'secondary.attendance.read', 'secondary.discipline.validate', 'secondary.grades.read', 'secondary.reportcard.read', 'secondary.exams.read', 'secondary.exams.validate', 'secondary.fees.read', 'secondary.workshops.read', 'secondary.dashboard.read', 'secondary.profile.read', 'secondary.catalogue.read'],
        'sec_censeur' => ['secondary.settings.read', 'secondary.settings.write', 'secondary.enrol.read', 'secondary.timetable.read', 'secondary.syllabus.read', 'secondary.attendance.read', 'secondary.grades.read', 'secondary.reportcard.read', 'secondary.exams.read', 'secondary.exams.manage', 'secondary.workshops.read', 'secondary.dashboard.read', 'secondary.profile.read', 'secondary.catalogue.read'],
        'sec_surveillant_general' => ['secondary.enrol.read', 'secondary.timetable.read', 'secondary.attendance.read', 'secondary.reportcard.read', 'secondary.dashboard.read', 'secondary.profile.read'],
        'sec_animateur_pedagogique' => ['secondary.catalogue.read'],
        'sec_professeur_principal' => ['secondary.enrol.read', 'secondary.timetable.read', 'secondary.syllabus.read', 'secondary.attendance.read', 'secondary.grades.read', 'secondary.reportcard.read', 'secondary.exams.read', 'secondary.dashboard.read'],
        'enseignant' => ['secondary.timetable.read', 'secondary.syllabus.read', 'secondary.attendance.read', 'secondary.grades.read', 'secondary.reportcard.read', 'secondary.workshops.read', 'secondary.workshops.session', 'secondary.dashboard.read'],
        'sec_conseiller_orientation' => ['secondary.catalogue.read'],
        'sec_intendant' => ['secondary.settings.read', 'secondary.enrol.read', 'secondary.exams.read', 'secondary.fees.read', 'secondary.workshops.costs.read', 'secondary.finance.read', 'secondary.profile.read', 'secondary.catalogue.read'],
        'sec_scolarite' => ['secondary.profile.read', 'secondary.catalogue.read'],
        'sec_parent' => ['secondary.family.pay'],
        'sec_admin_fonctionnel' => ['secondary.profile.read', 'secondary.catalogue.read', 'identity.roles.assign'],
    ];

    /** Rôles communs du socle repris par la matrice, sans préfixe `sec_` (R09). */
    public const SHARED_ROLES = ['enseignant'];

    private function __construct()
    {
    }

    /** @return list<string> permissions effectives d'un rôle (ROLES + EXTRA) */
    public static function permissionsOf(string $roleCode): array
    {
        $permissions = [];
        foreach (self::ROLES as $role) {
            if ($role['code'] === $roleCode) {
                $permissions = $role['permissions'];
            }
        }

        return array_values(array_unique([...$permissions, ...(self::EXTRA[$roleCode] ?? [])]));
    }
}
