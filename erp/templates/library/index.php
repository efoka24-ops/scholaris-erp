<?php
/**
 * Module 14 : bibliotheque.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $books, $borrows, $students
 * @var int $overdue
 * @var string $search
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Bibliotheque';
$today = date('Y-m-d');
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<h1>Bibliotheque</h1>
<p class="subtitle">
    <?= $this->number(count($books)) ?> ouvrage(s) &middot;
    <?= $this->number(count($borrows)) ?> emprunt(s) en cours &middot;
    <?= $this->number($overdue) ?> en retard
</p>

<?php if ($borrows !== []) : ?>
    <div class="card">
        <h2>Emprunts en cours</h2>
        <table class="table">
            <thead>
            <tr><th>Ouvrage</th><th>Eleve</th><th>Emprunte le</th><th>A rendre le</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($borrows as $borrow) : ?>
                <tr>
                    <td><?= $this->e($borrow['title']) ?></td>
                    <td><?= $this->e($borrow['last_name'].' '.$borrow['first_name']) ?></td>
                    <td class="font-mono"><?= $this->date($borrow['borrow_date']) ?></td>
                    <td class="font-mono" style="color:<?= (string) $borrow['due_date'] < $today ? '#ff4d4d' : 'inherit' ?>">
                        <?= $this->date($borrow['due_date']) ?>
                        <?php if ((string) $borrow['due_date'] < $today) : ?>
                            <span class="badge">en retard</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($rbac->allows('library:update')) : ?>
                            <form method="post" action="/library/borrows/<?= $this->e($borrow['id']) ?>/return" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Enregistrer le retour</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form method="get" action="/library" class="filters">
    <input type="search" name="q" placeholder="Titre, auteur, ISBN" value="<?= $this->e($search) ?>">
    <button type="submit" class="button--secondary">Rechercher</button>
</form>

<?php if ($books === []) : ?>
    <div class="card"><p class="muted">Aucun ouvrage au catalogue.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Titre</th><th>Auteur</th><th>Categorie</th><th>Disponibles</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book) : ?>
            <tr>
                <td><?= $this->e($book['title']) ?></td>
                <td><?= $this->e($book['author'] ?: '-') ?></td>
                <td><?= $this->e($book['category'] ?: '-') ?></td>
                <td class="font-mono"><?= $this->e($book['available']) ?> / <?= $this->e($book['quantity']) ?></td>
                <td>
                    <?php if ((int) $book['available'] > 0 && $rbac->allows('library:update')) : ?>
                        <form method="post" action="/library/books/<?= $this->e($book['id']) ?>/borrow"
                              style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <select name="student_id" required style="min-width:200px">
                                <option value="">Emprunteur...</option>
                                <?php foreach ($students as $student) : ?>
                                    <option value="<?= $this->e($student['id']) ?>">
                                        <?= $this->e($student['last_name'].' '.$student['first_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="days" value="14" min="1" max="90" style="width:80px">
                            <button type="submit" class="link-button">Preter</button>
                        </form>
                    <?php elseif ((int) $book['available'] < 1) : ?>
                        <span class="muted">indisponible</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($rbac->allows('library:create')) : ?>
    <form method="post" action="/library/books" class="card" style="margin-top:1.5rem">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Ajouter un ouvrage</h2>

        <div class="grid-2">
            <div class="field">
                <label for="title">Titre *</label>
                <input id="title" name="title" required value="<?= $this->e($value('title')) ?>">
            </div>
            <div class="field">
                <label for="author">Auteur</label>
                <input id="author" name="author" value="<?= $this->e($value('author')) ?>">
            </div>
            <div class="field">
                <label for="isbn">ISBN</label>
                <input id="isbn" name="isbn" value="<?= $this->e($value('isbn')) ?>">
            </div>
            <div class="field">
                <label for="category">Categorie</label>
                <input id="category" name="category" value="<?= $this->e($value('category')) ?>">
            </div>
            <div class="field">
                <label for="quantity">Nombre d exemplaires *</label>
                <input id="quantity" name="quantity" type="number" min="1" value="<?= $this->e($value('quantity') ?: '1') ?>" required>
            </div>
        </div>

        <button type="submit" class="button">Ajouter au catalogue</button>
    </form>
<?php endif; ?>
