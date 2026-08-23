<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Modules 9 a 11 : emplois du temps, presences, discipline.
 *
 * Ces trois modules partagent la meme maille — la classe et le jour — et les
 * memes listes de reference. Les regrouper evite de repeter trois fois le
 * chargement des classes, des matieres et des eleves.
 */
final class SchoolLifeController extends Controller
{
    private const DAYS = [
        'MONDAY' => 'Lundi',
        'TUESDAY' => 'Mardi',
        'WEDNESDAY' => 'Mercredi',
        'THURSDAY' => 'Jeudi',
        'FRIDAY' => 'Vendredi',
        'SATURDAY' => 'Samedi',
    ];

    private const ATTENDANCE_STATUSES = ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED'];

    private const INCIDENT_TYPES = [
        'RETARD', 'ABSENCE_INJUSTIFIEE', 'INSOLENCE', 'BAGARRE', 'TRICHERIE', 'AUTRE',
    ];

    private const SANCTIONS = [
        'AVERTISSEMENT', 'BLAME', 'EXCLUSION_COURS', 'EXCLUSION_TEMPORAIRE',
        'CONVOCATION_PARENTS', 'AUTRE',
    ];

    // --- Module 9 : emplois du temps -----------------------------------------

    public function timetable(Request $request): Response
    {
        $classroomId = $request->string('classroom');
        $slots = [];

        if ($classroomId !== '') {
            $this->findOrFail('classrooms', $classroomId);

            $slots = $this->app->db()->select(
                'SELECT t.*, s.name AS subject_name, u.first_name, u.last_name, r.name AS room_name
                 FROM timetable_slots t
                 INNER JOIN subjects s ON s.id = t.subject_id
                 INNER JOIN users u ON u.id = t.teacher_id
                 LEFT JOIN rooms r ON r.id = t.room_id
                 WHERE t.tenant_id = :tenant AND t.classroom_id = :classroom
                 ORDER BY t.day_of_week, t.start_time',
                ['tenant' => $this->app->tenant()->requireId(), 'classroom' => $classroomId]
            );
        }

        // Regroupement par jour : la vue affiche une colonne par jour.
        $byDay = array_fill_keys(array_keys(self::DAYS), []);

        foreach ($slots as $slot) {
            $byDay[(string) $slot['day_of_week']][] = $slot;
        }

        return $this->view('schoollife.timetable', [
            'classrooms' => $this->classrooms(),
            'classroomId' => $classroomId,
            'byDay' => $byDay,
            'days' => self::DAYS,
            'subjects' => $this->subjects(),
            'teachers' => $this->staff(),
            'rooms' => $this->table('rooms')->orderBy('name')->get(),
        ]);
    }

