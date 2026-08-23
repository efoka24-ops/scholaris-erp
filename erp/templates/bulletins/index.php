<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $classrooms, $periods, $bulletins
 * @var string $classroomId, $periodId
 * @var int $published
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Bulletins';
$selected = $classroomId !== '' && $periodId !== '';
?>
<h1>Bulletins</h1>
<p class="subtitle">
    Un bulletin fige les notes au moment de son emission : un recalcul ulterieur
    ne modifie pas un document deja remis.
</p>

<form method="get" action="/bulletins" class="filters">
    <select name="classroom">
        <option value="">Choisir une classe...</option>
        <?php foreach ($classrooms as $classroom) : ?>
            <option value="<?= $this->e($classroom['id']) ?>" <?= $classroomId === $classroom['id'] ? 'selected' : '' ?>>
                <?= $this->e($classroom['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="period">
        <option value="">Choisir une periode...</option>
        <?php foreach ($periods as $period) : ?>
            <option value="<?= $this->e($period['id']) ?>" <?= $periodId === $period['id'] ? 'selected' : '' ?>>
                Sequence <?= $this->e($period['number']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button--secondary">Afficher</button>
</form>

<?php if (! $selected) : ?>
    <div class="card"><p class="muted">Choisissez une classe et une periode.</p></div>
<?php else : ?>
    <div class="page-header">
        <div>
            <h2><?= $this->number(count($bulletins)) ?> bulletin(s)</h2>
            <p class="subtitle"><?= $this->number($published) ?> publie(s)</p>
        </div>
        <div class="form-actions" style="margin:0">
            <?php if ($rbac->allows('bulletins:generate')) : ?>
                <form method="post" action="/bulletins/generate/<?= $this->e($classroomId) ?>/<?= $this->e($periodId) ?>" class="inline-form">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="button">Generer les bulletins</button>
                </form>
            <?php endif; ?>

            <?php if ($bulletins !== [] && $rbac->allows('bulletins:send')) : ?>
                <form method="post" action="/bulletins/publish/<?= $this->e($classroomId) ?>/<?= $this->e($periodId) ?>"
                      class="inline-form"
                      onsubmit="return confirm('Publier ces bulletins ? Ils deviendront visibles des familles.');">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="button--secondary">Publier</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($bulletins === []) : ?>
        <div class="card">
            <p class="muted">
                Aucun bulletin. Les moyennes doivent avoir ete calculees avant
                la generation.
            </p>
        </div>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Matricule</th><th>Eleve</th><th>Code de verification</th><th>Statut</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($bulletins as $bulletin) : ?>
                <tr>
                    <td><?= $this->e($bulletin['matricule']) ?></td>
                    <td><?= $this->e($bulletin['last_name'].' '.$bulletin['first_name']) ?></td>
                    <td class="font-mono"><?= $this->e($bulletin['verification_code']) ?></td>
                    <td><span class="badge"><?= $this->e($bulletin['status']) ?></span></td>
                    <td><a href="/bulletins/<?= $this->e($bulletin['id']) ?>">Ouvrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
