<?php
/**
 * Suivi d une demande de paiement Mobile Money.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $transaction
 * @var array<string, mixed>|null $invoice
 * @var array<string, string> $providers
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Demande de paiement';

$status = (string) $transaction['status'];
$isFinal = in_array($status, ['SUCCESS', 'FAILED', 'REJECTED'], true);

$statusLabels = [
    'PENDING' => 'Initialisation',
    'PROCESSING' => 'En attente de validation par la famille',
    'SUCCESS' => 'Paiement encaisse',
    'FAILED' => 'Paiement echoue',
    'REJECTED' => 'Demande refusee par la passerelle',
    'RECONCILIATION' => 'En cours de reconciliation',
];
?>
<div class="page-header">
    <div>
        <h1>Demande de paiement</h1>
        <p class="subtitle">
            <?= $this->money($transaction['amount']) ?>
            &middot; <?= $this->e($providers[$transaction['network']] ?? $transaction['network']) ?>
            &middot; <?= $this->e($transaction['phone_number']) ?>
        </p>
    </div>
    <?php if ($invoice !== null) : ?>
        <a class="button button--secondary" href="/finance/invoices/<?= $this->e($invoice['id']) ?>">
            Voir la facture
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Statut</h2>

    <?php if ($status === 'SUCCESS') : ?>
        <div class="alert alert--success">
            <?= $this->e($statusLabels[$status]) ?>. Le solde de la facture a ete
            mis a jour et un recu a ete emis.
        </div>
    <?php elseif ($isFinal) : ?>
        <div class="alert alert--error"><?= $this->e($statusLabels[$status] ?? $status) ?></div>
    <?php else : ?>
        <div class="alert"><?= $this->e($statusLabels[$status] ?? $status) ?></div>
    <?php endif; ?>

    <dl class="details">
        <dt>Reference</dt><dd><?= $this->e($transaction['external_reference']) ?></dd>
        <dt>Montant</dt><dd><?= $this->money($transaction['amount']) ?></dd>
        <dt>Numero</dt><dd><?= $this->e($transaction['phone_number']) ?></dd>
        <dt>Demande le</dt><dd><?= $this->date($transaction['created_at'], 'd/m/Y H:i') ?></dd>
        <?php if ($transaction['notified_at']) : ?>
            <dt>Dernier retour</dt><dd><?= $this->date($transaction['notified_at'], 'd/m/Y H:i') ?></dd>
        <?php endif; ?>
    </dl>

    <?php if (! $isFinal) : ?>
        <form method="post" action="/finance/transactions/<?= $this->e($transaction['id']) ?>/refresh"
              style="margin-top:1rem">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <button type="submit" class="button button--secondary">Verifier le statut</button>
        </form>

        <p class="muted">
            Le statut definitif arrive normalement de lui-meme, par notification
            de la passerelle. Ce bouton sert quand cette notification tarde.
        </p>
    <?php endif; ?>
</div>
