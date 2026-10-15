<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Policies;

/**
 * Rôles R01-R23 de l'application Supérieur et matrice lecture/écriture/validation du CDC, en données.
 *
 * Les rôles et leurs permissions sont des données GLOBALES écrites par la migration partagée
 * `database/migrations/202610109000_roles_higher.sql` ; cette classe en est la référence lisible et testée
 * (tests/Feature/Higher/Core/HigherRolesMigrationTest compare la base à ROLES, tests/Unit/Higher/Policies/RoleMatrixTest
 * vérifie chaque case de MATRIX). Un rôle du socle réutilisé (`new` faux) garde ses permissions ; seules les permissions
 * listées ici lui sont ajoutées si elles manquent.
 *
 * Le périmètre (école, département, filière, groupe : RG-ORG-2) n'est PAS dans la permission : il vient de l'affectation
 * (Identity, `role_assignments.scope_type`) et il est imposé par Policies\ScopeResolver puis Support\ScopedBySchool.
 * Les permissions du socle (assessment.*, scheduling.*…) s'exercent sur tout l'établissement dans les routes du socle :
 * les rôles externes (R22, R23) n'en reçoivent donc AUCUNE, seulement des lectures `higher.*` filtrées par Higher.
 */
final class RoleMatrix
{
    /** Opérations qui ne doivent jamais figurer chez l'étudiant (R12) ni chez le garant (R13). */
    public const MANAGEMENT_VERBS = [
        'manage', 'validate', 'approve', 'enter', 'issue', 'revoke', 'cash', 'sign', 'decide', 'take', 'lock', 'publish', 'write',
        'assign', 'amend', 'preside', 'prepare', 'derogate', 'pay', 'enrol', 'justify', 'send', 'reopen', 'export', 'lift',
    ];

