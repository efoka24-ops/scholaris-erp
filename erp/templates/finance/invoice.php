<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $invoice
 * @var array<string, mixed>|null $student
 * @var array<int, array<string, mixed>> $payments
 * @var list<string> $methods
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Facture';

$methodLabels = [
    'CASH' => 'Especes',
    'MOBILE_MONEY' => 'Mobile Money',
    'BANK_TRANSFER' => 'Virement bancaire',
    'CHECK' => 'Cheque',
];

$balance = (float) $invoice['balance'];
?>
<div class="page-header">
    <div>
        <h1><?= $this->e(($student['last_name'] ?? '').' '.($student['first_name'] ?? '')) ?></h1>
        <p class="subtitle">
            Matricule <?= $this->e($student['matricule'] ?? '-') ?>
            &middot; <span class="badge"><?= $this->e($invoice['status']) ?></span>
        </p>
    </div>
    <a class="button button--secondary" href="/finance/invoices">Retour</a>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Total</div>
        <div class="stat__value stat__value--money"><?= $this->money($invoice['total_amount']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Regle</div>
        <div class="stat__value stat__value--money"><?= $this->money($invoice['paid_amount']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Solde</div>
        <div class="stat__value stat__value--money"><?= $this->money($balance) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Echeance</div>
        <div class="stat__value" style="font-size:1.1rem"><?= $this->date($invoice['due_date']) ?></div>
    </div>
</div>

<?php if ($balance > 0 && $rbac->allows('payments:create')) : ?>
    <div class="card">
        <h2>Enregistrer un encaissement</h2>

        <form method="post" action="/finance/invoices/<?= $this->e($invoice['id']) ?>/payments">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

            <div class="grid-2">
                <div class="field">
                    <label for="amount">Montant (FCFA) *</label>
                    <input id="amount" name="amount" type="text" inputmode="numeric" required
                           value="<?= $this->e((string) (int) $balance) ?>">
                </div>
                <div class="field">
                    <label for="method">Moyen de paiement *</label>
                    <select id="method" name="method" required>
                        <?php foreach ($methods as $method) : ?>
                            <option value="<?= $this->e($method) ?>">
                                <?= $this->e($methodLabels[$method] ?? $method) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="reference">Reference</label>
                    <input id="reference" name="reference" placeholder="Numero de transaction, de cheque...">
                </div>
                <div class="field">
                    <label for="notes">Observation</label>
                    <input id="notes" name="notes">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="button">Encaisser</button>
                <a class="button button--secondary"
                   href="/finance/invoices/<?= $this->e($invoice['id']) ?>/mobile-money">
                    Demander un paiement Mobile Money
                </a>
            </div>
        </form>

        <p class="muted">
            Un encaissement superieur au solde est refuse : c est presque toujours
            une erreur de saisie.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Encaissements</h2>

    <?php if ($payments === []) : ?>
        <p class="muted">Aucun encaissement enregistre sur cette facture.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Recu</th><th>Montant</th><th>Moyen</th><th>Reference</th><th>Encaisse par</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $payment) : ?>
                <tr>
                    <td><a href="/finance/receipts/<?= $this->e($payment['id']) ?>"><?= $this->e($payment['receipt_number']) ?></a></td>
                    <td><?= $this->money($payment['amount']) ?></td>
                    <td><?= $this->e($methodLabels[$payment['method']] ?? $payment['method']) ?></td>
                    <td><?= $this->e($payment['reference'] ?: '-') ?></td>
                    <td>
                        <?php if ($payment['agent_last']) : ?>
                            <?= $this->e($payment['agent_last'].' '.$payment['agent_first']) ?>
                        <?php else : ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->date($payment['paid_at'], 'd/m/Y H:i') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
