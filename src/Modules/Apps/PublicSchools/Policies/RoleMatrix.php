<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Policies;

/**
 * Rôles R01-R21 des écoles publiques (sauf R11, R12 et R14, propres à la Maternelle) et leurs permissions, en données.
 *
 * Les rôles et leurs permissions sont des données GLOBALES écrites par la migration partagée
 * `database/migrations/202610152351_roles_public_schools.sql` (permissions `reporting.territory_aggregates.read` et
 * `identity.named_access.read` : migrations ultérieures du socle) ; cette classe en est la référence lisible.
 * tests/Unit/PublicSchools/Policies/RoleMatrixTest compare chaque rôle à la migration. Le marquage `aggregate_only` vient de
 * la migration Identity 202610201000 (PP2) : ces rôles ne reçoivent jamais une permission `*.named.*`.
 *
 * Le périmètre (établissement, classe, territoire : RG-TER-2) n'est PAS dans la permission : il vient de l'affectation
 * (Identity) et il est imposé par Policies\ScopeResolver puis Support\ScopedByTerritory. Niveaux : `tenant` = national,
 * `territory` = affectation à un territoire (PP2), `establishment` = affectation à une école.
 */
final class RoleMatrix
{
    /** Marque des permissions nominatives : refusées aux rôles à agrégats seuls (PdoRoleCatalog) et journalisées à chaque lecture territoriale. */
    public const NAMED_MARK = '.named.';

    /** Permissions ajoutées au rôle `parent` du socle (R13) : lecture de ses seuls reçus APEE. */
    public const PARENT_PERMISSIONS = ['public_schools.apee.read_receipts'];