    public function storeSlot(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $this->findOrFail('classrooms', $classroomId);

        $back = '/timetable?classroom='.urlencode($classroomId);
        $academicYearId = $this->currentAcademicYearId();

        if ($academicYearId === null) {
            return $this->redirectWithError($back, 'Aucune annee academique active.');
        }

        $validator = (new Validator($request))
            ->in('subject_id', 'matiere', $this->ids($this->subjects()))
            ->in('teacher_id', 'enseignant', $this->ids($this->staff()))
            ->in('day_of_week', 'jour', array_keys(self::DAYS))
            ->required('start_time', 'heure de debut')
            ->required('end_time', 'heure de fin')
            ->in('room_id', 'salle', $this->ids($this->table('rooms')->get()), false);

        if ($validator->fails()) {
            return $this->redirectWithError($back, implode(' ', $validator->errors()));
        }

        $data = $validator->validated();

        if ($data['start_time'] >= $data['end_time']) {
            return $this->redirectWithError($back, 'L heure de fin doit suivre l heure de debut.');
        }

        // Un enseignant ne peut pas etre dans deux salles au meme moment, ni
        // une salle accueillir deux classes : ces conflits se detectent ici,
        // pas apres coup sur le terrain.
        $conflict = $this->findConflict(
            (string) $data['day_of_week'],
            (string) $data['start_time'],
            (string) $data['end_time'],
            (string) $data['teacher_id'],
            $data['room_id'] !== null ? (string) $data['room_id'] : null,
            $classroomId
        );

        if ($conflict !== null) {
            return $this->redirectWithError($back, $conflict);
        }

        $this->table('timetable_slots')->insert([
            'academic_year_id' => $academicYearId,
            'classroom_id' => $classroomId,
            'subject_id' => $data['subject_id'],
            'teacher_id' => $data['teacher_id'],
            'room_id' => $data['room_id'],
            'day_of_week' => $data['day_of_week'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess($back, 'Creneau ajoute.');
    }

    public function deleteSlot(Request $request): Response
    {
        $slot = $this->findOrFail('timetable_slots', (string) $request->attribute('id'));

        $this->table('timetable_slots')->where('id', (string) $slot['id'])->delete();

        return $this->redirectWithSuccess(
            '/timetable?classroom='.urlencode((string) $slot['classroom_id']),
            'Creneau supprime.'
        );
    }

    // --- Module 10 : presences -----------------------------------------------

    public function attendance(Request $request): Response
    {
        $classroomId = $request->string('classroom');
        $date = $request->string('date') ?: date('Y-m-d');
        $students = [];
        $existing = [];

        if ($classroomId !== '') {
            $this->findOrFail('classrooms', $classroomId);
            $students = $this->studentsOf($classroomId);

            foreach ($this->table('attendances')
                ->where('classroom_id', $classroomId)
                ->where('date', $date)
                ->get() as $row) {
                $existing[(string) $row['student_id']] = $row;
            }
        }

        return $this->view('schoollife.attendance', [
            'classrooms' => $this->classrooms(),
            'classroomId' => $classroomId,
            'date' => $date,
            'students' => $students,
            'existing' => $existing,
            'statuses' => self::ATTENDANCE_STATUSES,
        ]);
    }

    public function storeAttendance(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $this->findOrFail('classrooms', $classroomId);

        $date = $request->string('date') ?: date('Y-m-d');
        $back = '/attendance?classroom='.urlencode($classroomId).'&date='.urlencode($date);

        $statuses = $request->all()['status'] ?? [];
        $reasons = $request->all()['reason'] ?? [];

        if (! is_array($statuses)) {
            return $this->redirectWithError($back, 'Aucune saisie recue.');
        }

        $saved = 0;
        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use ($statuses, $reasons, $classroomId, $date, $now, &$saved): void {
            foreach ($statuses as $studentId => $status) {
                if (! is_string($studentId) || ! in_array($status, self::ATTENDANCE_STATUSES, true)) {
                    continue;
                }

                $reason = is_array($reasons) && isset($reasons[$studentId]) && is_scalar($reasons[$studentId])
                    ? trim((string) $reasons[$studentId])
                    : null;

                $existing = $this->table('attendances')
                    ->where('student_id', $studentId)
                    ->where('classroom_id', $classroomId)
                    ->where('date', $date)
                    ->first();

                if ($existing !== null) {
                    $this->table('attendances')
                        ->where('id', (string) $existing['id'])
                        ->update(['status' => $status, 'reason' => $reason ?: null, 'updated_at' => $now]);
                } else {
                    $this->table('attendances')->insert([
                        'student_id' => $studentId,
                        'classroom_id' => $classroomId,
                        'date' => $date,
                        'status' => $status,
                        'reason' => $reason ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $saved++;
            }
        });

        return $this->redirectWithSuccess($back, $saved.' presence(s) enregistree(s).');
    }

    // --- Module 11 : discipline ----------------------------------------------

    public function discipline(Request $request): Response
    {
        $incidents = $this->app->db()->select(
            'SELECT d.*, s.matricule, s.first_name, s.last_name,
                    u.first_name AS reporter_first, u.last_name AS reporter_last
             FROM discipline_incidents d
             INNER JOIN students s ON s.id = d.student_id
             INNER JOIN users u ON u.id = d.reported_by
             WHERE d.tenant_id = :tenant
             ORDER BY d.date DESC, d.created_at DESC
             LIMIT 100',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('schoollife.discipline', [
            'incidents' => $incidents,
            'students' => $this->table('students')
                ->select(['id', 'matricule', 'first_name', 'last_name'])
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'types' => self::INCIDENT_TYPES,
            'sanctions' => self::SANCTIONS,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeIncident(Request $request): Response
    {
        $studentIds = $this->ids(
            $this->table('students')->select(['id'])->notDeleted()->get()
        );

        $validator = (new Validator($request))
            ->in('student_id', 'eleve', $studentIds)
            ->in('type', 'type d incident', self::INCIDENT_TYPES)
            ->required('description', 'description')
            ->date('date', 'date')
            ->in('sanction', 'sanction', self::SANCTIONS, false)
            ->optional('sanction_details');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/discipline', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();

        $this->table('discipline_incidents')->insert([
            'student_id' => $data['student_id'],
            'reported_by' => $this->currentUserId(),
            'type' => $data['type'],
            'description' => $data['description'],
            'date' => $data['date'],
            'sanction' => $data['sanction'],
            'sanction_details' => $data['sanction_details'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess('/discipline', 'Incident enregistre.');
    }

    // --- Outillage commun ----------------------------------------------------

    /**
     * Detecte un conflit d'enseignant ou de salle sur un creneau.
     */
    private function findConflict(
        string $day,
        string $start,
        string $end,
        string $teacherId,
        ?string $roomId,
        string $classroomId
    ): ?string {
        $slots = $this->table('timetable_slots')->where('day_of_week', $day)->get();

        foreach ($slots as $slot) {
            // Deux creneaux se chevauchent si chacun commence avant que
            // l'autre ne finisse.
            $overlaps = $start < (string) $slot['end_time'] && (string) $slot['start_time'] < $end;

            if (! $overlaps) {
                continue;
            }

            if ((string) $slot['teacher_id'] === $teacherId) {
                return 'Cet enseignant a deja cours sur ce creneau.';
            }

            if ($roomId !== null && (string) $slot['room_id'] === $roomId) {
                return 'Cette salle est deja occupee sur ce creneau.';
            }

            if ((string) $slot['classroom_id'] === $classroomId) {
                return 'Cette classe a deja cours sur ce creneau.';
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function studentsOf(string $classroomId): array
    {
        $academicYearId = $this->currentAcademicYearId();

        if ($academicYearId === null) {
            return [];
        }

        return $this->app->db()->select(
            'SELECT s.id, s.matricule, s.first_name, s.last_name
             FROM enrollments e INNER JOIN students s ON s.id = e.student_id
             WHERE e.tenant_id = :tenant AND e.classroom_id = :classroom
               AND e.academic_year_id = :year AND e.status = :status AND e.deleted_at IS NULL
             ORDER BY s.last_name, s.first_name',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'classroom' => $classroomId,
                'year' => $academicYearId,
                'status' => 'ACTIVE',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classrooms(): array
    {
        return $this->app->db()->select(
            'SELECT c.id, c.name, l.name AS level_name FROM classrooms c
             INNER JOIN levels l ON l.id = c.level_id
             WHERE c.tenant_id = :tenant ORDER BY l.sort_order, c.name',
            ['tenant' => $this->app->tenant()->requireId()]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function subjects(): array
    {
        return $this->table('subjects')->select(['id', 'name', 'code'])->notDeleted()->orderBy('name')->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staff(): array
    {
        return $this->table('users')
            ->select(['id', 'first_name', 'last_name'])
            ->notDeleted()
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }
}
