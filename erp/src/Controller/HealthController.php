<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 12 : sante scolaire.
 *
 * Donnees medicales : leur acces est restreint par la permission health:read,
 * accordee a l'infirmerie et a la direction, et non a l'ensemble du personnel.
 * Un dossier par eleve, cree a la premiere saisie.
 */
final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('q');

        $query = $this->app->db();
        $params = ['tenant' => $this->app->tenant()->requireId()];
        $condition = '';

        if ($search !== '') {
            $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $condition = ' AND (s.last_name LIKE :s1 OR s.first_name LIKE :s2 OR s.matricule LIKE :s3)';
            $params['s1'] = $escaped;
            $params['s2'] = $escaped;
            $params['s3'] = $escaped;
        }

        // Tous les eleves actifs sont listes, avec ou sans dossier : c'est
        // l'absence de dossier qui interesse l'infirmerie.
        $students = $query->select(
            'SELECT s.id, s.matricule, s.first_name, s.last_name, s.blood_group, s.allergies,
                    h.id AS record_id, h.blood_type, h.chronic_diseases
             FROM students s
             LEFT JOIN health_records h ON h.student_id = s.id
             WHERE s.tenant_id = :tenant AND s.deleted_at IS NULL AND s.status = \'ACTIVE\''.$condition.'
             ORDER BY s.last_name, s.first_name
             LIMIT 100',
            $params
        );

        return $this->view('health.index', [
            'students' => $students,
            'search' => $search,
            'withRecord' => count(array_filter($students, static fn (array $s): bool => $s['record_id'] !== null)),
        ]);
    }

    public function show(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));

        return $this->view('health.show', [
            'student' => $student,
            'record' => $this->table('health_records')->where('student_id', (string) $student['id'])->first(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function save(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));
        $back = '/health/'.$student['id'];

        $validator = (new Validator($request))
            ->optional('blood_type')
            ->optional('allergies')
            ->optional('chronic_diseases')
            ->optional('medications')
            ->optional('vaccinations')
            ->optional('emergency_contact')
            ->optional('notes');

        $data = $validator->validated();
        $data['updated_at'] = date('Y-m-d H:i:s');

        $existing = $this->table('health_records')->where('student_id', (string) $student['id'])->first();

        if ($existing !== null) {
            $this->table('health_records')->where('id', (string) $existing['id'])->update($data);
        } else {
            $data['student_id'] = $student['id'];
            $data['created_at'] = $data['updated_at'];
            $this->table('health_records')->insert($data);
        }

        return $this->redirectWithSuccess($back, 'Dossier medical enregistre.');
    }
}
