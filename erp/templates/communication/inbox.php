<?php
/**
 * Messagerie interne de l'utilisateur connecte.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $received, $sent
 */
$this->extends('layouts.app');
$title = 'Ma messagerie';
?>
<h1>Ma messagerie</h1>
<p class="subtitle">
    <?= $this->number(count($received)) ?> message(s) recu(s) &middot;
    <?= $this->number(count($sent)) ?> envoye(s)
</p>

<div class="card">
    <h2>Recus</h2>

    <?php if ($received === []) : ?>
        <p class="muted">Aucun message recu.</p>
    <?php else : ?>
        <?php foreach ($received as $message) : ?>
            <div style="padding:.85rem 0;border-top:1px solid rgba(255,255,255,.06)">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                    <strong><?= $this->e($message['last_name'].' '.$message['first_name']) ?></strong>
                    <span class="font-mono muted" style="font-size:.72rem">
                        <?= $this->date($message['created_at'], 'd/m/Y H:i') ?>
                    </span>
                </div>
                <p style="margin:.4rem 0 0"><?= $this->e($message['body']) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Envoyes</h2>

    <?php if ($sent === []) : ?>
        <p class="muted">Aucun message envoye.</p>
    <?php else : ?>
        <?php foreach ($sent as $message) : ?>
            <div style="padding:.85rem 0;border-top:1px solid rgba(255,255,255,.06)">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                    <span class="muted">
                        A <?= $this->e($message['last_name'].' '.$message['first_name']) ?>
                    </span>
                    <span class="font-mono muted" style="font-size:.72rem">
                        <?= $this->date($message['created_at'], 'd/m/Y H:i') ?>
                        <?= $message['read_at'] ? ' &middot; lu' : ' &middot; non lu' ?>
                    </span>
                </div>
                <p style="margin:.4rem 0 0"><?= $this->e($message['body']) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
