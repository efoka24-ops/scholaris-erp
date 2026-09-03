<?php
/**
 * Module 24 : stocks et magasin.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $items, $alerts, $movements
 * @var float $totalValue
 * @var string $search
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Stocks';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');

$quantity = static fn (mixed $q): string => rtrim(rtrim(number_format((float) $q, 2, ',', ' '), '0'), ',');
?>
<h1>Stocks &amp; magasin</h1>
<p class="subtitle"><?= $this->number(count($items)) ?> article(s) au catalogue</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Articles</div>
        <div class="stat__value"><?= $this->number(count($items)) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Valeur du stock</div>
        <div class="stat__value stat__value--money"><?= $this->money($totalValue) ?></div>
    </div>
    <div class="stat <?= $alerts === [] ? '' : 'stat--pink' ?>">
        <div class="stat__label">Sous le seuil</div>
        <div class="stat__value"><?= $this->number(count($alerts)) ?></div>
    </div>
</div>

<?php if ($alerts !== []) : ?>
    <div class="card">
        <h2>À réapprovisionner</h2>
        <p class="muted">Ces articles sont au niveau du seuil d'alerte ou en dessous.</p>
        <table class="table">
            <thead>
            <tr><th>Code</th><th>Désignation</th><th>En stock</th><th>Seuil</th></tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $item) : ?>
                <tr>
                    <td class="font-mono"><?= $this->e($item['code']) ?></td>
                    <td><a href="/stocks/<?= $this->e($item['id']) ?>"><?= $this->e($item['name']) ?></a></td>
                    <td class="font-mono"><?= $this->e($quantity($item['quantity']).' '.$item['unit']) ?></td>
                    <td class="font-mono"><?= $this->e($quantity($item['min_quantity'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form method="get" action="/stocks" class="inline-form">
    <input type="search" name="q" placeholder="Désignation, code, catégorie" value="<?= $this->e($search) ?>">
    <button type="submit" class="button button--secondary">Rechercher</button>
</form>

<div class="card">
    <h2>Catalogue</h2>
    <?php if ($items === []) : ?>
        <p class="muted">Aucun article. Créez-en un ci-dessous.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Code</th><th>Désignation</th><th>Catégorie</th><th>En stock</th><th>Valeur</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item) : ?>
                <tr>
                    <td class="font-mono"><?= $this->e($item['code']) ?></td>
                    <td>
                        <a href="/stocks/<?= $this->e($item['id']) ?>"><?= $this->e($item['name']) ?></a>
                        <?php if ((float) $item['quantity'] <= (float) $item['min_quantity']) : ?>
                            <span class="badge badge--warning">seuil atteint</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->e($item['category']) ?></td>
                    <td class="font-mono"><?= $this->e($quantity($item['quantity']).' '.$item['unit']) ?></td>
                    <td class="font-mono"><?= $this->money((float) $item['quantity'] * (float) $item['unit_cost']) ?></td>
                    <td>
                        <?php if ($rbac->allows('stock:move')) : ?>
                            <form method="post" action="/stocks/<?= $this->e($item['id']) ?>/mouvement" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <select name="direction" aria-label="Sens du mouvement">
                                    <option value="ENTREE">Entrée</option>
                                    <option value="SORTIE">Sortie</option>
                                    <option value="AJUSTEMENT">Inventaire</option>
                                </select>
                                <input name="quantity" inputmode="decimal" size="5" required aria-label="Quantité">
                                <input name="reason" placeholder="Motif" aria-label="Motif">
                                <button type="submit" class="link-button">Enregistrer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('stock:create')) : ?>
    <div class="card">
        <form method="post" action="/stocks/articles">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Nouvel article</h2>
            <p class="muted">
                L'article est créé à zéro : la quantité de départ se saisit ensuite comme
                une entrée, pour qu'elle apparaisse au journal des mouvements.
            </p>
            <div class="grid-2">
                <div class="field">
                    <label for="code">Code article *</label>
                    <input id="code" name="code" required value="<?= $this->e($value('code')) ?>">
                </div>
                <div class="field">
                    <label for="name">Désignation *</label>
                    <input id="name" name="name" required value="<?= $this->e($value('name')) ?>">
                </div>
                <div class="field">
                    <label for="category">Catégorie</label>
                    <input id="category" name="category" placeholder="Fournitures, entretien, cantine...">
                </div>
                <div class="field">
                    <label for="unit">Unité *</label>
                    <input id="unit" name="unit" required value="<?= $this->e($value('unit') ?: 'unité') ?>">
                </div>
                <div class="field">
                    <label for="min_quantity">Seuil d'alerte</label>
                    <input id="min_quantity" name="min_quantity" inputmode="decimal" value="0">
                </div>
                <div class="field">
                    <label for="unit_cost">Coût unitaire (FCFA)</label>
                    <input id="unit_cost" name="unit_cost" inputmode="decimal" value="0">
                </div>
                <div class="field">
                    <label for="location">Emplacement</label>
                    <input id="location" name="location" placeholder="Magasin, réserve...">
                </div>
            </div>
            <button type="submit" class="button">Créer l'article</button>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Derniers mouvements</h2>
    <?php if ($movements === []) : ?>
        <p class="muted">Aucun mouvement enregistré.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date</th><th>Article</th><th>Sens</th><th>Quantité</th><th>Solde après</th><th>Motif</th></tr>
            </thead>
            <tbody>
            <?php foreach ($movements as $movement) : ?>
                <tr>
                    <td class="font-mono"><?= $this->date($movement['moved_on']) ?></td>
                    <td><?= $this->e($movement['name']) ?></td>
                    <td>
                        <?php if ((string) $movement['direction'] === 'ENTREE') : ?>
                            <span class="badge badge--success">Entrée</span>
                        <?php elseif ((string) $movement['direction'] === 'SORTIE') : ?>
                            <span class="badge badge--warning">Sortie</span>
                        <?php else : ?>
                            <span class="badge">Inventaire</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-mono"><?= $this->e($quantity($movement['quantity']).' '.$movement['unit']) ?></td>
                    <td class="font-mono"><?= $this->e($quantity($movement['balance_after'])) ?></td>
                    <td><?= $this->e($movement['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
