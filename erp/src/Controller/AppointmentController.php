<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 39 : rendez-vous.
 *
 * Sert surtout aux entretiens parents-enseignants et aux receptions par la
 * direction. Le module refuse deux rendez-vous qui se chevauchent pour le
 * meme agent : c'est la seule regle qui compte vraiment ici, parce qu'un
 * double rendez-vous se traduit par une famille qui attend dans un couloir.
 */
final class AppointmentController extends Controller
{
    private const STATUSES = ['DEMANDE', 'CONFIRME', 'HONORE', 'ANNULE'];

    public function index(Request $request): Response
    {
        $status = $request->string('statut');
        $appointments = $this->app->db();

        $sql = 'SELECT a.*, u.first_name AS staff_first, u.last_name AS staff_last
                FROM appointments a
                INNER JOIN users u ON u.id = a.staff_id
                WHERE a.tenant_id = :tenant';

        $params = ['tenant' => $this->app->tenant()->requireId()];

        if (in_array($status, self::STATUSES, true)) {
            $sql .= ' AND a.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY a.scheduled_at DESC LIMIT 150';

        $rows = $appointments->select($sql, $params);
        $now = date('Y-m-d H:i:s');

        // Les rendez-vous a venir et confirmes sont ce qu'on regarde en
        // arrivant le matin ; le reste est de la consultation.
        $upcoming = array_values(array_filter(
            $rows,
            static fn (array $a): bool => (string) $a['scheduled_at'] >= $now
                && in_array((string) $a['status'], ['DEMANDE', 'CONFIRME'], true)
        ));

        return $this->view('appointments.index', [
            'appointments' => $rows,
            'upcoming' => $upcoming,
            'status' => $status,
            'statuses' => self::STATUSES,
            'staff' => $this->table('users')
                ->select(['id', 'first_name', 'last_name'])
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'students' => $this->table('students')
                ->select(['id', 'matricule', 'first_name', 'last_name'])
                ->notDeleted()
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')
                ->get(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function store(Request $request): Response
    {
        $staffIds = array_map(
            static fn (array $u): string => (string) $u['id'],
            $this->table('users')->select(['id'])->where('status', 'ACTIVE')->get()
        );

        $studentIds = array_map(
            static fn (array $s): string => (string) $s['id'],
            $this->table('students')->select(['id'])->notDeleted()->get()
        );

        $validator = (new Validator($request))
            ->in('staff_id', 'personne rencontree', $staffIds)
            ->required('requester_name', 'demandeur')
            ->required('subject', 'objet')
            ->required('scheduled_at', 'date et heure')
            ->integer('duration_minutes', 'duree', 5, 480)
            ->optional('location')
            ->optional('notes')
            ->in('student_id', 'eleve concerne', $studentIds, false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/rendez-vous', implode(' ', $validator->errors()));
        }

        $data = $validator->only([
            'staff_id', 'requester_name', 'subject', 'scheduled_at',
            'duration_minutes', 'location', 'notes', 'student_id',
        ]);

        $start = $this->normalizeDateTime((string) $data['scheduled_at']);

        if ($start === null) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/rendez-vous', 'Date et heure incomprehensibles.');
        }

        $duration = (int) $data['duration_minutes'];

        if ($this->overlaps((string) $data['staff_id'], $start, $duration)) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError(
                '/rendez-vous',
                'Un autre rendez-vous occupe deja ce creneau pour cette personne.'
            );
        }

        $now = date('Y-m-d H:i:s');

        $this->table('appointments')->insert([
            'staff_id' => $data['staff_id'],
            'requester_id' => $this->currentUserId(),
            'requester_name' => $data['requester_name'],
            'student_id' => $data['student_id'] ?? null,
            'subject' => $data['subject'],
            'scheduled_at' => $start,
            'duration_minutes' => $duration,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'DEMANDE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->redirectWithSuccess('/rendez-vous', 'Rendez-vous enregistre.');
    }

    /**
     * Un rendez-vous en chevauche-t-il un autre pour le meme agent ?
     *
     * Les rendez-vous annules ne comptent pas : leur creneau est libere.
     */
    private function overlaps(string $staffId, string $start, int $duration): bool
    {
        $end = date('Y-m-d H:i:s', strtotime($start) + $duration * 60);

        $rows = $this->app->db()->select(
            'SELECT scheduled_at, duration_minutes FROM appointments
             WHERE tenant_id = :tenant AND staff_id = :staff AND status <> :cancelled',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'staff' => $staffId,
                'cancelled' => 'ANNULE',
            ]
        );

        foreach ($rows as $row) {
            $otherStart = (string) $row['scheduled_at'];
            $otherEnd = date(
                'Y-m-d H:i:s',
                strtotime($otherStart) + (int) $row['duration_minutes'] * 60
            );

            // Deux creneaux se chevauchent des que chacun commence avant que
            // l'autre ne finisse. Deux rendez-vous bout a bout ne se
            // chevauchent pas : la comparaison est stricte.
            if ($start < $otherEnd && $otherStart < $end) {
                return true;
            }
        }

        return false;
    }

    /** Accepte « 2026-09-01T14:30 » comme « 2026-09-01 14:30:00 ». */
    private function normalizeDateTime(string $value): ?string
    {
        $timestamp = strtotime(str_replace('T', ' ', trim($value)));

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    public function updateStatus(Request $request): Response
    {
        $appointment = $this->findOrFail('appointments', (string) $request->attribute('id'));

        $validator = (new Validator($request))->in('status', 'statut', self::STATUSES);

        if ($validator->fails()) {
            return $this->redirectWithError('/rendez-vous', implode(' ', $validator->errors()));
        }

        $status = (string) $validator->validated()['status'];

        $this->table('appointments')
            ->where('id', (string) $appointment['id'])
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->trail()->changed(
            'RENDEZVOUS_STATUT',
            'appointments',
            (string) $appointment['id'],
            ['status' => $appointment['status']],
            ['status' => $status]
        );

        return $this->redirectWithSuccess('/rendez-vous', 'Rendez-vous mis a jour.');
    }
}
