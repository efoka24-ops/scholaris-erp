<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Policies;

/**
 * Rôles de la Maternelle privée (spec 007 §2) et leurs permissions. `ROLES` : rôles créés par la migration
 * `roles_nursery` (nouveaux codes `nursery_*`) ; `SHARED` : lignes de la spec qui s'appuient sur un rôle du socle ou sur un lien
 * de garde (le parent n'a pas de permission `nursery.*` en base : ses routes de portail vérifient qu'il est garant de l'enfant).
 */
final class RoleMatrix
{
    /** @var array<string, array{code: string, level: string, sensitive: bool, label_fr: string, label_en: string, permissions: list<string>}> */
    public const ROLES = [
        'N01' => [
            'code' => 'nursery_promoteur', 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Promoteur de maternelle', 'label_en' => 'Nursery founder',
            'permissions' => ['nursery.dashboard.read', 'reporting.read'],
        ],
        'N02' => [
            'code' => 'nursery_directeur', 'level' => 'establishment', 'sensitive' => true,
            'label_fr' => 'Directrice ou directeur de maternelle', 'label_en' => 'Nursery head',
            'permissions' => [
                'nursery.settings.manage', 'nursery.section.manage', 'nursery.child.read', 'nursery.pickup.read', 'nursery.pickup.restrict',
                'nursery.pickup.manage', 'nursery.pickup.handover', 'nursery.register.read', 'nursery.dev.read', 'nursery.dev.validate',
                'nursery.frame.manage', 'nursery.lifebook.read', 'nursery.need.read', 'nursery.allergy.read', 'nursery.message.send',
                'nursery.transition.decide', 'nursery.dashboard.read',
            ],
        ],
        'N03' => [
            'code' => 'nursery_educator', 'level' => 'class', 'sensitive' => false,
            'label_fr' => 'Éducatrice ou enseignante de section', 'label_en' => 'Nursery educator',
            'permissions' => [
                'nursery.child.read', 'nursery.pickup.handover', 'nursery.register.read', 'nursery.arrival.record', 'nursery.dev.observe', 'nursery.dev.read',
                'nursery.lifebook.write', 'nursery.lifebook.read', 'nursery.need.report', 'nursery.need.read', 'nursery.allergy.read',
                'nursery.message.send', 'nursery.transition.opinion', 'attendance.take',
            ],
        ],
        'N04' => [
            'code' => 'nursery_assistant', 'level' => 'class', 'sensitive' => false,
            'label_fr' => 'Aide maternelle', 'label_en' => 'Nursery assistant',
            'permissions' => ['nursery.child.read', 'nursery.pickup.handover', 'nursery.arrival.record', 'nursery.register.read', 'nursery.allergy.read', 'attendance.take'],
        ],
        'N05' => [
            'code' => 'nursery_secretaire', 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Secrétaire de maternelle', 'label_en' => 'Nursery secretary',
            'permissions' => ['people.manage', 'nursery.enrol', 'nursery.child.read', 'documents.issue', 'nursery.pickup.manage', 'nursery.pickup.read', 'nursery.register.read', 'nursery.allergy.read'],
        ],
        'N10' => [
            'code' => 'nursery_cantine', 'level' => 'establishment', 'sensitive' => false,
            'label_fr' => 'Responsable de cantine de maternelle', 'label_en' => 'Nursery canteen manager',
            'permissions' => ['nursery.allergy.read'],
        ],
    ];

    /** @var array<string, array{role: string, permissions: list<string>}> */
    public const SHARED = [
        'N06' => ['role' => 'comptable', 'permissions' => ['billing.manage']],
        'N07' => ['role' => 'caissier', 'permissions' => ['billing.cash']],
        'N08' => ['role' => 'parent', 'permissions' => ['nursery.pickup.self_manage', 'nursery.lifebook.view', 'nursery.allergy.declare']],
        'N11' => ['role' => 'super_admin', 'permissions' => ['identity.manage', 'settings.manage', 'documents.templates.manage']],
    ];

    /**
     * Domaine => acteur => [V = voit, E = écrit, permission qui ouvre l'accès].
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const MATRIX = [
        'parametrage' => ['N02' => ['E', 'nursery.settings.manage']],
        'sections' => ['N02' => ['E', 'nursery.section.manage'], 'N03' => ['V', 'nursery.child.read'], 'N04' => ['V', 'nursery.child.read'], 'N05' => ['V', 'nursery.child.read']],
        'inscriptions' => ['N02' => ['V', 'nursery.child.read'], 'N05' => ['E', 'nursery.enrol']],
        'presence' => ['N03' => ['E', 'attendance.take'], 'N04' => ['E', 'attendance.take']],
        'sortie' => ['N02' => ['E', 'nursery.pickup.handover'], 'N03' => ['E', 'nursery.pickup.handover'], 'N04' => ['E', 'nursery.pickup.handover'], 'N05' => ['E', 'nursery.pickup.manage']],
        'restrictions' => ['N02' => ['E', 'nursery.pickup.restrict']],
        'registre' => ['N02' => ['V', 'nursery.register.read'], 'N03' => ['V', 'nursery.register.read'], 'N04' => ['V', 'nursery.register.read'], 'N05' => ['V', 'nursery.register.read']],
        'developpement' => ['N02' => ['E', 'nursery.dev.validate'], 'N03' => ['E', 'nursery.dev.observe']],
        'cahier_de_vie' => ['N03' => ['E', 'nursery.lifebook.write'], 'N02' => ['V', 'nursery.lifebook.read']],
        'besoins' => ['N03' => ['E', 'nursery.need.report'], 'N02' => ['V', 'nursery.need.read']],
        'allergies' => ['N10' => ['V', 'nursery.allergy.read'], 'N03' => ['V', 'nursery.allergy.read']],
        'pilotage' => ['N01' => ['V', 'nursery.dashboard.read'], 'N02' => ['V', 'nursery.dashboard.read']],
    ];

    private function __construct()
    {
    }
}
