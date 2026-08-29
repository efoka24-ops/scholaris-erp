<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $invoices
 * @var int $total, $page, $pages
 * @var string $status, $search
 * @var list<string> $statuses
 */
$this->extends('layouts.app');
$title = 'Factures';
?>
<div class="page-header">
    <div>
        <h1>Factures</h1>
        <p class="subtitle"><?= $this->number($total) ?> facture(s)</p>
    </div>
    <a class="button button--secondary" href="/finance">Tableau de bord</a>
</div>

<form method="get" action="/finance/invoices" class="filters">
    <input type="search" name="q" placeholder="Nom, prenom ou matricule" value="<?= $this->e($search) ?>">
    <select name="status">
        <option value="">Tous les statuts</option>
        <?php foreach ($statuses as $option) : ?>
            <option value="<?= $this->e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                <?= $this->e($option) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Filtrer</button>
</form>

<?php if ($invoices === []) : ?>
    <div class="card"><p class="muted">Aucune facture ne correspond a ce filtre.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr>
            <th>Élève</th><th>Classe</th><th>Total</th><th>Règle</th>
            <th>Solde</th><th>Échéance</th><th>Statut</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($invoices as $invoice) : ?>
            <tr>
                <td>
                    <a href="/finance/invoices/<?= $this->e($invoice['id']) ?>">
                        <?= $this->e($invoice['last_name'].' '.$invoice['first_name']) ?>
                    </a>
                    <div class="muted"><?= $this->e($invoice['matricule']) ?></div>
                </td>
                <td><?= $this->e($invoice['classroom_name'] ?: '-') ?></td>
                <td><?= $this->money($invoice['total_amount']) ?></td>
                <td><?= $this->money($invoice['paid_amount']) ?></td>
                <td><strong><?= $this->money($invoice['balance']) ?></strong></td>
                <td><?= $this->date($invoice['due_date']) ?></td>
                <td><span class="badge"><?= $this->e($invoice['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $pages; $p++) : ?>
                <a class="pagination__page<?= $p === $page ? ' pagination__page--activé' : '' ?>"
                   href="/finance/invoices?page=<?= $p ?>&amp;status=<?= urlencode($status) ?>&amp;q=<?= urlencode($search) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
