<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 18 : ressources humaines.
 *
 * Fiches du personnel et demandes de conge. La fiche RH est distincte du
 * compte de connexion : tout le personnel n'a pas acces a l'application, et un
 * depart ne doit pas effacer l'historique RH.
 */
final class HrController extends Controller
{
    private const LEAVE_STATUSES = ['PENDING', 'APPROVED', 'REJECTED'];

    public function index(Request $request): Response
    {
        $employees = $this->app->db()->select(
            'SELECT e.*, u.email
             FROM employees e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.tenant_id = :tenant
             ORDER BY e.last_name, e.first_name',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $leaves = $this->app->db()->select(
            'SELECT l.*, e.first_name, e.last_name, e.position
             FROM leave_requests l
             INNER JOIN employees e ON e.id = l.employee_id
             WHERE l.tenant_id = :tenant
             ORDER BY l.created_at DESC
             LIMIT 50',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('hr.index', [
            'employees' => $employees,
            'leaves' => $leaves,
            'pending' => count(array_filter($leaves, static fn (array $l): bool => $l['status'] === 'PENDING')),
            'payroll' => $this->table('employees')->where('status', 'ACTIVE')->sum('salary'),
            'users' => $this->table('users')
                ->select(['id', 'first_name', 'last_name', 'email'])
                ->notDeleted()
                ->orderBy('last_name')
                ->get(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeEmployee(Request $request): Response
    {
        $userIds = array_map(
            static fn (array $u): string => (string) $u['id'],
            $this->table('users')->select(['id'])->notDeleted()->get()
        );

        $validator = (new Validator($request))
            ->required('first_name', 'prenom')
            ->required('last_name', 'nom')
            ->required('position', 'fonction')
            ->date('hire_date', 'date d embauche')
            ->optional('department')
            // Le lien vers un compte est facultatif : une partie du personnel
            // n'utilise pas l'application.
            ->in('user_id', 'compte de connexion', $userIds, false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/hr', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['first_name', 'last_name', 'position', 'hire_date', 'department', 'user_id']);

        $salary = str_replace(',', '.', $request->string('salary'));
        $data['salary'] = is_numeric($salary) ? number_format((float) $salary, 2, '.', '') : null;
        $data['status'] = 'ACTIVE';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('employees')->insert($data);

        return $this->redirectWithSuccess('/hr', 'Fiche personnel creee.');
    }

    public function storeLeave(Request $request): Response
    {
        $employeeIds = array_map(
            static fn (array $e): string => (string) $e['id'],
            $this->table('employees')->select(['id'])->get()
        );

        $validator = (new Validator($request))
            ->in('employee_id', 'employe', $employeeIds)
            ->date('start_date', 'date de debut')
            ->date('end_date', 'date de fin')
            ->required('reason', 'motif');

        if ($validator->fails()) {
            return $this->redirectWithError('/hr', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['employee_id', 'start_date', 'end_date', 'reason']);

        if ($data['end_date'] < $data['start_date']) {
            return $this->redirectWithError('/hr', 'La date de fin doit suivre la date de debut.');
        }

        $data['status'] = 'PENDING';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('leave_requests')->insert($data);

        return $this->redirectWithSuccess('/hr', 'Demande de conge enregistree.');
    }

    public function decideLeave(Request $request): Response
    {
        $leave = $this->findOrFail('leave_requests', (string) $request->attribute('id'));
        $decision = $request->string('decision');

        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            return $this->redirectWithError('/hr', 'Decision invalide.');
        }

        if ($leave['status'] !== 'PENDING') {
            return $this->redirectWithError('/hr', 'Cette demande a deja ete traitee.');
        }

        $this->table('leave_requests')
            ->where('id', (string) $leave['id'])
            ->update(['status' => $decision, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->redirectWithSuccess(
            '/hr',
            $decision === 'APPROVED' ? 'Conge approuve.' : 'Conge refuse.'
        );
    }
}
