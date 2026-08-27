<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 8bis : cahier de textes numerique.
 *
 * Un enseignant ne voit et ne modifie que ses propres seances. La direction
 * (Directeur, Censeur, Surveillant general), qui detient la permission large
 * course-log:read-all, voit le cahier de textes de tout l'etablissement — le
 * meme principe que la portee des affectations dans GradeController.
 */
final class CourseLogController extends Controller
{
    public function index(Request $request): Response
    {
        $classroomId = $request->string('classroom');
        $date = $request->string('date');

        $sql = 'SELECT e.*, c.name AS classroom_name, s.name AS subject_name,
                       u.first_name AS teacher_first, u.last_name AS teacher_last
                FROM course_log_entries e
                INNER JOIN classrooms c ON c.id = e.classroom_id
                INNER JOIN subjects s ON s.id = e.subject_id
                INNER JOIN users u ON u.id = e.teacher_id
                WHERE e.tenant_id = :tenant';
        $params = ['tenant' => $this->app->tenant()->requireId()];

        if (! $this->app->rbac()->allows('course-log:read-all')) {
            $sql .= ' AND e.teacher_id = :teacher';
            $params['teacher'] = $this->currentUserId();
        }

        if ($classroomId !== '') {
            $sql .= ' AND e.classroom_id = :classroom';
            $params['classroom'] = $classroomId;
        }

        if ($date !== '') {
            $sql .= ' AND e.date = :date';
            $params['date'] = $date;
        }

        $entries = $this->app->db()->select($sql.' ORDER BY e.date DESC, e.created_at DESC LIMIT 200', $params);

        return $this->view('courselog.index', [
            'entries' => $entries,
            'classrooms' => $this->classrooms(),
            'subjects' => $this->subjects(),
            'classroomId' => $classroomId,
            'date' => $date,
            'canCreate' => $this->app->rbac()->allows('course-log:create'),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = (new Validator($request))
            ->in('classroom_id', 'classe', $this->ids($this->classrooms()))
            ->in('subject_id', 'matiere', $this->ids($this->subjects()))
            ->date('date', 'date')
            ->required('content', 'contenu de la seance')
            ->optional('homework');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/course-log', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();

        $this->table('course_log_entries')->insert([
            'classroom_id' => $data['classroom_id'],
            'subject_id' => $data['subject_id'],
            'teacher_id' => $this->currentUserId(),
            'date' => $data['date'],
            'content' => $data['content'],
            'homework' => $data['homework'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess('/course-log', 'Seance enregistree.');
    }

    public function update(Request $request): Response
    {
        $entry = $this->findOrFail('course_log_entries', (string) $request->attribute('id'));

        // Seul l'enseignant auteur peut modifier sa seance, meme si la
        // direction peut toutes les consulter.
        if ((string) $entry['teacher_id'] !== $this->currentUserId()) {
            return $this->redirectWithError('/course-log', 'Vous ne pouvez modifier que vos propres seances.');
        }

        $validator = (new Validator($request))
            ->required('content', 'contenu de la seance')
            ->optional('homework');

        if ($validator->fails()) {
            return $this->redirectWithError('/course-log', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();

        $this->table('course_log_entries')->where('id', (string) $entry['id'])->update([
            'content' => $data['content'],
            'homework' => $data['homework'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess('/course-log', 'Seance mise a jour.');
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }
}
