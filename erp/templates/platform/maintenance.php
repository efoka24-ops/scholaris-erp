<?php
/**
 * Etat du schema et application des migrations en attente.
 *
 * @var \Scholaris\View\View $this
 * @var list<array{name: string, applied_at: string|null}> $migrations
 * @var int $pending
 * @var string $driver, $phpVersion
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Maintenance';
?>
<div class="page-header">
    <div>
        <h1>Maintenance</h1>
        <p class="subtitle">
            PHP <?= $this->e($phpVersion) ?> &middot; moteur <?= $this->e($driver) ?>
        </p>
    </div>
    <a class="button button--secondary" href="/admin">Retour au tableau de bord</a>
</div>

<?php if ($pending > 0) : ?>
    <div class="alert alert--error">
        <strong><?= $this->number($pending) ?> migration<?= $pending > 1 ? 's' : '' ?></strong>
        en attente. Tant qu elles ne sont pas appliquees, le schema de la base
        est en retard sur le code : certaines pages peuvent tomber en erreur.
    </div>

    <form method="post" action="/admin/maintenance/migrer">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <button type="submit" class="button">Appliquer les migrations en attente</button>
    </form>
<?php else : ?>
    <div class="alert alert--success">Le schema est a jour.</div>
<?php endif; ?>

<div class="card">
    <h2>Migrations</h2>
    <table class="table">
        <thead><tr><th>Fichier</th><th>Etat</th></tr></thead>
        <tbody>
        <?php foreach ($migrations as $migration) : ?>
            <tr>
                <td><code><?= $this->e($migration['name']) ?></code></td>
                <td>
                    <?php if ($migration['applied_at'] === null) : ?>
                        <span class="badge badge--warning">en attente</span>
                    <?php else : ?>
                        <span class="muted">appliquee le <?= $this->date($migration['applied_at'], 'd/m/Y H:i') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="muted">
        Seuls les fichiers livres avec l application sont joues, dans l ordre,
        et une seule fois chacun. Aucune commande ni aucun SQL exterieur n est
        execute ici.
    </p>
</div>
