<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Database\Table;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Examens officiels : CEP, BEPC, Probatoire, BAC, et examens configurables.
 *
 * L'etablissement inscrit ses eleves, suit les dossiers et saisit les
 * resultats. Les conditions d'inscription (age, periode) sont verifiees a la
 * saisie : un dossier refuse au centre d'examen se decouvre trop tard.
 */
final class ExamController extends Controller
{
    private const CODES = ['CEP', 'BEPC', 'PROBATOIRE', 'BAC', 'CUSTOM'];

    private const STATUSES = ['PENDING', 'VALIDATED', 'REJECTED', 'ABSENT', 'PASSED', 'FAILED'];

    public function index(Request $request): Response
    {
        $exams = $this->app->db()->select(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM exam_registrations r WHERE r.exam_id = e.id) AS registrations
             FROM official_exams e
             WHERE e.tenant_id = :tenant AND e.deleted_at IS NULL
             ORDER BY e.registration_start DESC',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('exams.index', [
            'exams' => $exams,
            'codes' => self::CODES,
            'levels' => $this->table('levels')->orderBy('sort_order')->get(),
            'academicYearId' => $this->currentAcademicYearId(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function store(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        if ($academicYearId === null) {
            return $this->redirectWithError('/exams', 'Aucune annee academique active.');
        }

        $levelIds = array_map(
            static fn (array $l): string => (string) $l['id'],
            $this->table('levels')->select(['id'])->get()
        );

        $validator = (new Validator($request))
            ->required('name', 'intitule')
            ->in('code', 'examen', self::CODES)
            ->in('level_id', 'niveau', $levelIds, false)
            ->date('registration_start', 'ouverture des inscriptions')
            ->date('registration_end', 'cloture des inscriptions')
            ->date('exam_start', 'debut des epreuves', false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/exams', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();

        if ($data['registration_end'] < $data['registration_start']) {
            return $this->redirectWithError('/exams', 'La cloture doit suivre l ouverture des inscriptions.');
        }

        $fee = str_replace(',', '.', $request->string('fee_amount'));

        $this->table('official_exams')->insert([
            'name' => $data['name'],
            'code' => $data['code'],
            'level_id' => $data['level_id'],
            'academic_year_id' => $academicYearId,
            'registration_start' => $data['registration_start'].' 00:00:00',
            'registration_end' => $data['registration_end'].' 23:59:59',
            'exam_start' => $data['exam_start'] !== null ? $data['exam_start'].' 08:00:00' : null,
            'fee_amount' => is_numeric($fee) ? number_format((float) $fee, 2, '.', '') : '0.00',
            'pass_mark' => '10.00',
            'is_official' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess('/exams', 'Examen ouvert aux inscriptions.');
    }

    public function show(Request $request): Response
    {
        $exam = $this->findOrFail('official_exams', (string) $request->attribute('id'));

        $registrations = $this->app->db()->select(
            'SELECT r.*, s.matricule, s.first_name, s.last_name, s.date_of_birth
             FROM exam_registrations r
             INNER JOIN students s ON s.id = r.student_id
             WHERE r.tenant_id = :tenant AND r.exam_id = :exam
             ORDER BY s.last_name, s.first_name',
            ['tenant' => $this->app->tenant()->requireId(), 'exam' => $exam['id']]
        );

        $registered = array_column($registrations, 'student_id');

        // Seuls les eleves pas encore inscrits sont proposes.
        $candidates = array_values(array_filter(
            $this->table('students')
                ->select(['id', 'matricule', 'first_name', 'last_name'])
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            static fn (array $s): bool => ! in_array($s['id'], $registered, true)
        ));

        return $this->view('exams.show', [
            'exam' => $exam,
            'registrations' => $registrations,
            'candidates' => $candidates,
            'statuses' => self::STATUSES,
            'isOpen' => $this->registrationIsOpen($exam),
        ]);
    }

    public function register(Request $request): Response
    {
        $exam = $this->findOrFail('official_exams', (string) $request->attribute('id'));
        $back = '/exams/'.$exam['id'];

        // Hors periode, le centre d'examen refuserait le dossier : autant le
        // bloquer ici plutot que de le decouvrir a la cloture.
        if (! $this->registrationIsOpen($exam)) {
            return $this->redirectWithError($back, 'Les inscriptions a cet examen sont fermees.');
        }

        $studentId = $request->string('student_id');
        $student = $this->table('students')->find($studentId);

        if ($student === null) {
            return $this->redirectWithError($back, 'Eleve introuvable.');
        }

        $already = $this->table('exam_registrations')
            ->where('exam_id', (string) $exam['id'])
            ->where('student_id', $studentId)
            ->exists();

        if ($already) {
            return $this->redirectWithError($back, 'Cet eleve est deja inscrit a cet examen.');
        }

        $ageError = $this->checkAge($exam, (string) $student['date_of_birth']);

        if ($ageError !== null) {
            return $this->redirectWithError($back, $ageError);
        }

        $this->table('exam_registrations')->insert([
            'exam_id' => $exam['id'],
            'student_id' => $studentId,
            'registration_number' => $this->nextRegistrationNumber($exam),
            'center_name' => $request->string('center_name') ?: null,
            'series' => $request->string('series') ?: null,
            'status' => 'PENDING',
            'fee_paid' => $request->boolean('fee_paid') ? 1 : 0,
            'registered_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess($back, 'Candidat inscrit.');
    }

    public function updateRegistration(Request $request): Response
    {
        $registration = $this->findOrFail('exam_registrations', (string) $request->attribute('id'));
        $status = $request->string('status');

        if (! in_array($status, self::STATUSES, true)) {
            return $this->redirectWithError('/exams/'.$registration['exam_id'], 'Statut invalide.');
        }

        $this->table('exam_registrations')
            ->where('id', (string) $registration['id'])
            ->update([
                'status' => $status,
                'validated_by' => $this->currentUserId(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->redirectWithSuccess('/exams/'.$registration['exam_id'], 'Dossier mis a jour.');
    }

    /**
     * @param  array<string, mixed>  $exam
     */
    private function registrationIsOpen(array $exam): bool
    {
        $now = date('Y-m-d H:i:s');

        return (string) $exam['registration_start'] <= $now && $now <= (string) $exam['registration_end'];
    }

    /**
     * @param  array<string, mixed>  $exam
     */
    private function checkAge(array $exam, string $dateOfBirth): ?string
    {
        if ($exam['min_age'] === null && $exam['max_age'] === null) {
            return null;
        }

        $birth = strtotime($dateOfBirth);

        if ($birth === false) {
            return null;
        }

        $age = (int) ((time() - $birth) / (365.25 * 24 * 3600));

        if ($exam['min_age'] !== null && $age < (int) $exam['min_age']) {
            return sprintf('Age minimum requis : %d ans (candidat : %d ans).', (int) $exam['min_age'], $age);
        }

        if ($exam['max_age'] !== null && $age > (int) $exam['max_age']) {
            return sprintf('Age maximum autorise : %d ans (candidat : %d ans).', (int) $exam['max_age'], $age);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $exam
     */
    private function nextRegistrationNumber(array $exam): string
    {
        $count = $this->table('exam_registrations')->where('exam_id', (string) $exam['id'])->count();

        return sprintf('%s-%s-%04d', $exam['code'], date('Y'), $count + 1);
    }
}
