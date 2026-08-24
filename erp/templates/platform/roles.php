<?php
/**
 * Roles et habilitations.
 *
 * Le referentiel compte plus de cent permissions reparties sur une douzaine de
 * roles. Sans ecran pour le lire, personne ne sait ce qu un role autorise
 * reellement — et l on finit par accorder trop, faute de pouvoir verifier.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $roles
 * @var array<string, list<array<string, mixed>>> $permissions
 * @var int $total
 */
$this->extends('layouts.app');
$title = 'Roles et habilitations';
?>
<div class="page-header">
    <div>
        <h1>Roles et habilitations</h1>
        <p class="subtitle">
            <?= $this->number(count($roles)) ?> roles systeme &middot;
            <?= $this->number($total) ?> permissions au referentiel
        </p>
    </div>
</div>

<div class="alert alert--info">
    Ces roles sont livres avec l application et partages par tous les
    etablissements. Les modifier changerait les droits de chacun, partout a la
    fois : cet ecran les donne a lire, non a editer.
</div>

<?php foreach ($roles as $role) : ?>
    <div class="card">
        <div class="inline-form" style="justify-content:space-between;align-items:flex-start">
            <div>
                <h2 style="margin:0"><?= $this->e($role['name']) ?></h2>
                <p class="muted" style="margin:0.25rem 0 0"><?= $this->e($role['description'] ?? '') ?></p>
            </div>
            <div style="text-align:right" class="muted">
                <?= $this->number($role['permissions_count']) ?> permissions<br>
                <?= $this->number($role['users_count']) ?> compte<?= (int) $role['users_count'] > 1 ? 's' : '' ?>
            </div>
        </div>

        <details style="margin-top:1rem">
            <summary class="muted">Voir le detail des droits</summary>
            <table class="table" style="margin-top:1rem">
                <thead>
                <tr><th>Ressource</th><th>Action</th><th>Ce que cela autorise</th></tr>
                </thead>
                <tbody>
                <?php foreach ($permissions[(string) $role['id']] ?? [] as $permission) : ?>
                    <tr>
                        <td><code><?= $this->e($permission['resource']) ?></code></td>
                        <td><code><?= $this->e($permission['action']) ?></code></td>
                        <td class="muted"><?= $this->e($permission['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>
<?php endforeach; ?>
