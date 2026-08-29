<?php
/**
 * Demande de paiement Mobile Money via pawaPay.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $invoice
 * @var array<string, mixed>|null $student
 * @var array<string, string> $providers
 * @var string $environment
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Paiement Mobile Money';
$balance = (float) $invoice['balance'];
?>
<div class="page-header">
    <div>
        <h1>Paiement Mobile Money</h1>
        <p class="subtitle">
            <?= $this->e(($student['last_name'] ?? '').' '.($student['first_name'] ?? '')) ?>
            &middot; solde <?= $this->money($balance) ?>
        </p>
    </div>
    <a class="button button--secondary" href="/finance/invoices/<?= $this->e($invoice['id']) ?>">Retour</a>
</div>

<?php if ($environment !== 'production') : ?>
    <div class="alert alert--error">
        Passerelle en mode <strong>bac a sable</strong> : aucun argent reel ne
        sera debite.
    </div>
<?php endif; ?>

<form method="post" action="/finance/invoices/<?= $this->e($invoice['id']) ?>/mobile-money" class="card">
    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

    <div class="grid-2">
        <div class="field">
            <label for="amount">Montant (FCFA) *</label>
            <input id="amount" name="amount" type="text" inputmode="numeric" required
                   value="<?= $this->e((string) (int) $balance) ?>">
        </div>
        <div class="field">
            <label for="provider">Operateur *</label>
            <select id="provider" name="provider" required>
                <?php foreach ($providers as $code => $label) : ?>
                    <option value="<?= $this->e($code) ?>"><?= $this->e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="phone_number">Numéro du payeur *</label>
            <input id="phone_number" name="phone_number" required placeholder="6XX XX XX XX">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="button">Envoyer la demande de paiement</button>
    </div>

    <p class="muted">
        La famille recevra une demande de validation sur son telephone. Le
        paiement n'est impute sur la facture qu'une fois cette validation
        confirmee par l'operateur : une demande envoyée n'est pas un encaissement.
    </p>
</form>