    /**
     * @var array<string, array{number: string, level: string, sensitive: bool, aggregate_only: bool, label_fr: string, label_en: string,
     *      permissions: list<string>}>
     */
    public const ROLES = [
        'pub_cabinet' => [
            'number' => 'R01', 'level' => 'tenant', 'sensitive' => false, 'aggregate_only' => true,
            'label_fr' => 'Cabinet du ministre, secrétariat général', 'label_en' => 'Minister\'s office, general secretariat',
            'permissions' => [
                'public_schools.aggregates.read', 'reporting.territory_aggregates.read',
            ],
        ],
        'pub_exam_directorate' => [
            'number' => 'R02', 'level' => 'tenant', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Direction des examens, concours et certification', 'label_en' => 'Directorate of examinations, competitions and certification',
            'permissions' => [
                'public_schools.aggregates.read', 'public_primary.exams.consolidate', 'public_primary.exams.import',
                'reporting.territory_aggregates.read',
            ],
        ],
        'pub_planning' => [
            'number' => 'R03', 'level' => 'tenant', 'sensitive' => false, 'aggregate_only' => true,
            'label_fr' => 'Division de la planification', 'label_en' => 'Planning division',
            'permissions' => [
                'public_schools.aggregates.read', 'public_schools.census.validate', 'reporting.territory_aggregates.read',
            ],
        ],
        'pub_inspection_general' => [
            'number' => 'R04', 'level' => 'tenant', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Inspection générale des enseignements', 'label_en' => 'General inspectorate of education',
            'permissions' => [
                'public_schools.aggregates.read', 'public_schools.inspection.read', 'documents.templates.manage',
                'reporting.territory_aggregates.read', 'public_primary.referential.manage',
            ],
        ],
        'pub_regional_delegate' => [
            'number' => 'R05', 'level' => 'territory', 'sensitive' => true, 'aggregate_only' => true,
            'label_fr' => 'Délégué régional', 'label_en' => 'Regional delegate',
            'permissions' => [
                'public_schools.school.read', 'public_schools.aggregates.read', 'public_schools.census.validate',
                'public_schools.messages.announce_territory', 'reporting.territory_aggregates.read',
            ],
        ],
        'pub_departmental_delegate' => [
            'number' => 'R06', 'level' => 'territory', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Délégué départemental et service des statistiques', 'label_en' => 'Divisional delegate and statistics service',
            'permissions' => [
                'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.named.read', 'public_schools.staff.read',
                'public_schools.inspection.read', 'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.send',
                'public_schools.messages.announce_territory', 'public_primary.exams.validate', 'public_primary.exams.import',
                'reporting.territory_aggregates.read',
            ],
        ],
        'pub_district_inspector' => [
            'number' => 'R07', 'level' => 'territory', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Inspecteur d\'arrondissement', 'label_en' => 'Sub-divisional inspector',
            'permissions' => [
                'public_schools.school.read', 'public_schools.school.validate', 'public_schools.pupils.read', 'public_schools.pupils.named.read',
                'public_schools.teacher_presence.read', 'public_schools.teacher_presence.validate', 'public_schools.staff.read',
                'public_schools.messages.send', 'public_schools.messages.announce_territory', 'public_schools.apee.read',
                'public_schools.census.validate', 'public_schools.inspection.read', 'public_schools.inspection.write',
                'public_schools.aggregates.read', 'public_primary.promotion.opinion', 'public_primary.exams.validate', 'organisation.read',
                'sync.batches.submit', 'reporting.territory_aggregates.read',
            ],
        ],
        'pub_pedagogical_advisor' => [
            'number' => 'R08', 'level' => 'territory', 'sensitive' => false, 'aggregate_only' => false,
            'label_fr' => 'Conseiller ou animateur pédagogique', 'label_en' => 'Pedagogical advisor or facilitator',
            'permissions' => [
                'public_schools.school.read', 'public_schools.inspection.read', 'public_schools.inspection.write',
            ],
        ],
        'pub_school_director' => [
            'number' => 'R09', 'level' => 'establishment', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Directeur d\'école', 'label_en' => 'School head',
            'permissions' => [
                'public_schools.school.read', 'public_schools.school.write', 'public_schools.pupils.read', 'public_schools.pupils.write',
                'public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read',
                'public_schools.teacher_presence.write', 'public_schools.staff.read', 'public_schools.staff.write', 'public_schools.messages.send',
                'public_schools.apee.read', 'public_schools.census.write', 'public_schools.inspection.read', 'public_primary.evaluation.validate',
                'public_primary.evaluation.publish', 'public_primary.evaluation.rectify', 'public_primary.promotion.validate',
                'public_primary.exams.write', 'organisation.read', 'sync.batches.submit', 'sync.devices.manage', 'identity.named_access.read',
                'documents.read', 'public_nursery.contribution.manage', 'public_nursery.register.read',
            ],
        ],
        'pub_class_teacher' => [
            'number' => 'R10', 'level' => 'establishment', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Enseignant titulaire de classe', 'label_en' => 'Class teacher',
            'permissions' => [
                'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.attendance.read', 'public_schools.attendance.write',
                'public_schools.teacher_presence.read', 'public_schools.messages.send', 'public_schools.inspection.read',
                'public_primary.evaluation.write', 'public_primary.promotion.write', 'organisation.read', 'sync.batches.submit',
            ],
        ],
        'pub_apee_board' => [
            'number' => 'R15', 'level' => 'establishment', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Bureau de l\'APEE (président, trésorier)', 'label_en' => 'PTA board (chair, treasurer)',
            'permissions' => [
                'public_schools.school.read', 'public_schools.apee.read', 'public_schools.apee.write', 'public_schools.apee.read_receipts',
                'public_schools.apee.non_payers.read', 'documents.read',
            ],
        ],
        'pub_school_council' => [
            'number' => 'R16', 'level' => 'establishment', 'sensitive' => false, 'aggregate_only' => false,
            'label_fr' => 'Membre du conseil d\'école', 'label_en' => 'School council member',
            'permissions' => [
                'public_schools.school.read', 'public_schools.apee.read',
            ],
        ],
        'pub_canteen_manager' => [
            'number' => 'R17', 'level' => 'establishment', 'sensitive' => false, 'aggregate_only' => false,
            'label_fr' => 'Responsable de la cantine', 'label_en' => 'Canteen manager',
            'permissions' => [
                'public_schools.school.read', 'public_schools.canteen.write',
            ],
        ],
        'pub_school_health_agent' => [
            'number' => 'R18', 'level' => 'territory', 'sensitive' => false, 'aggregate_only' => false,
            'label_fr' => 'Agent de santé scolaire', 'label_en' => 'School health agent',
            'permissions' => [
                'public_schools.school.read', 'public_schools.canteen.write',
            ],
        ],
        'pub_technical_partner' => [
            'number' => 'R19', 'level' => 'territory', 'sensitive' => false, 'aggregate_only' => true,
            'label_fr' => 'Partenaire technique et financier', 'label_en' => 'Technical and financial partner',
            'permissions' => [
                'public_schools.aggregates.read', 'reporting.territory_aggregates.read',
            ],
        ],
        'pub_inspection_clerk' => [
            'number' => 'R20', 'level' => 'territory', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Agent de saisie de l\'inspection', 'label_en' => 'Inspectorate data-entry clerk',
            'permissions' => [
                'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.write', 'public_schools.census.write',
            ],
        ],
        'pub_functional_admin' => [
            'number' => 'R21', 'level' => 'tenant', 'sensitive' => true, 'aggregate_only' => false,
            'label_fr' => 'Administrateur fonctionnel', 'label_en' => 'Functional administrator',
            'permissions' => [
                'organisation.read', 'organisation.territories.manage', 'identity.manage', 'identity.roles.assign', 'settings.manage',
                'documents.templates.manage', 'messaging.templates.manage', 'sync.devices.manage',
                'public_primary.referential.manage',
            ],
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function permissionsOf(string $role): array
    {
        return self::ROLES[$role]['permissions'] ?? [];
    }

    public static function allows(string $role, string $permission): bool
    {
        if ($role === 'parent') {
            return in_array($permission, self::PARENT_PERMISSIONS, true);
        }
        if (!isset(self::ROLES[$role])) {
            return false;
        }
        if (self::ROLES[$role]['aggregate_only'] && self::isNamed($permission)) {
            return false;
        }

        return in_array($permission, self::ROLES[$role]['permissions'], true);
    }

    public static function isNamed(string $permission): bool
    {
        return str_contains($permission, self::NAMED_MARK);
    }

    public static function isAggregateOnly(string $role): bool
    {
        return self::ROLES[$role]['aggregate_only'] ?? false;
    }

    public static function levelOf(string $role): ?string
    {
        return self::ROLES[$role]['level'] ?? null;
    }

    /** @return list<string> codes des rôles qui portent la permission (rôles à agrégats seuls exclus pour une permission nominative) */
    public static function rolesWith(string $permission): array
    {
        $codes = [];
        foreach (array_keys(self::ROLES) as $role) {
            if (self::allows($role, $permission)) {
                $codes[] = $role;
            }
        }

        return $codes;
    }
}
