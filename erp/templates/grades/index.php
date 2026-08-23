<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $classrooms
 * @var array<int, array<string, mixed>> $periods
 * @var array<int, array<string, mixed>> $assignments
 * @var bool $isTeacher
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Notes';

$openPeriod = null;

foreach ($periods as $period) {
    if ($period['grading_status'] === 'OPEN') {
        $openPeriod = $period;
        break;
    }
}
?>
<h1>Notes</h1>
<p class="subtitle">
    <?php if ($openPeriod !== null) : ?>
        Sequence <?= $this->e($openPeriod['number']) ?> ouverte a la saisie
    <?php else : ?>
        Aucune sequence ouverte a la saisie
    <?php endif; ?>
</p>

<?php if ($openPeriod === null) : ?>
    <div class="alert alert--error">
        Aucune periode n est ouverte. La saisie des notes est bloquee tant qu une
        sequence n a pas ete ouverte par la direction.
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= $isTeacher ? 'Mes enseignements' : 'Saisie des notes' ?></h2>

    <?php if ($assignments === []) : ?>
        <p class="muted">
            <?= $isTeacher
                ? 'Aucune matiere ne vous est affectee pour le moment.'
                : 'Aucune affectation enseignant-matiere-classe enregistree.' ?>
        </p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Classe</th><th>Matiere</th><th>Coef.</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $assignment) : ?>
                <tr>
                    <td><?= $this->e($assignment['classroom_name']) ?></td>
                    <td><?= $this->e($assignment['subject_name']) ?></td>
                    <td><?= $this->e(rtrim(rtrim((string) $assignment['coefficient'], '0'), '.')) ?></td>
                    <td>
                        <?php if ($openPeriod !== null) : ?>
                            <a href="/grades/entry/<?= $this->e($assignment['classroom_id']) ?>/<?= $this->e($assignment['subject_id']) ?>/<?= $this->e($openPeriod['id']) ?>">
                                Saisir les notes
                            </a>
                        <?php else : ?>
                            <span class="muted">periode fermee</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('grades:calculate') && $openPeriod !== null) : ?>
    <div class="card">
        <h2>Calcul des moyennes</h2>
        <p class="muted">
            Le calcul reprend toutes les notes de la sequence, recalcule les
            moyennes par matiere puis la moyenne generale ponderee, et etablit le
            classement. Il peut etre relance autant de fois que necessaire.
        </p>

        <table class="table">
            <thead>
            <tr><th>Classe</th><th>Niveau</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($classrooms as $classroom) : ?>
                <tr>
                    <td><?= $this->e($classroom['name']) ?></td>
                    <td><?= $this->e($classroom['level_name']) ?></td>
                    <td>
                        <form method="post"
                              action="/grades/calculate/<?= $this->e($classroom['id']) ?>/<?= $this->e($openPeriod['id']) ?>"
                              class="inline-form">
                            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                            <button type="submit" class="link-button">Calculer</button>
                        </form>
                        &middot;
                        <a href="/grades/results/<?= $this->e($classroom['id']) ?>/<?= $this->e($openPeriod['id']) ?>">
                            Resultats
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
