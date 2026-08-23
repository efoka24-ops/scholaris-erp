<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use RuntimeException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\Billing;
use Scholaris\Support\Validator;

/**
 * Module 7 : facturation et encaissements.
 */
final class FinanceController extends Controller
{
    private const PER_PAGE = 30;

    private const METHODS = ['CASH', 'MOBILE_MONEY', 'BANK_TRANSFER', 'CHECK'];

    private const INVOICE_STATUSES = ['PENDING', 'PARTIAL', 'PAID', 'OVERDUE'];

    public function dashboard(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        $base = fn () => $academicYearId === null
            ? $this->table('invoices')->notDeleted()
            : $this->table('invoices')->notDeleted()->where('academic_year_id', $academicYearId);

        $byStatus = [];

        foreach (self::INVOICE_STATUSES as $status) {
            $byStatus[$status] = [
                'count' => $base()->where('status', $status)->count(),
                'amount' => $base()->where('status', $status)->sum('balance'),
            ];
        }

        $billed = $base()->sum('total_amount');
        $collected = $base()->sum('paid_amount');

        return $this->view('finance.dashboard', [
            'billed' => $billed,
            'collected' => $collected,
            'outstanding' => $base()->sum('balance'),
            // Taux de recouvrement : indicateur suivi de pres par l'intendance.
            'recoveryRate' => $billed > 0 ? round($collected / $billed * 100, 1) : 0.0,
            'byStatus' => $byStatus,
            'recentPayments' => $this->app->db()->select(
                'SELECT p.*, s.first_name, s.last_name, s.matricule
                 FROM payments p INNER JOIN students s ON s.id = p.student_id
                 WHERE p.tenant_id = :tenant AND p.deleted_at IS NULL
                 ORDER BY p.paid_at DESC LIMIT 10',
                ['tenant' => $this->app->tenant()->requireId()]
            ),
        ]);
    }

    public function invoices(Request $request): Response
    {
        $status = $request->string('status');
        $search = $request->string('q');
        $page = max($request->int('page', 1), 1);

        $conditions = ['i.tenant_id = :tenant', 'i.deleted_at IS NULL'];
        $params = ['tenant' => $this->app->tenant()->requireId()];

        if (in_array($status, self::INVOICE_STATUSES, true)) {
            $conditions[] = 'i.status = :status';
            $params['status'] = $status;
        }

        if ($search !== '') {
            $conditions[] = '(s.last_name LIKE :search OR s.first_name LIKE :search2 OR s.matricule LIKE :search3)';
            $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $params['search'] = $escaped;
            $params['search2'] = $escaped;
            $params['search3'] = $escaped;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) $this->app->db()->scalar(
            "SELECT COUNT(*) FROM invoices i INNER JOIN students s ON s.id = i.student_id WHERE {$where}",
            $params
        );

        $invoices = $this->app->db()->select(
            "SELECT i.*, s.matricule, s.first_name, s.last_name, c.name AS classroom_name
             FROM invoices i
             INNER JOIN students s ON s.id = i.student_id
             LEFT JOIN enrollments e ON e.id = i.enrollment_id
             LEFT JOIN classrooms c ON c.id = e.classroom_id
             WHERE {$where}
             ORDER BY i.created_at DESC
             LIMIT ".self::PER_PAGE.' OFFSET '.(($page - 1) * self::PER_PAGE),
            $params
        );

        return $this->view('finance.invoices', [
            'invoices' => $invoices,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'status' => $status,
            'search' => $search,
            'statuses' => self::INVOICE_STATUSES,
        ]);
    }

    public function invoice(Request $request): Response
    {
        $invoice = $this->findOrFail('invoices', (string) $request->attribute('id'));

        return $this->view('finance.invoice', [
            'invoice' => $invoice,
            'student' => $this->table('students')->find((string) $invoice['student_id']),
            'payments' => $this->app->db()->select(
                'SELECT p.*, u.first_name AS agent_first, u.last_name AS agent_last
                 FROM payments p LEFT JOIN users u ON u.id = p.received_by
                 WHERE p.invoice_id = :invoice AND p.tenant_id = :tenant AND p.deleted_at IS NULL
                 ORDER BY p.paid_at DESC',
                ['invoice' => $invoice['id'], 'tenant' => $this->app->tenant()->requireId()]
            ),
            'methods' => self::METHODS,
        ]);
    }

