<?php
/**
 * Tableau de bord enseignant : ses classes, ses matieres, sa saisie en cours.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $assignments, $mainClassrooms
 * @var array<string, mixed>|null $period
 * @var int $unreadMessages
 */
$this->extends('layouts.app');
$title = 'Mon tableau de bord';

$total = 0;
$done = 0;

foreach ($assignments as $assignment) {
    $total += (int) $assignment['headcount'];
    $done += (int) $assignment['graded'];
}
?>
<h1>Mon tableau de bord</h1>
<p class="subtitle">
    <?php if ($period !== null) : ?>
        Sequence <?= $this->e($period['number']) ?> ouverte a la saisie
    <?php else : ?>
        Aucune sequence ouverte : la saisie des notes est fermee
    <?php endif; ?>
</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Mes enseignements</div>
        <div class="stat__value"><?= $this->number(count($assignments)) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Classes dont je suis PP</div>
        <div class="stat__value"><?= $this->number(count($mainClassrooms)) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Notes saisies</div>
        <div class="stat__value"><?= $this->number($done) ?> / <?= $this->number($total) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Messages non lus</div>
        <div class="stat__value"><?= $this->number($unreadMessages) ?></div>
    </div>
</div>

<div class="card">
    <h2>Ma saisie</h2>

    <?php if ($assignments === []) : ?>
        <p class="muted">Aucune matiere ne vous est affectee pour le moment.</p>
    <?php else : ?>
        <table class="table">
            <thead><tr><th>Classe</th><th>Matiere</th><th>Coef.</th><th>Avancement</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assignments as $assignment) : ?>
                <?php
                $headcount = (int) $assignment['headcount'];
                $graded = (int) $assignment['graded'];
                $complete = $headcount > 0 && $graded >= $headcount;
                ?>
                <tr>
                    <td><?= $this->e($assignment['classroom_name']) ?></td>
                    <td><?= $this->e($assignment['subject_name']) ?></td>
                    <td class="font-mono"><?= $this->e(rtrim(rtrim((string) $assignment['coefficient'], '0'), '.')) ?></td>
                    <td class="font-mono" style="color:<?= $complete ? '#00e5a0' : ($graded > 0 ? '#ffb800' : '#ff4d4d') ?>">
                        <?= $this->number($graded) ?> / <?= $this->number($headcount) ?>
                        <?php if ($complete) : ?>
                            <span class="badge">complet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($period !== null) : ?>
                            <a href="/grades/entry/<?= $this->e($assignment['classroom_id']) ?>/<?= $this->e($assignment['subject_id']) ?>/<?= $this->e($period['id']) ?>">
                                Saisir
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

<?php if ($mainClassrooms !== []) : ?>
    <div class="card">
        <h2>Mes classes en tant que professeur principal</h2>
        <table class="table">
            <thead><tr><th>Classe</th><th>Section</th><th>Capacite</th></tr></thead>
            <tbody>
            <?php foreach ($mainClassrooms as $classroom) : ?>
                <tr>
                    <td><a href="/classrooms/<?= $this->e($classroom['id']) ?>"><?= $this->e($classroom['name']) ?></a></td>
                    <td><span class="badge"><?= $this->e($classroom['section']) ?></span></td>
                    <td class="font-mono"><?= $this->e($classroom['capacity']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
