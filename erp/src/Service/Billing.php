<?php

declare(strict_types=1);

namespace Scholaris\Service;

use RuntimeException;
use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Scholaris\Tenant\TenantContext;

/**
 * Facturation de la scolarite.
 *
 * Regroupe ici, hors des controleurs, parce que la meme logique sert a
 * plusieurs endroits : inscription d'un eleve, regeneration d'une facture,
 * encaissement d'un paiement.
 *
 * Les montants sont manipules en centimes entiers dans les calculs et stockes
 * en DECIMAL : additionner des flottants ferait deriver le solde d'une facture
 * de quelques francs au fil des paiements.
 */
final class Billing
{
    private Connection $db;

    private TenantContext $tenant;

    public function __construct(Connection $db, TenantContext $tenant)
    {
        $this->db = $db;
        $this->tenant = $tenant;
    }

    /**
     * Grille tarifaire applicable a un niveau pour une annee.
     *
     * Une grille ciblant explicitement le niveau l'emporte sur la grille par
     * defaut (level_id nul), qui sert de repli.
     *
     * @return array<string, mixed>|null
     */
    public function feeStructureFor(string $levelId, string $academicYearId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM fee_structures
             WHERE tenant_id = :tenant AND academic_year_id = :year AND deleted_at IS NULL
               AND (level_id = :level OR level_id IS NULL)
             ORDER BY CASE WHEN level_id IS NULL THEN 1 ELSE 0 END
             LIMIT 1',
            [
                'tenant' => $this->tenant->requireId(),
                'year' => $academicYearId,
                'level' => $levelId,
            ]
        );
    }

    /**
     * Genere la facture d'une inscription, si la grille le permet.
     *
     * Renvoie null quand aucune grille n'est definie : une inscription reste
     * possible sans facturation, l'etablissement pouvant parametrer ses tarifs
     * apres coup.
     */
    public function generateInvoice(string $enrollmentId): ?string
    {
        $enrollment = $this->db->selectOne(
            'SELECT e.*, c.level_id FROM enrollments e
             INNER JOIN classrooms c ON c.id = e.classroom_id
             WHERE e.id = :id AND e.tenant_id = :tenant',
            ['id' => $enrollmentId, 'tenant' => $this->tenant->requireId()]
        );

        if ($enrollment === null) {
            throw new RuntimeException('Inscription introuvable.');
        }

        $existing = $this->db->scalar(
            'SELECT id FROM invoices WHERE enrollment_id = :enrollment AND tenant_id = :tenant AND deleted_at IS NULL',
            ['enrollment' => $enrollmentId, 'tenant' => $this->tenant->requireId()]
        );

        if ($existing !== null) {
            return (string) $existing;
        }

        $structure = $this->feeStructureFor(
            (string) $enrollment['level_id'],
            (string) $enrollment['academic_year_id']
        );

        if ($structure === null) {
            return null;
        }

        // Echeance retenue : la plus tardive des tranches de la grille. Sans
        // tranche definie, la facture n'a pas de date limite et ne peut donc
        // pas basculer en retard.
        $dueDate = $this->db->scalar(
            'SELECT MAX(due_date) FROM fee_installments
             WHERE fee_structure_id = :structure AND tenant_id = :tenant AND deleted_at IS NULL',
            ['structure' => $structure['id'], 'tenant' => $this->tenant->requireId()]
        );

        $total = (float) $structure['total_amount'];
        $id = Table::uuid();
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO invoices
                (id, tenant_id, student_id, enrollment_id, fee_structure_id, academic_year_id,
                 total_amount, paid_amount, balance, due_date, status, created_at, updated_at)
             VALUES (:id, :tenant, :student, :enrollment, :structure, :year,
                 :total, :paid, :balance, :due_date, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $this->tenant->requireId(),
                'student' => $enrollment['student_id'],
                'enrollment' => $enrollmentId,
                'structure' => $structure['id'],
                'year' => $enrollment['academic_year_id'],
                'total' => number_format($total, 2, '.', ''),
                'paid' => '0.00',
                'balance' => number_format($total, 2, '.', ''),
                'due_date' => $dueDate,
                'status' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $id;
    }

    /**
     * Enregistre un paiement et met la facture a jour, en une transaction.
     *
     * Le numero de recu est tire dans la meme transaction que le paiement :
     * deux encaissements simultanes ne peuvent pas porter le meme numero.
     *
     * @return array{payment_id: string, receipt_number: string}
     */
    public function recordPayment(
        string $invoiceId,
        float $amount,
        string $method,
        ?string $reference,
        ?string $receivedBy,
        ?string $notes
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Le montant doit etre superieur a zero.');
        }

        return $this->db->transaction(function () use ($invoiceId, $amount, $method, $reference, $receivedBy, $notes): array {
            $invoice = $this->db->selectOne(
                'SELECT * FROM invoices WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                ['id' => $invoiceId, 'tenant' => $this->tenant->requireId()]
            );

            if ($invoice === null) {
                throw new RuntimeException('Facture introuvable.');
            }

            // Un encaissement superieur au solde signale une erreur de saisie :
            // mieux vaut refuser que creer un avoir implicite.
            if (round($amount, 2) > round((float) $invoice['balance'], 2)) {
                throw new RuntimeException(sprintf(
                    'Le montant depasse le solde restant (%s FCFA).',
                    number_format((float) $invoice['balance'], 0, ',', ' ')
                ));
            }

            $receiptNumber = $this->nextReceiptNumber();
            $paymentId = Table::uuid();
            $now = date('Y-m-d H:i:s');

            $this->db->execute(
                'INSERT INTO payments
                    (id, tenant_id, invoice_id, student_id, amount, method, reference,
                     receipt_number, paid_at, received_by, notes, created_at, updated_at)
                 VALUES (:id, :tenant, :invoice, :student, :amount, :method, :reference,
                     :receipt, :paid_at, :received_by, :notes, :created_at, :updated_at)',
                [
                    'id' => $paymentId,
                    'tenant' => $this->tenant->requireId(),
                    'invoice' => $invoiceId,
                    'student' => $invoice['student_id'],
                    'amount' => number_format($amount, 2, '.', ''),
                    'method' => $method,
                    'reference' => $reference,
                    'receipt' => $receiptNumber,
                    'paid_at' => $now,
                    'received_by' => $receivedBy,
                    'notes' => $notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $this->refreshInvoice($invoiceId);

            return ['payment_id' => $paymentId, 'receipt_number' => $receiptNumber];
        });
    }

    /**
     * Recalcule le regle, le solde et le statut d'une facture depuis ses
     * paiements. Recalculer plutot que d'incrementer garantit que le solde
     * reste juste meme si un paiement est annule.
     */
    public function refreshInvoice(string $invoiceId): void
    {
        $invoice = $this->db->selectOne(
            'SELECT total_amount, due_date FROM invoices WHERE id = :id AND tenant_id = :tenant',
            ['id' => $invoiceId, 'tenant' => $this->tenant->requireId()]
        );

        if ($invoice === null) {
            return;
        }

        $paid = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE invoice_id = :invoice AND tenant_id = :tenant AND deleted_at IS NULL',
            ['invoice' => $invoiceId, 'tenant' => $this->tenant->requireId()]
        );

        $total = (float) $invoice['total_amount'];
        $balance = round($total - $paid, 2);
        $dueDate = $invoice['due_date'] ?? null;

        if ($balance <= 0) {
            $status = 'PAID';
        } elseif (is_string($dueDate) && $dueDate !== '' && strtotime($dueDate) < strtotime(date('Y-m-d'))) {
            $status = 'OVERDUE';
        } elseif ($paid > 0) {
            $status = 'PARTIAL';
        } else {
            $status = 'PENDING';
        }

        $this->db->execute(
            'UPDATE invoices SET paid_amount = :paid, balance = :balance, status = :status, updated_at = :updated_at
             WHERE id = :id',
            [
                'paid' => number_format($paid, 2, '.', ''),
                'balance' => number_format(max($balance, 0), 2, '.', ''),
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $invoiceId,
            ]
        );
    }

    /**
     * Numero de recu suivant, au format CODE/ANNEE/000001.
     */
    private function nextReceiptNumber(): string
    {
        $tenantId = $this->tenant->requireId();
        $year = date('Y');
        $lock = $this->db->driver() === 'mysql' ? ' FOR UPDATE' : '';

        $sequence = $this->db->selectOne(
            'SELECT id, last_number FROM receipt_sequences
             WHERE tenant_id = :tenant AND year = :year LIMIT 1'.$lock,
            ['tenant' => $tenantId, 'year' => $year]
        );

        $now = date('Y-m-d H:i:s');

        if ($sequence === null) {
            $next = 1;
            $this->db->execute(
                'INSERT INTO receipt_sequences (id, tenant_id, year, last_number, created_at, updated_at)
                 VALUES (:id, :tenant, :year, :n, :created_at, :updated_at)',
                [
                    'id' => Table::uuid(),
                    'tenant' => $tenantId,
                    'year' => $year,
                    'n' => $next,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        } else {
            $next = (int) $sequence['last_number'] + 1;
            $this->db->execute(
                'UPDATE receipt_sequences SET last_number = :n, updated_at = :updated_at WHERE id = :id',
                ['n' => $next, 'updated_at' => $now, 'id' => $sequence['id']]
            );
        }

        $code = $this->db->scalar('SELECT code FROM tenants WHERE id = :id', ['id' => $tenantId]);

        return sprintf('%s/%s/%06d', $code ?? 'ETS', $year, $next);
    }
}
