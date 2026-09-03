<?php
/**
 * Detail d'une ecriture comptable.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $entry
 * @var array<int, array<string, mixed>> $lines
 */

use Scholaris\Service\ChartOfAccounts;

$this->extends('layouts.app');
$title = 'Écriture '.$entry['reference'];

$totalDebit = 0.0;
$totalCredit = 0.0;

foreach ($lines as $line) {
    $totalDebit += (float) $line['debit'];
    $totalCredit += (float) $line['credit'];
}
?>
<h1>Écriture <span class="font-mono"><?= $this->e($entry['reference']) ?></span></h1>
<p class="subtitle">
    <?= $this->e($entry['label']) ?> &middot;
    <?= $this->date($entry['entry_date']) ?> &middot;
    journal <?= $this->e(ChartOfAccounts::journalName((string) $entry['journal'])) ?>
    <?php if ((int) $entry['posted'] === 1) : ?>
        <span class="badge badge--success">Validée le <?= $this->date($entry['posted_at']) ?></span>
    <?php else : ?>
        <span class="badge badge--warning">Au brouillard</span>
    <?php endif; ?>
</p>

<?php if ($entry['source'] === 'CONTREPASSATION') : ?>
    <p class="muted">
        Cette écriture annule une écriture antérieure. Les deux restent au journal :
        une correction comptable s'ajoute, elle n'efface pas.
    </p>
<?php endif; ?>

<div class="card">
    <table class="table">
        <thead>
        <tr><th>Compte</th><th>Intitulé</th><th>Libellé</th><th>Débit</th><th>Crédit</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $line) : ?>
            <tr>
                <td class="font-mono"><?= $this->e($line['code']) ?></td>
                <td><?= $this->e($line['name']) ?></td>
                <td><?= $this->e($line['label']) ?></td>
                <td class="font-mono"><?= (float) $line['debit'] > 0 ? $this->money($line['debit']) : '' ?></td>
                <td class="font-mono"><?= (float) $line['credit'] > 0 ? $this->money($line['credit']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="3">Total</th>
            <th class="font-mono"><?= $this->money($totalDebit) ?></th>
            <th class="font-mono"><?= $this->money($totalCredit) ?></th>
        </tr>
        </tfoot>
    </table>
</div>

<p><a href="/comptabilite">&larr; Retour à la comptabilité</a></p>
