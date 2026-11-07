<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Domain;

/** Tableaux de bord et indicateurs attendus (clé => libellé). Un indicateur sans source est rendu indisponible. */
enum DashboardKind: string
{
    case Direction = 'direction';
    case Censeur = 'censeur';
    case Groupe = 'groupe';

    public const DIRECTION = [
        'people.enrolled' => 'Étudiants inscrits',
        'people.enrolment_growth' => 'Inscriptions depuis le début de la période',
        'billing.learners_in_arrears' => 'Apprenants en retard de paiement',
        'billing.outstanding_xaf' => 'Solde restant dû (FCFA)',
        'billing.recovery_rate' => 'Taux de recouvrement',
        'attendance.absent_today' => 'Absents du jour',
        'attendance.week_unjustified_rate' => 'Absences non justifiées de la semaine',
        'staff.teachers_active' => 'Enseignants actifs',
        'staff.vacation_hours' => 'Heures de vacation',
        'scheduling.slots_today' => 'Créneaux tenus aujourd\'hui',
    ];

    public const CENSEUR = [
        'people.enrolled' => 'Élèves inscrits',
        'attendance.absent_today' => 'Absents du jour',
        'attendance.parents_notified' => 'Parents prévenus par SMS',
        'assessment.entry_progress' => 'Avancement de la saisie des notes',
        'assessment.published_average' => 'Moyenne des résultats publiés',
        'discipline.pending_validation' => 'Discipline à valider',
        'assessment.councils_planned' => 'Conseils de classe planifiés',
        'scheduling.slots_today' => 'Créneaux tenus aujourd\'hui',
    ];

    /** @return array<string, string> */
    public function expected(): array
    {
        return match ($this) {
            self::Direction, self::Groupe => self::DIRECTION,
            self::Censeur => self::CENSEUR,
        };
    }

    /** Indicateurs additifs que la vue groupe consolide (somme sur les établissements disponibles). */
    public const ADDITIVE = ['people.enrolled', 'billing.learners_in_arrears', 'billing.outstanding_xaf', 'attendance.absent_today', 'staff.teachers_active', 'scheduling.slots_today'];
}
