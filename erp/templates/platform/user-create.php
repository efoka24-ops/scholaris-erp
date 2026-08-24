<?php
/**
 * Nomination d un administrateur de la plateforme.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $old
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Nommer un administrateur';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<div class="page-header">
    <div>
        <h1>Nommer un administrateur de la plateforme</h1>
        <p class="subtitle">
            Ce compte n appartiendra a aucun etablissement et pourra instruire
            les demandes d ouverture, comme le votre.
        </p>
    </div>
    <a class="button button--secondary" href="/admin/comptes">Retour aux comptes</a>
</div>

<div class="card">
    <div class="alert alert--info">
        Un seul compte d administration nationale est un point de defaillance :
        s il se perd, la plateforme n a plus d arbitre pour les demandes
        d ouverture. En nommer un second est une precaution, pas un confort.
    </div>

    <form method="post" action="/admin/comptes">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <div class="grid-2">
            <div class="field">
                <label for="last_name">Nom *</label>
                <input id="last_name" name="last_name" required value="<?= $this->e($value('last_name')) ?>">
            </div>
            <div class="field">
                <label for="first_name">Prenom *</label>
                <input id="first_name" name="first_name" required value="<?= $this->e($value('first_name')) ?>">
            </div>
            <div class="field">
                <label for="email">Email *</label>
                <input id="email" name="email" type="email" required value="<?= $this->e($value('email')) ?>">
            </div>
        </div>
        <button type="submit" class="button">Creer le compte</button>
    </form>
</div>
