<?php
/**
 * Module 37 : gestion electronique des documents.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $documents
 * @var array<string, string> $categories
 * @var string $category, $search
 * @var int $totalBytes
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Documents';

$size = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes.' o';
    }

    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 0, ',', ' ').' Ko';
    }

    return number_format($bytes / 1048576, 1, ',', ' ').' Mo';
};
?>
<h1>Documents</h1>
<p class="subtitle">
    <?= $this->number(count($documents)) ?> document(s) &middot;
    <?= $this->e($size($totalBytes)) ?> stockés
</p>

<form method="get" action="/documents" class="inline-form">
    <input type="search" name="q" placeholder="Intitulé, description, nom de fichier" value="<?= $this->e($search) ?>">
    <select name="categorie" aria-label="Catégorie">
        <option value="">Toutes les catégories</option>
        <?php foreach ($categories as $key => $name) : ?>
            <option value="<?= $this->e($key) ?>" <?= $category === $key ? 'selected' : '' ?>>
                <?= $this->e($name) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button--secondary">Rechercher</button>
</form>

<div class="card">
    <h2>Fonds documentaire</h2>
    <?php if ($documents === []) : ?>
        <p class="muted">Aucun document.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Intitulé</th><th>Catégorie</th><th>Fichier</th><th>Taille</th><th>Déposé le</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($documents as $document) : ?>
                <tr>
                    <td>
                        <a href="/documents/<?= $this->e($document['id']) ?>/telecharger">
                            <?= $this->e($document['title']) ?>
                        </a>
                        <?php if ((string) $document['visibility'] === 'RESTREINT') : ?>
                            <span class="badge badge--warning">restreint</span>
                        <?php endif; ?>
                        <?php if ($document['description'] !== null) : ?>
                            <div class="muted"><?= $this->e($document['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->e($categories[(string) $document['category']] ?? $document['category']) ?></td>
                    <td class="font-mono"><?= $this->e($document['original_name']) ?></td>
                    <td class="font-mono"><?= $this->e($size((int) $document['size_bytes'])) ?></td>
                    <td class="font-mono"><?= $this->date($document['created_at']) ?></td>
                    <td>
                        <?php if ($rbac->allows('documents:delete')) : ?>
                            <form method="post" action="/documents/<?= $this->e($document['id']) ?>/retirer" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Retirer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('documents:create')) : ?>
    <div class="card">
        <form method="post" action="/documents" enctype="multipart/form-data">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Déposer un document</h2>
            <p class="muted">
                Formats acceptés : PDF, image, Word, Excel, texte. 10 Mo au maximum.
                Les fichiers sont stockés hors du site public : ils ne sont accessibles
                qu'aux personnes connectées à cet établissement.
            </p>
            <div class="grid-2">
                <div class="field">
                    <label for="title">Intitulé *</label>
                    <input id="title" name="title" required>
                </div>
                <div class="field">
                    <label for="category">Catégorie</label>
                    <select id="category" name="category">
                        <?php foreach ($categories as $key => $name) : ?>
                            <option value="<?= $this->e($key) ?>"><?= $this->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="visibility">Visibilité</label>
                    <select id="visibility" name="visibility">
                        <option value="INTERNE">Interne à l'établissement</option>
                        <option value="RESTREINT">Restreint à la direction</option>
                    </select>
                </div>
                <div class="field">
                    <label for="file">Fichier *</label>
                    <input id="file" name="file" type="file" required>
                </div>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input id="description" name="description">
            </div>
            <button type="submit" class="button">Déposer</button>
        </form>
    </div>
<?php endif; ?>
