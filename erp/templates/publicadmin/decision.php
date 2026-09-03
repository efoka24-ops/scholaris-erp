<?php
/**
 * Acte de carriere, sous une forme proche du document remis a l'agent.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $decision
 * @var array<string, mixed>|null $employee, $tenant
 * @var array<string, string> $kinds
 */
$this->extends('layouts.app');
$title = 'Acte '.$decision['reference'];
?>
<h1><?= $this->e($kinds[(string) $decision['kind']] ?? $decision['kind']) ?></h1>
<p class="subtitle">
    <span class="font-mono"><?= $this->e($decision['reference']) ?></span>
    <?php if ((string) $decision['status'] === 'SIGNE') : ?>
        <span class="badge badge--success">Signé</span>
    <?php else : ?>
        <span class="badge badge--warning">Projet</span>
    <?php endif; ?>
</p>

<div class="card">
    <h2><?= $tenant === null ? 'Établissement' : $this->e($tenant['name']) ?></h2>
    <table class="table">
        <tbody>
        <tr>
            <th>Agent concerné</th>
            <td><?= $employee === null ? '—' : $this->e($employee['last_name'].' '.$employee['first_name']) ?></td>
        </tr>
        <tr>
            <th>Fonction</th>
            <td><?= $employee === null ? '—' : $this->e($employee['position']) ?></td>
        </tr>
        <tr>
            <th>Objet</th>
            <td><?= $this->e($decision['subject']) ?></td>
        </tr>
        <tr>
            <th>Date de la décision</th>
            <td class="font-mono"><?= $this->date($decision['decided_on']) ?></td>
        </tr>
        <tr>
            <th>Date d'effet</th>
            <td class="font-mono">
                <?= $decision['effective_on'] === null ? '—' : $this->date($decision['effective_on']) ?>
            </td>
        </tr>
        <tr>
            <th>Autorité signataire</th>
            <td><?= $this->e($decision['signed_by']) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<?php if ($decision['content'] !== null && trim((string) $decision['content']) !== '') : ?>
    <div class="card">
        <h2>Contenu</h2>
        <p><?= nl2br($this->e($decision['content'])) ?></p>
    </div>
<?php endif; ?>

<p><a href="/administration">&larr; Retour aux actes</a></p>