    public function storePayment(Request $request): Response
    {
        $invoice = $this->findOrFail('invoices', (string) $request->attribute('id'));

        $validator = (new Validator($request))
            ->decimal('amount', 'montant', 1)
            ->in('method', 'moyen de paiement', self::METHODS)
            ->optional('reference')
            ->optional('notes');

        if ($validator->fails()) {
            return $this->redirectWithError(
                '/finance/invoices/'.$invoice['id'],
                implode(' ', $validator->errors())
            );
        }

        $data = $validator->validated();
        $billing = new Billing($this->app->db(), $this->app->tenant());

        try {
            $result = $billing->recordPayment(
                (string) $invoice['id'],
                (float) $data['amount'],
                (string) $data['method'],
                $data['reference'],
                $this->currentUserId(),
                $data['notes']
            );
        } catch (RuntimeException $e) {
            // Depassement du solde ou facture introuvable : message metier,
            // pas une panne technique.
            return $this->redirectWithError('/finance/invoices/'.$invoice['id'], $e->getMessage());
        }

        return $this->redirectWithSuccess(
            '/finance/invoices/'.$invoice['id'],
            'Paiement enregistre. Recu numero '.$result['receipt_number'].'.'
        );
    }

    public function receipt(Request $request): Response
    {
        $payment = $this->findOrFail('payments', (string) $request->attribute('id'));

        return $this->view('finance.receipt', [
            'payment' => $payment,
            'student' => $this->table('students')->find((string) $payment['student_id']),
            'invoice' => $this->table('invoices')->find((string) $payment['invoice_id']),
            'tenant' => $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
                'SELECT * FROM tenants WHERE id = :id',
                ['id' => $this->app->tenant()->requireId()]
            )),
        ]);
    }

    // --- Grilles tarifaires --------------------------------------------------

    public function feeStructures(Request $request): Response
    {
        return $this->view('finance.fee-structures', [
            'structures' => $this->app->db()->select(
                'SELECT f.*, l.name AS level_name, y.label AS year_label,
                        (SELECT COUNT(*) FROM fee_installments fi
                         WHERE fi.fee_structure_id = f.id AND fi.deleted_at IS NULL) AS installments
                 FROM fee_structures f
                 LEFT JOIN levels l ON l.id = f.level_id
                 INNER JOIN academic_years y ON y.id = f.academic_year_id
                 WHERE f.tenant_id = :tenant AND f.deleted_at IS NULL
                 ORDER BY y.label DESC, l.sort_order',
                ['tenant' => $this->app->tenant()->requireId()]
            ),
            'levels' => $this->table('levels')->orderBy('sort_order')->get(),
            'academicYearId' => $this->currentAcademicYearId(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeFeeStructure(Request $request): Response
    {
        $academicYearId = $this->currentAcademicYearId();

        if ($academicYearId === null) {
            return $this->redirectWithError('/finance/fee-structures', 'Aucune annee academique active.');
        }

        $levelIds = array_map(
            static fn (array $l): string => (string) $l['id'],
            $this->table('levels')->get()
        );

        $validator = (new Validator($request))
            ->required('name', 'libelle')
            ->decimal('total_amount', 'montant total', 0)
            // level_id vide = grille par defaut, applicable a tous les niveaux.
            ->in('level_id', 'niveau', $levelIds, false);

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/finance/fee-structures', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['name', 'total_amount', 'level_id']);
        $now = date('Y-m-d H:i:s');

        $this->table('fee_structures')->insert([
            'name' => $data['name'],
            'level_id' => $data['level_id'],
            'academic_year_id' => $academicYearId,
            'total_amount' => number_format((float) $data['total_amount'], 2, '.', ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->redirectWithSuccess('/finance/fee-structures', 'Grille tarifaire enregistree.');
    }
}
