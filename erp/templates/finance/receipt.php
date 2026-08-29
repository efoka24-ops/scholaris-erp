<?php
/**
 * Recu d encaissement, concu pour etre imprime et remis a la famille.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $payment
 * @var array<string, mixed>|null $student
 * @var array<string, mixed>|null $invoice
 * @var array<string, mixed>|null $tenant
 */
$this->extends('layouts.app');
$title = 'Reçu '.$payment['receipt_number'];

$methodLabels = [
    'CASH' => 'Especes',
    'MOBILE_MONEY' => 'Mobile Money',
    'BANK_TRANSFER' => 'Virement bancaire',
    'CHECK' => 'Cheque',
];
?>
<div class="page-header">
    <div>
        <h1>Reçu de paiement</h1>
        <p class="subtitle">Numéro <?= $this->e($payment['receipt_number']) ?></p>
    </div>
    <div class="form-actions">
        <button type="button" class="button" onclick="window.print()">Imprimer</button>
        <a class="button button--secondary" href="/finance/invoices/<?= $this->e($payment['invoice_id']) ?>">Retour</a>
    </div>
</div>

<div class="card">
    <h2><?= $this->e($tenant['name'] ?? '') ?></h2>
    <p class="muted">
        <?= $this->e($tenant['address'] ?? '') ?>
        <?php if (! empty($tenant['phone'])) : ?>
            &middot; <?= $this->e($tenant['phone']) ?>
        <?php endif; ?>
    </p>

    <dl class="details" style="margin-top:1.25rem">
        <dt>Reçu numéro</dt><dd><strong><?= $this->e($payment['receipt_number']) ?></strong></dd>
        <dt>Date</dt><dd><?= $this->date($payment['paid_at'], 'd/m/Y a H:i') ?></dd>
        <dt>Élève</dt>
        <dd>
            <?= $this->e(($student['last_name'] ?? '').' '.($student['first_name'] ?? '')) ?>
            (<?= $this->e($student['matricule'] ?? '-') ?>)
        </dd>
        <dt>Montant reçu</dt><dd><strong><?= $this->money($payment['amount']) ?></strong></dd>
        <dt>Moyen de paiement</dt>
        <dd><?= $this->e($methodLabels[$payment['method']] ?? $payment['method']) ?></dd>
        <?php if (! empty($payment['reference'])) : ?>
            <dt>Référence</dt><dd><?= $this->e($payment['reference']) ?></dd>
        <?php endif; ?>
        <?php if ($invoice !== null) : ?>
            <dt>Total de la scolarité</dt><dd><?= $this->money($invoice['total_amount']) ?></dd>
            <dt>Cumul règle</dt><dd><?= $this->money($invoice['paid_amount']) ?></dd>
            <dt>Solde restant</dt><dd><strong><?= $this->money($invoice['balance']) ?></strong></dd>
        <?php endif; ?>
    </dl>

    <p class="muted" style="margin-top:1.5rem">
        Ce reçu fait foi du paiement enregistré a la date indiquee.
        A conserver par la famille.
    </p>
</div>
