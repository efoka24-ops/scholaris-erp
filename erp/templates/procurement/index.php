<?php
/**
 * Module 23 : achats et fournisseurs.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $orders, $suppliers, $items
 * @var array<string, string> $supplierNames
 * @var array<string, int> $counts
 * @var float $pendingAmount
 * @var string $status
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Achats';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');

$badge = static function (string $status): string {
    return match ($status) {
        'APPROUVEE' => 'badge badge--success',
        'REFUSEE' => 'badge badge--danger',
        'RECUE' => 'badge badge--neutral',
        default => 'badge badge--warning',
    };
};

$label = static function (string $status): string {
    return match ($status) {
        'APPROUVEE' => 'Approuvée',
        'REFUSEE' => 'Refusée',
        'RECUE' => 'Reçue',
        default => 'En attente',
    };
};
?>
<h1>Achats &amp; fournisseurs</h1>
<p class="subtitle"><?= $this->number(count($suppliers)) ?> fournisseur(s) référencé(s)</p>

<div class="stats">
    <div class="stat <?= ($counts['DEMANDE'] ?? 0) > 0 ? 'stat--amber' : '' ?>">
        <div class="stat__label">En attente</div>
        <div class="stat__value"><?= $this->number($counts['DEMANDE'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Approuvées</div>
        <div class="stat__value"><?= $this->number($counts['APPROUVEE'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Reçues</div>
        <div class="stat__value"><?= $this->number($counts['RECUE'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Engagé, non reçu</div>
        <div class="stat__value stat__value--money"><?= $this->money($pendingAmount) ?></div>
    </div>
</div>

<form method="get" action="/achats" class="inline-form">
    <select name="statut" aria-label="Filtrer par statut">
        <option value="">Tous les statuts</option>
        <?php foreach (['DEMANDE', 'APPROUVEE', 'REFUSEE', 'RECUE'] as $option) : ?>
            <option value="<?= $this->e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                <?= $this->e($label($option)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Filtrer</button>
</form>

<div class="card">
    <h2>Commandes</h2>
    <?php if ($orders === []) : ?>
        <p class="muted">Aucune commande.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Référence</th><th>Objet</th><th>Fournisseur</th><th>Date</th><th>Montant</th><th>Statut</th></tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order) : ?>
                <tr>
                    <td class="font-mono">
                        <a href="/achats/<?= $this->e($order['id']) ?>"><?= $this->e($order['reference']) ?></a>
                    </td>
                    <td><?= $this->e($order['subject']) ?></td>
                    <td><?= $this->e($supplierNames[(string) $order['supplier_id']] ?? '—') ?></td>
                    <td class="font-mono"><?= $this->date($order['ordered_on']) ?></td>
                    <td class="font-mono"><?= $this->money($order['total_amount']) ?></td>
                    <td>
                        <span class="<?= $this->e($badge((string) $order['status'])) ?>">
                            <?= $this->e($label((string) $order['status'])) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('purchases:create')) : ?>
    <div class="card">
        <form method="post" action="/achats/commandes">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Nouvelle demande d'achat</h2>
            <p class="muted">
                Le montant total est calculé à partir des lignes. Un article rattaché au
                catalogue entrera automatiquement en stock à la réception.
            </p>

            <div class="grid-2">
                <div class="field">
                    <label for="subject">Objet *</label>
                    <input id="subject" name="subject" required value="<?= $this->e($value('subject')) ?>">
                </div>
                <div class="field">
                    <label for="supplier_id">Fournisseur</label>
                    <select id="supplier_id" name="supplier_id">
                        <option value="">À déterminer</option>
                        <?php foreach ($suppliers as $supplier) : ?>
                            <option value="<?= $this->e($supplier['id']) ?>"><?= $this->e($supplier['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ordered_on">Date de la demande *</label>
                    <input id="ordered_on" name="ordered_on" type="date" required
                           value="<?= $this->e($value('ordered_on') ?: date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label for="expected_on">Livraison souhaitée</label>
                    <input id="expected_on" name="expected_on" type="date">
                </div>
            </div>

            <table class="table">
                <thead>
                <tr><th>Désignation</th><th>Article du catalogue</th><th>Quantité</th><th>Prix unitaire</th></tr>
                </thead>
                <tbody>
                <?php for ($i = 0; $i < 4; $i++) : ?>
                    <tr>
                        <td><input name="lines[<?= $i ?>][label]" aria-label="Désignation de la ligne <?= $i + 1 ?>"></td>
                        <td>
                            <select name="lines[<?= $i ?>][item_id]" aria-label="Article de la ligne <?= $i + 1 ?>">
                                <option value="">Hors catalogue</option>
                                <?php foreach ($items as $item) : ?>
                                    <option value="<?= $this->e($item['id']) ?>">
                                        <?= $this->e($item['code'].' — '.$item['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input name="lines[<?= $i ?>][quantity]" inputmode="decimal" size="6" aria-label="Quantité de la ligne <?= $i + 1 ?>"></td>
                        <td><input name="lines[<?= $i ?>][unit_price]" inputmode="decimal" aria-label="Prix unitaire de la ligne <?= $i + 1 ?>"></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>

            <button type="submit" class="button">Enregistrer la demande</button>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Fournisseurs</h2>
    <?php if ($suppliers === []) : ?>
        <p class="muted">Aucun fournisseur référencé.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Raison sociale</th><th>Contact</th><th>Téléphone</th><th>Adresse électronique</th><th>N° contribuable</th></tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $supplier) : ?>
                <tr>
                    <td><?= $this->e($supplier['name']) ?></td>
                    <td><?= $this->e($supplier['contact_name']) ?></td>
                    <td class="font-mono"><?= $this->e($supplier['phone']) ?></td>
                    <td><?= $this->e($supplier['email']) ?></td>
                    <td class="font-mono"><?= $this->e($supplier['tax_number']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($rbac->allows('suppliers:create')) : ?>
        <form method="post" action="/achats/fournisseurs">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h3>Nouveau fournisseur</h3>
            <div class="grid-2">
                <div class="field">
                    <label for="supplier_name">Raison sociale *</label>
                    <input id="supplier_name" name="name" required>
                </div>
                <div class="field">
                    <label for="contact_name">Personne à contacter</label>
                    <input id="contact_name" name="contact_name">
                </div>
                <div class="field">
                    <label for="phone">Téléphone</label>
                    <input id="phone" name="phone">
                </div>
                <div class="field">
                    <label for="email">Adresse électronique</label>
                    <input id="email" name="email" type="email">
                </div>
                <div class="field">
                    <label for="address">Adresse</label>
                    <input id="address" name="address">
                </div>
                <div class="field">
                    <label for="tax_number">N° de contribuable</label>
                    <input id="tax_number" name="tax_number">
                </div>
            </div>
            <button type="submit" class="button">Enregistrer le fournisseur</button>
        </form>
    <?php endif; ?>
</div>
