<?php
/**
 * Bulletin de paie, imprimable et remis a l'agent.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $payslip
 * @var array<string, mixed>|null $employee, $period, $tenant
 * @var array<int, array<string, mixed>> $lines
 */
$this->extends('layouts.app');
$title = 'Bulletin de paie';

$gains = array_filter($lines, static fn (array $l): bool => (string) $l['kind'] === 'GAIN');
$retenues = array_filter($lines, static fn (array $l): bool => (string) $l['kind'] !== 'GAIN');
?>
<h1>Bulletin de paie</h1>
<p class="subtitle">
    <?= $employee === null ? '' : $this->e($employee['last_name'].' '.$employee['first_name']) ?>
    <?php if ($period !== null) : ?>
        &middot; <?= $this->e($period['label']) ?>
    <?php endif; ?>
    <?php if ((string) $payslip['status'] === 'VALIDE') : ?>
        <span class="badge badge--success">Validé</span>
    <?php else : ?>
        <span class="badge badge--warning">Brouillon</span>
    <?php endif; ?>
</p>

<div class="card">
    <h2><?= $tenant === null ? 'Établissement' : $this->e($tenant['name']) ?></h2>
    <?php if ($employee !== null) : ?>
        <p class="muted">
            <?= $this->e($employee['position']) ?>
            <?php if ($employee['department'] !== null) : ?>
                &middot; <?= $this->e($employee['department']) ?>
            <?php endif; ?>
            &middot; embauché le <?= $this->date($employee['hire_date']) ?>
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Éléments du bulletin</h2>
    <table class="table">
        <thead>
        <tr><th>Libellé</th><th>Gain</th><th>Retenue</th></tr>
        </thead>
        <tbody>
        <?php foreach ($gains as $line) : ?>
            <tr>
                <td><?= $this->e($line['label']) ?></td>
                <td class="font-mono"><?= $this->money($line['amount']) ?></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($retenues as $line) : ?>
            <tr>
                <td><?= $this->e($line['label']) ?></td>
                <td></td>
                <td class="font-mono"><?= $this->money($line['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <th>Net à payer</th>
            <th class="font-mono" colspan="2"><?= $this->money($payslip['net_amount']) ?></th>
        </tr>
        </tfoot>
    </table>
</div>

<div class="card">
    <h2>Charges patronales</h2>
    <p class="muted">
        À la charge de l'établissement, en plus du salaire brut : elles ne sont pas
        retenues sur le net de l'agent.
    </p>
    <table class="table">
        <tbody>
        <tr>
            <td>CNPS — part patronale et prestations familiales</td>
            <td class="font-mono"><?= $this->money($payslip['cnps_employer']) ?></td>
        </tr>
        <tr>
            <td>Coût total employeur</td>
            <td class="font-mono">
                <?= $this->money((float) $payslip['gross_amount'] + (float) $payslip['cnps_employer']) ?>
            </td>
        </tr>
        </tbody>
    </table>
</div>

<p><a href="/paie">&larr; Retour à la paie</a></p>
