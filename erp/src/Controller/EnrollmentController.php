<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\Billing;
use Scholaris\Support\Validator;

/**
 * Module 4 : inscriptions.
 *
 * L'inscription est le pivot du systeme : elle relie un eleve a une classe pour
 * une annee, et declenche la facturation de la scolarite.
 */
final class EnrollmentController extends Controller
{
    private const PER_PAGE = 30;

    private const TYPES = ['NEW', 'RE_ENROLLMENT', 'TRANSFER'];

    private const REGIMES = ['EXTERNAL', 'HALF_BOARD', 'BOARDING'];

    private const STATUSES = ['PENDING', 'ACTIVE', 'CANCELLED'];

    public function index(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();
        $classroomId = $request->string('classroom');
        $page = max($request->int('page', 1), 1);

        $conditions = ['e.tenant_id = :tenant', 'e.deleted_at IS NULL'];
        $params = ['tenant' => $this->app->tenant()->requireId()];

        if ($academicYearId !== null) {
            $conditions[] = 'e.academic_year_id = :year';
            $params['year'] = $academicYearId;
        }

        if ($classroomId !== '') {
            $conditions[] = 'e.classroom_id = :classroom';
            $params['classroom'] = $classroomId;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) $this->app->db()->scalar(
            "SELECT COUNT(*) FROM enrollments e WHERE {$where}",
            $params
        );

        $offset = ($page - 1) * self::PER_PAGE;

        $enrollments = $this->app->db()->select(
            "SELECT e.*, s.matricule, s.first_name, s.last_name, c.name AS classroom_name,
                    i.status AS invoice_status, i.balance
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id
             INNER JOIN classrooms c ON c.id = e.classroom_id
             LEFT JOIN invoices i ON i.enrollment_id = e.id AND i.deleted_at IS NULL
             WHERE {$where}
             ORDER BY e.enrollment_date DESC
             LIMIT ".self::PER_PAGE.' OFFSET '.$offset,
            $params
        );

        return $this->view('enrollments.index', [
            'enrollments' => $enrollments,
            'classrooms' => $this->classrooms(),
            'classroomId' => $classroomId,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'hasAcademicYear' => $academicYearId !== null,
        ]);
    }

    public function create(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        if ($academicYearId === null) {
            return $this->redirectWithError(
                '/enrollments',
                'Aucune annee academique active : ouvrez-en une avant d inscrire.'
            );
        }

        return $this->view('enrollments.form', [
            'classrooms' => $this->classrooms(),
            'students' => $this->enrollableStudents($academicYearId),
            'types' => self::TYPES,
            'regimes' => self::REGIMES,
            'preselectedStudent' => $request->string('student'),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function store(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        if ($academicYearId === null) {
            return $this->redirectWithError('/enrollments', 'Aucune annee academique active.');
        }

        $studentIds = array_map(
            static fn (array $s): string => (string) $s['id'],
            $this->enrollableStudents($academicYearId)
        );

        $validator = (new Validator($request))
            ->in('student_id', 'eleve', $studentIds)
            ->in('classroom_id', 'classe', array_map(
                static fn (array $c): string => (string) $c['id'],
                $this->classrooms()
            ))
            ->in('type', 'type', self::TYPES)
            ->in('regime', 'regime', self::REGIMES)
            ->optional('previous_school');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());
            $this->app->session()->flash('error', implode(' ', $validator->errors()));

            return $this->redirect('/enrollments/create');
        }

        $data = $validator->only(['student_id', 'classroom_id', 'type', 'regime', 'previous_school']);

        // La capacite est verifiee mais ne bloque pas : les etablissements
        // depassent regulierement l'effectif theorique, et un refus dur
        // empecherait une inscription legitime en pleine rentree.
        $classroom = $this->findOrFail('classrooms', (string) $data['classroom_id']);
        $current = $this->headcount((string) $classroom['id'], $academicYearId);
        $overCapacity = $current >= (int) $classroom['capacity'];

        $now = date('Y-m-d H:i:s');

        $result = $this->app->db()->transaction(function () use ($data, $academicYearId, $request, $now): array {
            $enrollmentId = $this->table('enrollments')->insert([
                'student_id' => $data['student_id'],
                'classroom_id' => $data['classroom_id'],
                'academic_year_id' => $academicYearId,
                'enrollment_date' => $now,
                'type' => $data['type'],
                'status' => 'ACTIVE',
                'regime' => $data['regime'],
                'is_repeater' => $request->boolean('is_repeater') ? 1 : 0,
                'previous_school' => $data['previous_school'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $billing = new Billing($this->app->db(), $this->app->tenant());

            return ['enrollment' => $enrollmentId, 'invoice' => $billing->generateInvoice($enrollmentId)];
        });

        $message = $result['invoice'] === null
            ? 'Inscription enregistree. Aucune grille tarifaire definie : la facture reste a generer.'
            : 'Inscription enregistree et facture generee.';

        if ($overCapacity) {
            $message .= ' Attention : la capacite de la classe est atteinte.';
        }

        return $this->redirectWithSuccess('/enrollments', $message);
    }

    public function cancel(Request $request): Response
    {
        $enrollment = $this->findOrFail('enrollments', (string) $request->attribute('id'));

        $this->table('enrollments')
            ->where('id', (string) $enrollment['id'])
            ->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->redirectWithSuccess('/enrollments', 'Inscription annulee.');
    }

    /**
     * Eleves actifs pas encore inscrits sur l'annee : proposer un eleve deja
     * inscrit conduirait a un doublon.
     *
     * @return array<int, array<string, mixed>>
     */
    private function enrollableStudents(string $academicYearId): array
    {
        return $this->app->db()->select(
            'SELECT s.id, s.matricule, s.first_name, s.last_name
             FROM students s
             WHERE s.tenant_id = :tenant AND s.deleted_at IS NULL AND s.status = :status
               AND NOT EXISTS (
                   SELECT 1 FROM enrollments e
                   WHERE e.student_id = s.id AND e.academic_year_id = :year
                     AND e.status <> :cancelled AND e.deleted_at IS NULL
               )
             ORDER BY s.last_name, s.first_name',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'status' => 'ACTIVE',
                'year' => $academicYearId,
                'cancelled' => 'CANCELLED',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classrooms(): array
    {
        return $this->app->db()->select(
            'SELECT c.id, c.name, c.code, c.capacity, l.name AS level_name
             FROM classrooms c INNER JOIN levels l ON l.id = c.level_id
             WHERE c.tenant_id = :tenant
             ORDER BY l.sort_order, c.name',
            ['tenant' => $this->app->tenant()->requireId()]
        );
    }

    private function headcount(string $classroomId, string $academicYearId): int
    {
        return $this->table('enrollments')
            ->notDeleted()
            ->where('classroom_id', $classroomId)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'ACTIVE')
            ->count();
    }
}
