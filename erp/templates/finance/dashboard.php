<?php
/**
 * @var \Scholaris\View\View $this
 * @var float $billed, $collected, $outstanding, $recoveryRate
 * @var array<string, array{count: int, amount: float}> $byStatus
 * @var array<int, array<string, mixed>> $recentPayments
 */
$this->extends('layouts.app');
$title = 'Finance';

$statusLabels = [
    'PENDING' => 'En attente',
    'PARTIAL' => 'Partiellement reglees',
    'PAID' => 'Soldees',
    'OVERDUE' => 'En retard',
];
?>
<div class="page-header">
    <div>
        <h1>Finance</h1>
        <p class="subtitle">Recouvrement de la scolarité sur l'année en cours</p>
    </div>
    <a class="button button--secondary" href="/finance/invoices">Toutes les factures</a>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Total facture</div>
        <div class="stat__value stat__value--money"><?= $this->money($billed) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Encaisse</div>
        <div class="stat__value stat__value--money"><?= $this->money($collected) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Reste a recouvrer</div>
        <div class="stat__value stat__value--money"><?= $this->money($outstanding) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Taux de recouvrement</div>
        <div class="stat__value"><?= $this->e(number_format($recoveryRate, 1, ',', ' ')) ?> %</div>
    </div>
</div>

<div class="card">
    <h2>Factures par statut</h2>
    <table class="table">
        <thead>
        <tr><th>Statut</th><th>Nombre</th><th>Solde</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($byStatus as $status => $data) : ?>
            <tr>
                <td><span class="badge"><?= $this->e($statusLabels[$status] ?? $status) ?></span></td>
                <td><?= $this->number($data['count']) ?></td>
                <td><?= $this->money($data['amount']) ?></td>
                <td><a href="/finance/invoices?status=<?= $this->e($status) ?>">Voir</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Derniers encaissements</h2>

    <?php if ($recentPayments === []) : ?>
        <p class="muted">Aucun paiement enregistré.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Reçu</th><th>Élève</th><th>Montant</th><th>Moyen</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentPayments as $payment) : ?>
                <tr>
                    <td><a href="/finance/receipts/<?= $this->e($payment['id']) ?>"><?= $this->e($payment['receipt_number']) ?></a></td>
                    <td><?= $this->e($payment['last_name'].' '.$payment['first_name']) ?></td>
                    <td><?= $this->money($payment['amount']) ?></td>
                    <td><?= $this->e($payment['method']) ?></td>
                    <td><?= $this->date($payment['paid_at'], 'd/m/Y H:i') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
