<?php
/**
 * Detail d'une commande d'achat, avec les gestes de decision et de reception.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $supplier
 * @var array<int, array<string, mixed>> $lines
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Commande '.$order['reference'];
$status = (string) $order['status'];
?>
<h1>Commande <span class="font-mono"><?= $this->e($order['reference']) ?></span></h1>
<p class="subtitle">
    <?= $this->e($order['subject']) ?> &middot;
    <?= $this->date($order['ordered_on']) ?>
    <?php if ($status === 'APPROUVEE') : ?>
        <span class="badge badge--success">Approuvée</span>
    <?php elseif ($status === 'REFUSEE') : ?>
        <span class="badge badge--danger">Refusée</span>
    <?php elseif ($status === 'RECUE') : ?>
        <span class="badge badge--neutral">Reçue</span>
    <?php else : ?>
        <span class="badge badge--warning">En attente de décision</span>
    <?php endif; ?>
</p>

<?php if ($supplier !== null) : ?>
    <div class="card">
        <h2>Fournisseur</h2>
        <p>
            <strong><?= $this->e($supplier['name']) ?></strong><br>
            <?= $this->e($supplier['contact_name']) ?>
            <?php if ($supplier['phone'] !== null) : ?>
                &middot; <span class="font-mono"><?= $this->e($supplier['phone']) ?></span>
            <?php endif; ?>
            <?php if ($supplier['email'] !== null) : ?>
                &middot; <?= $this->e($supplier['email']) ?>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Articles commandés</h2>
    <table class="table">
        <thead>
        <tr><th>Désignation</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $line) : ?>
            <tr>
                <td>
                    <?= $this->e($line['label']) ?>
                    <?php if ($line['item_id'] !== null) : ?>
                        <span class="badge">au catalogue</span>
                    <?php endif; ?>
                </td>
                <td class="font-mono"><?= $this->number($line['quantity']) ?></td>
                <td class="font-mono"><?= $this->money($line['unit_price']) ?></td>
                <td class="font-mono"><?= $this->money((float) $line['quantity'] * (float) $line['unit_price']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="3">Total de la commande</th>
            <th class="font-mono"><?= $this->money($order['total_amount']) ?></th>
        </tr>
        </tfoot>
    </table>
</div>

<?php if ($order['decision_note'] !== null) : ?>
    <div class="card">
        <h2>Motif de la décision</h2>
        <p><?= $this->e($order['decision_note']) ?></p>
    </div>
<?php endif; ?>

<?php if ($status === 'DEMANDE' && $rbac->allows('purchases:approve')) : ?>
    <div class="card">
        <form method="post" action="/achats/<?= $this->e($order['id']) ?>/decision">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Décision</h2>
            <p class="muted">Un refus doit être motivé : le motif sera relu bien après la décision.</p>
            <div class="field">
                <label for="decision">Suite donnée</label>
                <select id="decision" name="decision">
                    <option value="APPROUVEE">Approuver la commande</option>
                    <option value="REFUSEE">Refuser la commande</option>
                </select>
            </div>
            <div class="field">
                <label for="decision_note">Motif</label>
                <input id="decision_note" name="decision_note">
            </div>
            <button type="submit" class="button">Enregistrer la décision</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($status === 'APPROUVEE' && $rbac->allows('purchases:receive')) : ?>
    <div class="card">
        <form method="post" action="/achats/<?= $this->e($order['id']) ?>/reception">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Réception</h2>
            <p class="muted">
                Les articles rattachés au catalogue entreront automatiquement en stock,
                et leur coût unitaire sera aligné sur le prix payé.
            </p>
            <button type="submit" class="button">Enregistrer la réception</button>
        </form>
    </div>
<?php endif; ?>

<p><a href="/achats">&larr; Retour aux achats</a></p>
