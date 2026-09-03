<?php
/**
 * Journal des courriers sortants.
 *
 * Savoir ce qui est reellement parti evite de promettre a un directeur qu il
 * a recu ses identifiants alors que la remise a echoue.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $notifications
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Courriers envoyés';

$labels = [
    'SENT' => ['Remis', 'success'],
    'FAILED' => ['Échec', 'error'],
    'SKIPPED' => ['Non envoye', 'warning'],
    'PENDING' => ['En cours', 'warning'],
];
?>
<div class="page-header">
    <div>
        <h1>Courriers envoyés</h1>
        <p class="subtitle">
            Cent derniers courriers. Un envoi en échec peut etre repris ; a
            défaut, transmettez les informations par un autre moyen.
        </p>
    </div>
    <a class="button button--secondary" href="/admin">Retour au tableau de bord</a>
</div>

<div class="card">
    <?php if ($notifications === []) : ?>
        <p class="muted">Aucun courrier envoye pour le moment.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date</th><th>Destinataire</th><th>Objet</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($notifications as $mail) : ?>
                <?php [$label, $tone] = $labels[(string) $mail['status']] ?? ['Inconnu', 'warning']; ?>
                <tr>
                    <td><?= $this->date($mail['created_at'], 'd/m/Y H:i') ?></td>
                    <td><?= $this->e($mail['recipient']) ?></td>
                    <td>
                        <?= $this->e($mail['subject']) ?>
                        <?php if (($mail['error'] ?? null) !== null && $mail['error'] !== '') : ?>
                            <br><span class="muted"><?= $this->e($mail['error']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge--<?= $this->e($tone) ?>"><?= $this->e($label) ?></span></td>
                    <td style="text-align:right">
                        <?php if ((string) $mail['status'] !== 'SENT') : ?>
                            <form method="post" action="/admin/courriers/<?= $this->e($mail['id']) ?>/reprendre"
                                  class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Reprendre</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
