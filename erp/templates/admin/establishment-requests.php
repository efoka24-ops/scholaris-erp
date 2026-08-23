<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $requests
 * @var string $status
 * @var int $pendingCount
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Demandes de creation d etablissement';
$tabs = ['PENDING' => 'En attente', 'APPROVED' => 'Approuvees', 'REJECTED' => 'Refusees'];
?>
<h1>Demandes de creation d etablissement</h1>
<p class="subtitle"><?= $this->number($pendingCount) ?> demande(s) en attente de traitement</p>

<div class="filters">
    <?php foreach ($tabs as $key => $label) : ?>
        <a class="button <?= $status === $key ? '' : 'button--secondary' ?>"
           href="/admin/etablissements?status=<?= $this->e($key) ?>">
            <?= $this->e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($requests === []) : ?>
    <div class="card"><p class="muted">Aucune demande dans cette categorie.</p></div>
<?php else : ?>
    <?php foreach ($requests as $demand) : ?>
        <div class="card">
            <h2><?= $this->e($demand['name']) ?> <span class="badge"><?= $this->e($demand['code']) ?></span></h2>

            <dl class="details">
                <dt>Type</dt><dd><?= $this->e($demand['type']) ?> / <?= $this->e($demand['status']) ?></dd>
                <dt>Responsable</dt>
                <dd>
                    <?= $this->e($demand['director_last_name'].' '.$demand['director_first_name']) ?>
                    &middot; <?= $this->e($demand['director_email']) ?>
                    <?= $demand['director_phone'] ? ' &middot; '.$this->e($demand['director_phone']) : '' ?>
                </dd>
                <dt>Adresse</dt><dd><?= $this->e($demand['address'] ?: '-') ?></dd>
                <dt>Deposee le</dt><dd><?= $this->date($demand['created_at'], 'd/m/Y H:i') ?></dd>
                <?php if ($demand['rejection_reason']) : ?>
                    <dt>Motif du refus</dt><dd><?= $this->e($demand['rejection_reason']) ?></dd>
                <?php endif; ?>
            </dl>

            <?php if ($demand['request_status'] === 'PENDING') : ?>
                <div class="form-actions" style="margin-top:1rem;align-items:flex-start">
                    <form method="post" action="/admin/etablissements/<?= $this->e($demand['id']) ?>/approuver"
                          onsubmit="return confirm('Creer cet etablissement et le compte de son responsable ?');">
                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                        <button type="submit" class="button">Approuver</button>
                    </form>

                    <form method="post" action="/admin/etablissements/<?= $this->e($demand['id']) ?>/refuser"
                          style="display:flex;gap:0.5rem;align-items:center">
                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="text" name="reason" placeholder="Motif du refus" required style="width:260px">
                        <button type="submit" class="button button--danger">Refuser</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
