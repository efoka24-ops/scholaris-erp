<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 2 : classes.
 *
 * Une classe est rattachee a un niveau, porte une section (francophone ou
 * anglophone, distinction structurante au Cameroun) et un professeur principal.
 */
final class ClassroomController extends Controller
{
    private const SECTIONS = ['FRANCOPHONE', 'ANGLOPHONE'];

    public function index(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        // Effectif inscrit par classe, en une requete plutot qu'une par ligne.
        $counts = [];

        if ($academicYearId !== null) {
            $rows = $this->app->db()->select(
                'SELECT classroom_id, COUNT(*) AS total FROM enrollments
                 WHERE tenant_id = :tenant AND academic_year_id = :year
                   AND status = :status AND deleted_at IS NULL
                 GROUP BY classroom_id',
                [
                    'tenant' => $this->app->tenant()->requireId(),
                    'year' => $academicYearId,
                    'status' => 'ACTIVE',
                ]
            );

            foreach ($rows as $row) {
                $counts[(string) $row['classroom_id']] = (int) $row['total'];
            }
        }

        $classrooms = $this->app->db()->select(
            'SELECT c.*, l.name AS level_name, l.sort_order AS level_order,
                    u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
             FROM classrooms c
             INNER JOIN levels l ON l.id = c.level_id
             LEFT JOIN users u ON u.id = c.main_teacher_id
             WHERE c.tenant_id = :tenant
             ORDER BY l.sort_order, c.name',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('classrooms.index', [
            'classrooms' => $classrooms,
            'counts' => $counts,
        ]);
    }

    public function show(Request $request): Response
    {
        $classroom = $this->findOrFail('classrooms', (string) $request->attribute('id'));
        $academicYearId = $this->currentAcademicYearId();

        $students = $academicYearId === null ? [] : $this->app->db()->select(
            'SELECT s.id, s.matricule, s.first_name, s.last_name, s.gender, e.regime, e.is_repeater
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id
             WHERE e.tenant_id = :tenant AND e.classroom_id = :classroom
               AND e.academic_year_id = :year AND e.status = :status AND e.deleted_at IS NULL
             ORDER BY s.last_name, s.first_name',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'classroom' => $classroom['id'],
                'year' => $academicYearId,
                'status' => 'ACTIVE',
            ]
        );

        return $this->view('classrooms.show', [
            'classroom' => $classroom,
            'level' => $this->table('levels')->find((string) $classroom['level_id']),
            'students' => $students,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('classrooms.form', [
            'classroom' => null,
            'levels' => $this->levels(),
            'teachers' => $this->teachers(),
            'sections' => self::SECTIONS,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = $this->validate($request);

        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/classrooms/create');
        }

        $data = $validator->only(['code', 'name', 'capacity', 'level_id', 'main_teacher_id', 'section']);

        if ($this->codeExists((string) $data['code'], null)) {
            return $this->redirectWithError('/classrooms/create', 'Ce code de classe est deja utilise.');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $id = $this->table('classrooms')->insert($data);

        return $this->redirectWithSuccess('/classrooms/'.$id, 'Classe creee.');
    }

    public function edit(Request $request): Response
    {
        return $this->view('classrooms.form', [
            'classroom' => $this->findOrFail('classrooms', (string) $request->attribute('id')),
            'levels' => $this->levels(),
            'teachers' => $this->teachers(),
            'sections' => self::SECTIONS,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function update(Request $request): Response
    {
        $classroom = $this->findOrFail('classrooms', (string) $request->attribute('id'));
        $validator = $this->validate($request);

        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/classrooms/'.$classroom['id'].'/edit');
        }

        $data = $validator->only(['code', 'name', 'capacity', 'level_id', 'main_teacher_id', 'section']);

        if ($this->codeExists((string) $data['code'], (string) $classroom['id'])) {
            return $this->redirectWithError('/classrooms/'.$classroom['id'].'/edit', 'Ce code est deja utilise.');
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->table('classrooms')->where('id', (string) $classroom['id'])->update($data);

        return $this->redirectWithSuccess('/classrooms/'.$classroom['id'], 'Classe mise a jour.');
    }

    private function validate(Request $request): Validator
    {
        $levelIds = array_map(static fn (array $l): string => (string) $l['id'], $this->levels());
        $teacherIds = array_map(static fn (array $t): string => (string) $t['id'], $this->teachers());

        return (new Validator($request))
            ->required('code', 'code')
            ->required('name', 'nom')
            ->integer('capacity', 'capacite', 1, 500)
            ->in('level_id', 'niveau', $levelIds)
            ->in('section', 'section', self::SECTIONS)
            ->in('main_teacher_id', 'professeur principal', $teacherIds, false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function levels(): array
    {
        return $this->app->db()->select(
            'SELECT l.id, l.code, l.name, c.name AS cycle_name
             FROM levels l INNER JOIN cycles c ON c.id = l.cycle_id
             WHERE l.tenant_id = :tenant
             ORDER BY c.sort_order, l.sort_order',
            ['tenant' => $this->app->tenant()->requireId()]
        );
    }

    /**
     * Personnel pouvant etre professeur principal : tout compte actif de
     * l'etablissement, le role etant verifie a l'affectation des matieres.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teachers(): array
    {
        return $this->table('users')
            ->select(['id', 'first_name', 'last_name'])
            ->notDeleted()
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')
            ->get();
    }

    private function codeExists(string $code, ?string $exceptId): bool
    {
        $query = $this->table('classrooms')->where('code', $code);

        if ($exceptId !== null) {
            $query->where('id', $exceptId, '!=');
        }

        return $query->exists();
    }

    private function backWithErrors(Request $request, Validator $validator, string $to): Response
    {
        $this->app->session()->flashInput($request->all());
        $this->app->session()->flash('error', implode(' ', $validator->errors()));

        return $this->redirect($to);
    }
}
