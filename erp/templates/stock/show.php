<?php
/**
 * Fiche d'un article de stock.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $item
 * @var array<int, array<string, mixed>> $movements
 */
$this->extends('layouts.app');
$title = (string) $item['name'];

$quantity = static fn (mixed $q): string => rtrim(rtrim(number_format((float) $q, 2, ',', ' '), '0'), ',');
?>
<h1><?= $this->e($item['name']) ?></h1>
<p class="subtitle">
    <span class="font-mono"><?= $this->e($item['code']) ?></span>
    <?php if ($item['category'] !== null) : ?>
        &middot; <?= $this->e($item['category']) ?>
    <?php endif; ?>
    <?php if ($item['location'] !== null) : ?>
        &middot; <?= $this->e($item['location']) ?>
    <?php endif; ?>
</p>

<div class="stats">
    <div class="stat <?= (float) $item['quantity'] <= (float) $item['min_quantity'] ? 'stat--pink' : '' ?>">
        <div class="stat__label">En stock</div>
        <div class="stat__value"><?= $this->e($quantity($item['quantity']).' '.$item['unit']) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Seuil d'alerte</div>
        <div class="stat__value"><?= $this->e($quantity($item['min_quantity'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Valeur</div>
        <div class="stat__value stat__value--money">
            <?= $this->money((float) $item['quantity'] * (float) $item['unit_cost']) ?>
        </div>
    </div>
</div>

<div class="card">
    <h2>Historique des mouvements</h2>
    <p class="muted">
        Le solde affiché plus haut se déduit de ce journal : c'est lui qui permet
        d'expliquer un écart constaté à l'inventaire.
    </p>
    <?php if ($movements === []) : ?>
        <p class="muted">Aucun mouvement pour cet article.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date</th><th>Sens</th><th>Quantité</th><th>Solde après</th><th>Motif</th><th>Référence</th></tr>
            </thead>
            <tbody>
            <?php foreach ($movements as $movement) : ?>
                <tr>
                    <td class="font-mono"><?= $this->date($movement['moved_on']) ?></td>
                    <td>
                        <?php if ((string) $movement['direction'] === 'ENTREE') : ?>
                            <span class="badge badge--success">Entrée</span>
                        <?php elseif ((string) $movement['direction'] === 'SORTIE') : ?>
                            <span class="badge badge--warning">Sortie</span>
                        <?php else : ?>
                            <span class="badge">Inventaire</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-mono"><?= $this->e($quantity($movement['quantity'])) ?></td>
                    <td class="font-mono"><?= $this->e($quantity($movement['balance_after'])) ?></td>
                    <td><?= $this->e($movement['reason']) ?></td>
                    <td class="font-mono"><?= $this->e($movement['reference']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<p><a href="/stocks">&larr; Retour au magasin</a></p>
