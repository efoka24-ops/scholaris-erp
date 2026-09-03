<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;

/**
 * Tableau de bord.
 *
 * Chaque profil voit le sien : un enseignant ses classes et sa saisie en
 * cours, un parent ses enfants, un eleve ses resultats, l'intendance le
 * recouvrement. Afficher les memes chiffres a tout le monde obligerait chacun
 * a chercher ce qui le concerne au milieu de ce qui ne le concerne pas.
 *
 * Le profil est deduit des permissions, pas du nom du role : un role
 * personnalise portant grades:create doit obtenir la vue enseignant.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Un administrateur de plateforme n'a pas de tableau de bord scolaire :
        // il n'appartient a aucune ecole.
        if ($this->app->auth()->isPlatformAccount() && ! $this->app->tenant()->isSet()) {
            return $this->redirect('/admin');
        }

        $academicYearId = $this->currentAcademicYearId();
        $rbac = $this->app->rbac();

        // L'ordre compte : le profil le plus specifique l'emporte. Un eleve
        // n'a que ses propres donnees, un parent celles de ses enfants.
        if ($this->studentProfile() !== null) {
            return $this->studentDashboard($academicYearId);
        }

        if ($this->parentProfile() !== null) {
            return $this->parentDashboard($academicYearId);
        }

        // Saisir des notes sans pouvoir lancer le calcul : c'est le propre de
        // l'enseignant, la ou l'encadrement arbitre et recalcule.
        if ($rbac->allows('grades:create') && $rbac->denies('grades:calculate')) {
            return $this->teacherDashboard($academicYearId);
        }

        // Encaisser est le propre de l'intendance. La direction consulte la
        // finance sans encaisser, et ne doit donc pas se voir servir un
        // tableau de recouvrement a la place de sa vue d'ensemble.
        if ($rbac->allows('payments:create')
            && $rbac->denies('tenants:update')
            && $rbac->denies('grades:publish')) {
            return $this->bursarDashboard($academicYearId);
        }

        return $this->managementDashboard($academicYearId);
    }

    // --- Direction et administration -----------------------------------------

    /**
     * Vue d'ensemble : effectifs, recouvrement, activite recente.
     */
    private function managementDashboard(?string $academicYearId): Response
    {
        $invoices = fn () => $academicYearId === null
            ? $this->table('invoices')->notDeleted()
            : $this->table('invoices')->notDeleted()->where('academic_year_id', $academicYearId);

        $enrollments = $this->table('enrollments')->notDeleted()->where('status', 'ACTIVE');

        if ($academicYearId !== null) {
            $enrollments->where('academic_year_id', $academicYearId);
        }

        $billed = $invoices()->sum('total_amount');
        $collected = $invoices()->sum('paid_amount');

        return $this->view('dashboard.management', [
            'academicYear' => $academicYearId !== null
                ? $this->table('academic_years')->find($academicYearId)
                : null,
            'stats' => [
                'students' => $this->table('students')->notDeleted()->where('status', 'ACTIVE')->count(),
                'classrooms' => $this->table('classrooms')->count(),
                'enrollments' => $enrollments->count(),
                'staff' => $this->table('employees')->where('status', 'ACTIVE')->count(),
                'billed' => $billed,
                'collected' => $collected,
                'outstanding' => $invoices()->sum('balance'),
                'recovery' => $billed > 0 ? round($collected / $billed * 100, 1) : 0.0,
            ],
            'openPeriod' => $this->openPeriod(),
            'recentStudents' => $this->table('students')
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'pendingApplications' => $this->table('admission_applications')
                ->notDeleted()
                ->where('status', 'PENDING')
                ->count(),
            'enrolmentTrend' => $this->enrolmentTrend(),
            'mentionSpread' => $this->mentionSpread(),
            'attendanceToday' => $this->attendanceToday(),
            'pendingGradeEntries' => $this->pendingGradeEntries(),
        ]);
    }

    /**
     * Effectif inscrit mois par mois depuis la rentree.
     *
     * Le cumul, et non les entrees du mois : c'est la courbe de l'effectif
     * present qui interesse un chef d'etablissement, pas celle des arrivees.
     *
     * @return list<array{label: string, value: int}>
     */
    private function enrolmentTrend(): array
    {
        $months = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aout', 'Sep', 'Oct', 'Nov', 'Dec'];
        $points = [];

        // Douze mois glissants : une annee scolaire s'etend sur deux annees
        // civiles, un decoupage par annee civile la couperait en deux.
        for ($offset = 11; $offset >= 0; $offset--) {
            $end = date('Y-m-01 00:00:00', strtotime('first day of -'.($offset - 1).' month'));

            $count = (int) $this->app->db()->scalar(
                'SELECT COUNT(*) FROM enrollments
                 WHERE tenant_id = :tenant AND deleted_at IS NULL AND created_at < :end',
                ['tenant' => $this->app->tenant()->requireId(), 'end' => $end]
            );

            $points[] = [
                'label' => $months[(int) date('n', strtotime('-'.$offset.' month')) - 1],
                'value' => $count,
            ];
        }

        return $points;
    }

    /**
     * Repartition des moyennes publiees par mention.
     *
     * @return list<array{label: string, value: int, color: string}>
     */
    private function mentionSpread(): array
    {
        $rows = $this->app->db()->select(
            'SELECT general_average FROM period_results
             WHERE tenant_id = :tenant AND is_published = 1 AND general_average IS NOT NULL',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $buckets = [
            ['label' => 'Excellent (>= 16)', 'value' => 0, 'color' => '#7c3aed'],
            ['label' => 'Bien (14 - 16)', 'value' => 0, 'color' => '#10b981'],
            ['label' => 'Assez bien (12 - 14)', 'value' => 0, 'color' => '#4f6ef7'],
            ['label' => 'Passable (10 - 12)', 'value' => 0, 'color' => '#f59e0b'],
            ['label' => 'Insuffisant (< 10)', 'value' => 0, 'color' => '#ef4444'],
        ];

        foreach ($rows as $row) {
            $average = (float) $row['general_average'];

            if ($average >= 16) {
                $buckets[0]['value']++;
            } elseif ($average >= 14) {
                $buckets[1]['value']++;
            } elseif ($average >= 12) {
                $buckets[2]['value']++;
            } elseif ($average >= 10) {
                $buckets[3]['value']++;
            } else {
                $buckets[4]['value']++;
            }
        }

        return $buckets;
    }

    /**
     * Taux de presence du jour.
     *
     * Faute d'appel saisi, le taux est inconnu et non nul : afficher « 0 % »
     * laisserait croire a une desertion generale.
     *
     * @return array{rate: float|null, recorded: int}
     */
    private function attendanceToday(): array
    {
        $today = date('Y-m-d');
        $recorded = $this->table('attendances')->where('date', $today)->count();

        if ($recorded === 0) {
            return ['rate' => null, 'recorded' => 0];
        }

        $present = (int) $this->app->db()->scalar(
            "SELECT COUNT(*) FROM attendances
             WHERE tenant_id = :tenant AND date = :date AND status IN ('PRESENT', 'LATE')",
            ['tenant' => $this->app->tenant()->requireId(), 'date' => $today]
        );

        return ['rate' => round($present / $recorded * 100, 1), 'recorded' => $recorded];
    }

    /**
     * Classes dont la saisie de notes n'est pas achevee sur la periode ouverte.
     *
     * C'est le chiffre qui declenche une relance : une sequence se cloture, et
     * une classe oubliee bloque tous les bulletins.
     */
    private function pendingGradeEntries(): int
    {
        $period = $this->openPeriod();

        if ($period === null) {
            return 0;
        }

        return (int) $this->app->db()->scalar(
            'SELECT COUNT(*) FROM classrooms c
             WHERE c.tenant_id = :tenant
               AND NOT EXISTS (
                   SELECT 1 FROM grades g
                   JOIN students s ON s.id = g.student_id
                   JOIN enrollments e ON e.student_id = s.id AND e.classroom_id = c.id
                   WHERE g.period_id = :period AND g.deleted_at IS NULL
               )',
            ['tenant' => $this->app->tenant()->requireId(), 'period' => $period['id']]
        );
    }

    // --- Intendance ----------------------------------------------------------

    private function bursarDashboard(?string $academicYearId): Response
    {
        $invoices = fn () => $academicYearId === null
            ? $this->table('invoices')->notDeleted()
            : $this->table('invoices')->notDeleted()->where('academic_year_id', $academicYearId);

        $billed = $invoices()->sum('total_amount');
        $collected = $invoices()->sum('paid_amount');

        return $this->view('dashboard.bursar', [
            'stats' => [
                'billed' => $billed,
                'collected' => $collected,
                'outstanding' => $invoices()->sum('balance'),
                'recovery' => $billed > 0 ? round($collected / $billed * 100, 1) : 0.0,
                'overdue' => $invoices()->where('status', 'OVERDUE')->count(),
                'unpaid' => $invoices()->where('status', 'PENDING')->count(),
            ],
            'recentPayments' => $this->app->db()->select(
                'SELECT p.*, s.first_name, s.last_name, s.matricule
                 FROM payments p INNER JOIN students s ON s.id = p.student_id
                 WHERE p.tenant_id = :tenant AND p.deleted_at IS NULL
                 ORDER BY p.paid_at DESC LIMIT 8',
                ['tenant' => $this->app->tenant()->requireId()]
            ),
            'topDebtors' => $this->app->db()->select(
                'SELECT i.balance, s.first_name, s.last_name, s.matricule, i.id
                 FROM invoices i INNER JOIN students s ON s.id = i.student_id
                 WHERE i.tenant_id = :tenant AND i.deleted_at IS NULL AND i.balance > 0
                 ORDER BY i.balance DESC LIMIT 8',
                ['tenant' => $this->app->tenant()->requireId()]
            ),
        ]);
    }

    // --- Enseignant ----------------------------------------------------------

    /**
     * Les classes et matieres de l'enseignant, et l'etat de sa saisie.
     */
    private function teacherDashboard(?string $academicYearId): Response
    {
        $teacherId = $this->currentUserId();
        $period = $this->openPeriod();

        $assignments = $this->app->db()->select(
            'SELECT a.classroom_id, a.subject_id, c.name AS classroom_name, s.name AS subject_name,
                    s.coefficient
             FROM subject_assignments a
             INNER JOIN classrooms c ON c.id = a.classroom_id
             INNER JOIN subjects s ON s.id = a.subject_id
             WHERE a.tenant_id = :tenant AND a.teacher_id = :teacher AND a.deleted_at IS NULL
             ORDER BY c.name, s.name',
            ['tenant' => $this->app->tenant()->requireId(), 'teacher' => $teacherId]
        );

        // Avancement de la saisie : combien d'eleves ont deja une note, sur
        // l'effectif de la classe. C'est la seule question qu'un enseignant se
        // pose en ouvrant l'application.
        foreach ($assignments as $index => $assignment) {
            $assignments[$index]['headcount'] = $this->table('enrollments')
                ->notDeleted()
                ->where('classroom_id', (string) $assignment['classroom_id'])
                ->where('status', 'ACTIVE')
                ->count();

            $assignments[$index]['graded'] = $period === null ? 0 : (int) $this->app->db()->scalar(
                'SELECT COUNT(DISTINCT student_id) FROM grades
                 WHERE tenant_id = :tenant AND subject_id = :subject AND period_id = :period
                   AND deleted_at IS NULL',
                [
                    'tenant' => $this->app->tenant()->requireId(),
                    'subject' => $assignment['subject_id'],
                    'period' => $period['id'],
                ]
            );
        }

        return $this->view('dashboard.teacher', [
            'assignments' => $assignments,
            'period' => $period,
            'mainClassrooms' => $this->table('classrooms')->where('main_teacher_id', $teacherId)->get(),
            'unreadMessages' => $this->table('internal_messages')
                ->where('recipient_user_id', $teacherId)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    // --- Parent --------------------------------------------------------------

    /**
     * Les enfants du parent connecte, et rien d'autre.
     */
    private function parentDashboard(?string $academicYearId): Response
    {
        $parent = $this->parentProfile();

        $children = $this->app->db()->select(
            'SELECT s.id, s.matricule, s.first_name, s.last_name, s.status,
                    c.name AS classroom_name
             FROM student_parents sp
             INNER JOIN students s ON s.id = sp.student_id
             LEFT JOIN enrollments e ON e.student_id = s.id AND e.status = :active AND e.deleted_at IS NULL
             LEFT JOIN classrooms c ON c.id = e.classroom_id
             WHERE sp.parent_id = :parent AND s.tenant_id = :tenant AND s.deleted_at IS NULL
             ORDER BY s.last_name',
            [
                'parent' => $parent['id'],
                'tenant' => $this->app->tenant()->requireId(),
                'active' => 'ACTIVE',
            ]
        );

        // Resultats publies seulement : un parent ne doit pas voir une moyenne
        // avant que le conseil de classe ne l'ait arretee.
        foreach ($children as $index => $child) {
            $children[$index]['results'] = $this->app->db()->select(
                'SELECT r.general_average, r.rank_position, r.total_students, r.mention,
                        p.number AS period_number
                 FROM period_results r
                 INNER JOIN periods p ON p.id = r.period_id
                 WHERE r.tenant_id = :tenant AND r.student_id = :student
                   AND r.is_published = 1 AND r.deleted_at IS NULL
                 ORDER BY p.number DESC LIMIT 3',
                ['tenant' => $this->app->tenant()->requireId(), 'student' => $child['id']]
            );

            $children[$index]['invoice'] = $this->table('invoices')
                ->notDeleted()
                ->where('student_id', (string) $child['id'])
                ->orderBy('created_at', 'desc')
                ->first();

            $children[$index]['absences'] = $this->table('attendances')
                ->where('student_id', (string) $child['id'])
                ->where('status', 'ABSENT')
                ->count();
        }

        return $this->view('dashboard.parent', ['children' => $children]);
    }

    // --- Eleve ---------------------------------------------------------------

    private function studentDashboard(?string $academicYearId): Response
    {
        $student = $this->studentProfile();

        return $this->view('dashboard.student', [
            'student' => $student,
            'enrollment' => $this->app->db()->selectOne(
                'SELECT e.*, c.name AS classroom_name, l.name AS level_name
                 FROM enrollments e
                 INNER JOIN classrooms c ON c.id = e.classroom_id
                 INNER JOIN levels l ON l.id = c.level_id
                 WHERE e.tenant_id = :tenant AND e.student_id = :student AND e.status = :active
                   AND e.deleted_at IS NULL
                 ORDER BY e.enrollment_date DESC LIMIT 1',
                [
                    'tenant' => $this->app->tenant()->requireId(),
                    'student' => $student['id'],
                    'active' => 'ACTIVE',
                ]
            ),
            'results' => $this->app->db()->select(
                'SELECT r.*, p.number AS period_number
                 FROM period_results r
                 INNER JOIN periods p ON p.id = r.period_id
                 WHERE r.tenant_id = :tenant AND r.student_id = :student
                   AND r.is_published = 1 AND r.deleted_at IS NULL
                 ORDER BY p.number DESC',
                ['tenant' => $this->app->tenant()->requireId(), 'student' => $student['id']]
            ),
            'invoice' => $this->table('invoices')
                ->notDeleted()
                ->where('student_id', (string) $student['id'])
                ->orderBy('created_at', 'desc')
                ->first(),
            'absences' => $this->table('attendances')
                ->where('student_id', (string) $student['id'])
                ->where('status', 'ABSENT')
                ->count(),
        ]);
    }

    // --- Rattachements -------------------------------------------------------

    /**
     * Dossier eleve rattache au compte connecte, s'il existe.
     *
     * C'est ce lien qui borne l'acces d'un eleve a ses seules donnees.
     *
     * @return array<string, mixed>|null
     */
    private function studentProfile(): ?array
    {
        return $this->table('students')
            ->notDeleted()
            ->where('user_id', $this->currentUserId())
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parentProfile(): ?array
    {
        return $this->table('parents')
            ->notDeleted()
            ->where('user_id', $this->currentUserId())
            ->first();
    }

    /**
     * Periode ouverte a la saisie, s'il y en a une.
     *
     * @return array<string, mixed>|null
     */
    private function openPeriod(): ?array
    {
        return $this->app->db()->selectOne(
            'SELECT p.* FROM periods p
             INNER JOIN academic_years y ON y.id = p.academic_year_id
             WHERE y.tenant_id = :tenant AND y.status = :active AND p.grading_status = :open
             ORDER BY p.number LIMIT 1',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'active' => 'ACTIVE',
                'open' => 'OPEN',
            ]
        );
    }
}
