<?php
/**
 * Tableau de bord de l'intendance : le recouvrement, et ce qu'il reste a faire.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, float|int> $stats
 * @var array<int, array<string, mixed>> $recentPayments
 * @var array<int, array<string, mixed>> $topDebtors
 */
$this->extends('layouts.app');
$title = 'Intendance';
?>
<h1>Intendance</h1>
<p class="subtitle">Recouvrement de la scolarité sur l'année en cours</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Total facture</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['billed']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Encaisse</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['collected']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Reste a recouvrer</div>
        <div class="stat__value stat__value--money"><?= $this->money($stats['outstanding']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Taux de recouvrement</div>
        <div class="stat__value"><?= $this->e(number_format($stats['recovery'], 1, ',', ' ')) ?> %</div>
    </div>
    <div class="stat">
        <div class="stat__label">Factures en retard</div>
        <div class="stat__value"><?= $this->number($stats['overdue']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Jamais reglees</div>
        <div class="stat__value"><?= $this->number($stats['unpaid']) ?></div>
    </div>
</div>

<div class="card">
    <h2>Soldes les plus élèves</h2>
    <p class="muted" style="margin-top:-0.5rem">Les dossiers a relancer en priorite.</p>

    <?php if ($topDebtors === []) : ?>
        <p class="muted">Aucun solde en attente.</p>
    <?php else : ?>
        <table class="table">
            <thead><tr><th>Matricule</th><th>Élève</th><th>Solde</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($topDebtors as $debtor) : ?>
                <tr>
                    <td class="font-mono"><?= $this->e($debtor['matricule']) ?></td>
                    <td><?= $this->e($debtor['last_name'].' '.$debtor['first_name']) ?></td>
                    <td><strong><?= $this->money($debtor['balance']) ?></strong></td>
                    <td><a href="/finance/invoices/<?= $this->e($debtor['id']) ?>">Ouvrir la facture</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Derniers encaissements</h2>

    <?php if ($recentPayments === []) : ?>
        <p class="muted">Aucun paiement enregistré.</p>
    <?php else : ?>
        <table class="table">
            <thead><tr><th>Reçu</th><th>Élève</th><th>Montant</th><th>Moyen</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentPayments as $payment) : ?>
                <tr>
                    <td><a href="/finance/receipts/<?= $this->e($payment['id']) ?>"><?= $this->e($payment['receipt_number']) ?></a></td>
                    <td><?= $this->e($payment['last_name'].' '.$payment['first_name']) ?></td>
                    <td><?= $this->money($payment['amount']) ?></td>
                    <td><?= $this->e($payment['method']) ?></td>
                    <td class="font-mono"><?= $this->date($payment['paid_at'], 'd/m/Y H:i') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