    /**
     * @var array<string, array{code: string, new: bool, level: string, sensitive: bool, label_fr: string, label_en: string,
     *      scopes: list<string>, external: bool, max_days: int|null, permissions: list<string>}>
     */
    public const ROLES = [
        'R01' => [
            'code' => 'higher_promoteur', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Promoteur', 'label_en' => 'Founder', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            // Tableaux de bord et finances en lecture ; aucune saisie de notes.
            'permissions' => [
                'higher.access', 'higher.dashboard.read', 'reporting.read', 'higher.settings.read', 'higher.admission.read',
                'higher.enrolment.read', 'higher.curriculum.read', 'higher.timetable.read', 'higher.attendance.read', 'higher.grades.read',
                'higher.deliberation.read', 'higher.billing.read', 'higher.vacation.read', 'higher.document.read',
            ],
        ],
        'R02' => [
            'code' => 'direction_groupe', 'new' => false, 'level' => 'tenant', 'sensitive' => true,
            'label_fr' => 'Directeur général', 'label_en' => 'Director general', 'scopes' => ['tenant'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'higher.dashboard.read', 'reporting.read', 'higher.settings.read', 'higher.settings.sensitive',
                'higher.admission.read', 'higher.enrolment.read', 'higher.curriculum.read', 'higher.curriculum.approve', 'higher.timetable.read',
                'higher.session_log.read', 'higher.attendance.read', 'higher.grades.read', 'higher.deliberation.read', 'higher.billing.read',
                'higher.vacation.read', 'higher.vacation.validate', 'higher.document.read', 'documents.templates.manage',
                'higher.document.sign_institutional', 'higher.alert.dg', 'higher.national_list.export',
            ],
        ],
        'R03' => [
            'code' => 'higher_dir_academique', 'new' => true, 'level' => 'establishment', 'sensitive' => true,
            'label_fr' => 'Directeur académique', 'label_en' => 'Academic director', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            // Réouverture d'une délibération signée : `assessment.reopen` (paramètre assessment.deliberation.reopen_permission).
            'permissions' => [
                'higher.access', 'organisation.read', 'assessment.read', 'assessment.reopen', 'higher.settings.read', 'higher.curriculum.read',
                'higher.curriculum.approve', 'higher.regulation.amend', 'higher.calendar.manage', 'higher.tutelage.manage',
                'higher.admission.read', 'higher.enrolment.read', 'higher.timetable.read', 'higher.attendance.read', 'higher.grades.read',
                'higher.deliberation.read', 'higher.dashboard.read',
            ],
        ],
        'R04' => [
            'code' => 'higher_dir_ecole', 'new' => true, 'level' => 'establishment', 'sensitive' => true,
            'label_fr' => 'Directeur d\'école', 'label_en' => 'School director', 'scopes' => ['org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'assessment.read', 'higher.settings.read', 'higher.settings.manage', 'higher.admission.read',
                'higher.admission.decide', 'higher.enrolment.read', 'higher.enrolment.validate', 'higher.enrolment.derogate',
                'higher.curriculum.read', 'higher.curriculum.manage', 'higher.timetable.read', 'higher.timetable.validate',
                'higher.session_log.read', 'higher.attendance.read', 'higher.grades.read', 'assessment.correction.approve',
                'higher.deliberation.read', 'higher.deliberation.preside', 'higher.billing.read', 'higher.vacation.read',
                'higher.vacation.validate_school', 'higher.document.read', 'higher.document.sign_school', 'higher.dashboard.read',
            ],
        ],
        'R05' => [
            'code' => 'higher_chef_dept', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Chef de département', 'label_en' => 'Head of department', 'scopes' => ['org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'assessment.read', 'higher.assignment.manage', 'higher.admission.read',
                'higher.enrolment.read', 'higher.curriculum.read', 'higher.curriculum.manage', 'scheduling.read', 'scheduling.manage',
                'higher.timetable.read', 'higher.session_log.read', 'higher.session_log.validate', 'higher.attendance.read',
                'higher.grades.read', 'assessment.validate', 'assessment.correction.approve', 'higher.deliberation.read',
                'higher.deliberation.prepare', 'higher.vacation.read', 'higher.hours.validate', 'higher.dashboard.read',
            ],
        ],
        'R06' => [
            'code' => 'higher_resp_filiere', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Responsable de filière', 'label_en' => 'Programme leader', 'scopes' => ['structure'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'scheduling.read', 'scheduling.manage', 'higher.timetable.read', 'higher.curriculum.read',
                'higher.curriculum.manage', 'higher.internship.manage', 'higher.session_log.read', 'higher.attendance.read',
                'higher.grades.read', 'higher.enrolment.read',
            ],
        ],
        'R07' => [
            'code' => 'higher_scolarite', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Service de la scolarité', 'label_en' => 'Registrar', 'scopes' => ['org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'people.read', 'people.manage', 'higher.settings.read', 'higher.admission.read',
                'higher.application.manage', 'higher.enrolment.read', 'higher.enrol', 'higher.curriculum.read', 'scheduling.read',
                'scheduling.manage', 'higher.timetable.read', 'higher.session_log.read', 'higher.attendance.read', 'attendance.justify',
                'higher.grades.read', 'higher.grades.publish', 'higher.deliberation.read', 'higher.deliberation.prepare', 'higher.billing.read', 'documents.read',
                'documents.issue', 'higher.document.read', 'higher.national_list.manage', 'higher.dashboard.read',
            ],
        ],
        'R08' => [
            'code' => 'higher_examens', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Service des examens', 'label_en' => 'Examinations office', 'scopes' => ['org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'assessment.read', 'higher.exam.manage', 'higher.anonymity.lift', 'higher.grades.read',
                'higher.attendance.read', 'higher.timetable.read', 'higher.document.read',
            ],
        ],
        'R09' => [
            'code' => 'enseignant', 'new' => false, 'level' => 'establishment', 'sensitive' => true,
            'label_fr' => 'Enseignant permanent', 'label_en' => 'Permanent lecturer', 'scopes' => ['class', 'structure'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'grades.enter', 'assessment.enter', 'attendance.take', 'higher.grades.submit', 'higher.session_log.write',
                'higher.timetable.read', 'higher.curriculum.read', 'higher.vacation.read_own',
            ],
        ],
        'R10' => [
            'code' => 'higher_vacataire', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Enseignant vacataire', 'label_en' => 'Visiting lecturer', 'scopes' => ['class', 'structure'], 'external' => false, 'max_days' => null,
            // Cumulé avec `enseignant` (R09) : ce rôle n'ajoute que le suivi de ses heures et paiements.
            'permissions' => ['higher.access', 'higher.vacation.read_own'],
        ],
        'R11' => [
            'code' => 'higher_discipline', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Responsable de la discipline', 'label_en' => 'Discipline officer', 'scopes' => ['org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'attendance.read', 'attendance.discipline.manage', 'higher.council.manage',
                'higher.attendance.read',
            ],
        ],
        'R12' => [
            'code' => 'apprenant', 'new' => false, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Étudiant', 'label_en' => 'Student', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            'permissions' => ['self.read', 'documents.request', 'higher.access', 'higher.query.create', 'higher.application.submit'],
        ],
        'R13' => [
            'code' => 'parent', 'new' => false, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Garant', 'label_en' => 'Guarantor', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            // Absences et notes avec le consentement de l'étudiant (People, RG-INS-7) ; situation financière toujours.
            'permissions' => ['self.read', 'documents.request', 'higher.access'],
        ],
        'R14' => [
            'code' => 'caissier', 'new' => false, 'level' => 'establishment', 'sensitive' => true,
            'label_fr' => 'Caissier', 'label_en' => 'Cashier', 'scopes' => ['establishment', 'org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => ['higher.access', 'billing.read', 'billing.cash', 'higher.billing.read'],
        ],
        'R15' => [
            'code' => 'comptable', 'new' => false, 'level' => 'establishment', 'sensitive' => true,
            'label_fr' => 'Comptable', 'label_en' => 'Accountant', 'scopes' => ['establishment', 'org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'billing.read', 'billing.manage', 'documents.issue', 'higher.billing.read', 'higher.quitus.issue',
                'higher.vacation.read', 'higher.vacation.pay', 'higher.session_log.read', 'higher.settings.read', 'higher.admission.read',
                'higher.enrolment.read', 'higher.document.read', 'higher.dashboard.read',
            ],
        ],
        'R16' => [
            'code' => 'higher_resp_stages', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Responsable des stages', 'label_en' => 'Internship officer', 'scopes' => ['structure', 'org_unit'], 'external' => false, 'max_days' => null,
            'permissions' => ['higher.access', 'organisation.read', 'higher.internship.manage', 'higher.defense.manage', 'higher.enrolment.read'],
        ],
        'R17' => [
            'code' => 'higher_rh', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Responsable des ressources humaines', 'label_en' => 'Human resources officer', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            // Contrats de vacation : permission du socle `vacations.manage`.
            'permissions' => ['higher.access', 'organisation.read', 'staff.read', 'staff.manage', 'vacations.manage', 'higher.vacation.read'],
        ],
        'R18' => [
            'code' => 'higher_biblio', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Bibliothécaire', 'label_en' => 'Librarian', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            // Option du lot I4 : rôle déclaré, aucune permission.
            'permissions' => [],
        ],
        'R19' => [
            'code' => 'higher_communication', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Responsable de la communication', 'label_en' => 'Communications officer', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            'permissions' => ['higher.access', 'messaging.read', 'messaging.send', 'messaging.templates.manage'],
        ],
        'R20' => [
            'code' => 'higher_admin_fonct', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Administrateur fonctionnel', 'label_en' => 'Functional administrator', 'scopes' => ['establishment'], 'external' => false, 'max_days' => null,
            'permissions' => [
                'higher.access', 'organisation.read', 'identity.manage', 'identity.roles.assign', 'settings.manage', 'documents.templates.manage',
                'higher.settings.read', 'higher.settings.manage',
            ],
        ],
        'R21' => [
            'code' => 'higher_jury', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Membre de jury', 'label_en' => 'Board of examiners member', 'scopes' => ['structure', 'class'], 'external' => false, 'max_days' => null,
            // Durée = session de jury (role_assignments.ends_at) ; signature du PV par la permission du socle.
            'permissions' => ['higher.access', 'assessment.deliberation.sign', 'higher.jury.portal', 'higher.deliberation.read'],
        ],
        'R22' => [
            'code' => 'higher_tutelle', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Université de tutelle', 'label_en' => 'Supervising university', 'scopes' => ['structure'], 'external' => true, 'max_days' => 90,
            // Externe, lecture seule, limitée à ses filières ; portail inactif en V1 (higher.tutelage.portal_enabled=false).
            'permissions' => ['higher.access', 'higher.tutelage.read'],
        ],
        'R23' => [
            'code' => 'higher_auditeur', 'new' => true, 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Inspection / auditeur', 'label_en' => 'Inspector / auditor', 'scopes' => ['establishment', 'org_unit', 'structure'], 'external' => true, 'max_days' => 90,
            'permissions' => ['higher.access', 'higher.audit.read'],
        ],
    ];

    /**
     * Matrice du CDC (L lecture, E écriture, V validation ; « — » = case absente = refus par défaut). Colonnes : R02, R04, R05, R07,
     * R09 (R09-R10), R12, R13, R14, R15. Chaque case donne la permission qui la réalise. Les « L(la sienne) » de l'étudiant et du
     * garant passent par `self.read` (portails, périmètre « soi »). Trous du CDC (C1, C2 de decisions.md) : refus par défaut.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const MATRIX = [
        'parametrage' => [
            'R02' => ['V', 'higher.settings.sensitive'], 'R04' => ['E', 'higher.settings.manage'], 'R07' => ['L', 'higher.settings.read'],
            'R15' => ['L', 'higher.settings.read'],
        ],
        'admissions' => [
            'R02' => ['L', 'higher.admission.read'], 'R04' => ['V', 'higher.admission.decide'], 'R05' => ['L', 'higher.admission.read'],
            'R07' => ['E', 'higher.application.manage'], 'R12' => ['E', 'higher.application.submit'], 'R15' => ['L', 'higher.admission.read'],
        ],
        'inscriptions' => [
            'R02' => ['L', 'higher.enrolment.read'], 'R04' => ['V', 'higher.enrolment.validate'], 'R05' => ['L', 'higher.enrolment.read'],
            'R07' => ['E', 'higher.enrol'], 'R12' => ['L', 'self.read'], 'R13' => ['L', 'self.read'], 'R15' => ['L', 'higher.enrolment.read'],
        ],
        'maquettes' => [
            'R02' => ['V', 'higher.curriculum.approve'], 'R04' => ['E', 'higher.curriculum.manage'], 'R05' => ['E', 'higher.curriculum.manage'],
            'R07' => ['L', 'higher.curriculum.read'], 'R09' => ['L', 'higher.curriculum.read'], 'R12' => ['L', 'self.read'],
        ],
        'emplois_du_temps' => [
            'R02' => ['L', 'higher.timetable.read'], 'R04' => ['V', 'higher.timetable.validate'], 'R05' => ['E', 'scheduling.manage'],
            'R07' => ['E', 'scheduling.manage'], 'R09' => ['L', 'higher.timetable.read'], 'R12' => ['L', 'self.read'], 'R13' => ['L', 'self.read'],
        ],
        'cahier_de_textes' => [
            'R02' => ['L', 'higher.session_log.read'], 'R04' => ['L', 'higher.session_log.read'], 'R05' => ['V', 'higher.session_log.validate'],
            'R07' => ['L', 'higher.session_log.read'], 'R09' => ['E', 'higher.session_log.write'], 'R12' => ['L', 'self.read'],
            'R15' => ['L', 'higher.session_log.read'],
        ],
        'presences' => [
            'R02' => ['L', 'higher.attendance.read'], 'R04' => ['L', 'higher.attendance.read'], 'R05' => ['L', 'higher.attendance.read'],
            'R07' => ['L', 'higher.attendance.read'], 'R09' => ['E', 'attendance.take'], 'R12' => ['L', 'self.read'], 'R13' => ['L', 'self.read'],
        ],
        'notes' => [
            'R02' => ['L', 'higher.grades.read'], 'R04' => ['L', 'higher.grades.read'], 'R05' => ['V', 'assessment.correction.approve'],
            'R07' => ['L', 'higher.grades.read'], 'R09' => ['E', 'assessment.enter'], 'R12' => ['L', 'self.read'], 'R13' => ['L', 'self.read'],
        ],
        'deliberations' => [
            'R02' => ['L', 'higher.deliberation.read'], 'R04' => ['V', 'higher.deliberation.preside'], 'R05' => ['E', 'higher.deliberation.prepare'],
            'R07' => ['E', 'higher.deliberation.prepare'],
        ],
        'frais_et_paiements' => [
            'R02' => ['L', 'higher.billing.read'], 'R04' => ['L', 'higher.billing.read'], 'R07' => ['L', 'higher.billing.read'],
            'R12' => ['L', 'self.read'], 'R13' => ['L', 'self.read'], 'R14' => ['E', 'billing.cash'], 'R15' => ['E', 'billing.manage'],
        ],
        'vacations' => [
            'R02' => ['V', 'higher.vacation.validate'], 'R04' => ['V', 'higher.vacation.validate_school'], 'R05' => ['V', 'higher.hours.validate'],
            'R09' => ['L', 'higher.vacation.read_own'], 'R15' => ['E', 'higher.vacation.pay'],
        ],
        'documents' => [
            'R02' => ['V', 'documents.templates.manage'], 'R04' => ['V', 'higher.document.sign_school'], 'R07' => ['E', 'documents.issue'],
            'R12' => ['L', 'documents.request'], 'R13' => ['L', 'documents.request'], 'R14' => ['E', 'billing.cash'], 'R15' => ['E', 'documents.issue'],
        ],
        'tableaux_de_bord' => [
            'R02' => ['L', 'higher.dashboard.read'], 'R04' => ['L', 'higher.dashboard.read'], 'R05' => ['L', 'higher.dashboard.read'],
            'R07' => ['L', 'higher.dashboard.read'], 'R15' => ['L', 'higher.dashboard.read'],
        ],
    ];

    /** Colonnes de la matrice du CDC. */
    public const MATRIX_COLUMNS = ['R02', 'R04', 'R05', 'R07', 'R09', 'R12', 'R13', 'R14', 'R15'];

    private function __construct()
    {
    }

    public static function code(string $number): string
    {
        return self::ROLES[$number]['code'] ?? throw new \InvalidArgumentException('Rôle inconnu : ' . $number);
    }

    /** @return list<string> permissions de la ligne qui réalisent une écriture ou une validation (interdites aux cases « — ») */
    public static function writePermissionsOf(string $domain): array
    {
        $permissions = [];
        foreach (self::MATRIX[$domain] ?? [] as [$letter, $permission]) {
            if ($letter !== 'L') {
                $permissions[$permission] = $permission;
            }
        }

        return array_values($permissions);
    }

    public static function isExternal(string $roleCode): bool
    {
        foreach (self::ROLES as $role) {
            if ($role['code'] === $roleCode) {
                return $role['external'];
            }
        }

        return false;
    }
}
