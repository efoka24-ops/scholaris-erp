<?php
/**
 * Tableau de bord de direction.
 *
 * Il repond a quatre questions, dans cet ordre : combien d'élèves, qui est la
 * aujourd'hui, ou en est la saisie des notes, et ou en est l'argent. Le reste
 * vient apres.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed>|null $academicYear
 * @var array<string, mixed>|null $openPeriod
 * @var array<string, float|int> $stats
 * @var array<int, array<string, mixed>> $recentStudents
 * @var int $pendingApplications
 * @var list<array{label: string, value: int}> $enrolmentTrend
 * @var list<array{label: string, value: int, color: string}> $mentionSpread
 * @var array{rate: float|null, recorded: int} $attendanceToday
 * @var int $pendingGradeEntries
 * @var array<string, mixed>|null $auth
 * @var \Scholaris\Auth\Rbac $rbac
 * @var \Scholaris\Tenant\Features $features
 */

use Scholaris\View\Chart;
use Scholaris\View\Navigation;

$this->extends('layouts.app');
$title = 'Accueil';
$studentsLabel = $features->label('students', 'Eleves');

$days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$months = [
    1 => 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre',
];
$now = time();
$today = $days[(int) date('w', $now)].' '.date('j', $now).' '
    .$months[(int) date('n', $now)].' '.date('Y', $now);

$money = static function (float $amount): string {
    if ($amount >= 1000000) {
        return number_format($amount / 1000000, 2, ',', ' ').'M';
    }

    return number_format($amount, 0, ',', ' ');
};

$mentionTotal = array_sum(array_column($mentionSpread, 'value'));
?>
<div class="hero">
    <div class="hero__eyebrow">Tableau de bord</div>
    <h1>Bonjour <?= $this->e($auth['first_name'] ?? '') ?></h1>
    <p class="hero__meta">
        <?= $this->e($today) ?>
        <?php if ($academicYear !== null) : ?>
            &middot; année <?= $this->e($academicYear['label']) ?>
        <?php endif; ?>
        &middot; <?= $this->e($features->typeLabel()) ?>
        <?php if ($openPeriod !== null) : ?>
            &middot; séquence <?= (int) $openPeriod['number'] ?> en cours
        <?php endif; ?>
    </p>

    <div class="hero__chips">
        <span class="hero__chip">
            Recouvrement <strong><?= $this->e(number_format((float) $stats['recovery'], 0, ',', ' ')) ?>%</strong>
        </span>
        <?php if ($attendanceToday['rate'] !== null) : ?>
            <span class="hero__chip">
                Présence <strong><?= $this->e(number_format($attendanceToday['rate'], 0, ',', ' ')) ?>%</strong>
            </span>
        <?php endif; ?>
        <span class="hero__chip">
            <?= $this->e($studentsLabel) ?> <strong><?= $this->number($stats['students']) ?></strong>
        </span>
    </div>
</div>

<?php if ($academicYear === null) : ?>
    <div class="alert alert--error">
        Aucune année scolaire n'est ouverte. Tant qu'il n'y en a pas, ni
        inscription, ni appel, ni note ne peuvent etre enregistrés.
        <a href="/annees-scolaires">Ouvrir une année</a>
    </div>
<?php elseif ($openPeriod === null) : ?>
    <div class="alert alert--warning">
        Aucune séquence n'est ouverte a la saisie : les enseignants ne peuvent
        pas entrer de notes.
        <a href="/annees-scolaires">Ouvrir une séquence</a>
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat stat--tinted stat--blue">
        <div class="stat__icon"><?= $this->raw(Navigation::icon('users')) ?></div>
        <div class="stat__label"><?= $this->e($studentsLabel) ?> inscrits</div>
        <div class="stat__value"><?= $this->number($stats['students']) ?></div>
        <div class="stat__foot"><?= $this->number($stats['enrollments']) ?> inscriptions activés</div>
    </div>

    <div class="stat stat--tinted stat--green">
        <div class="stat__icon"><?= $this->raw(Navigation::icon('check')) ?></div>
        <div class="stat__label">Taux de présence</div>
        <?php if ($attendanceToday['rate'] === null) : ?>
            <div class="stat__value">&mdash;</div>
            <div class="stat__foot">aucun appel saisi aujourd'hui</div>
        <?php else : ?>
            <div class="stat__value"><?= $this->e(number_format($attendanceToday['rate'], 1, ',', ' ')) ?>%</div>
            <div class="stat__foot">
                aujourd'hui, sur <?= $this->number($attendanceToday['recorded']) ?> saisies
            </div>
        <?php endif; ?>
    </div>

    <div class="stat stat--tinted stat--amber">
        <div class="stat__icon"><?= $this->raw(Navigation::icon('edit')) ?></div>
        <div class="stat__label">Notes en attente</div>
        <div class="stat__value"><?= $this->number($pendingGradeEntries) ?></div>
        <div class="stat__foot">
            <?php if ($openPeriod !== null) : ?>
                classes sans saisie &middot; séquence <?= (int) $openPeriod['number'] ?>
            <?php else : ?>
                aucune séquence ouverte
            <?php endif; ?>
        </div>
    </div>

    <div class="stat stat--tinted stat--pink">
        <div class="stat__icon"><?= $this->raw(Navigation::icon('wallet')) ?></div>
        <div class="stat__label">Recouvrement</div>
        <div class="stat__value"><?= $this->e($money((float) $stats['collected'])) ?></div>
        <div class="stat__foot">
            FCFA encaissés &middot;
            <span class="stat__trend stat__trend--up">
                <?= $this->e(number_format((float) $stats['recovery'], 1, ',', ' ')) ?>%
            </span>
        </div>
    </div>
</div>

<div class="chart-grid">
    <div class="card">
        <div class="inline-form" style="justify-content:space-between;align-items:flex-start">
            <div>
                <h2 style="margin:0">Evolution des effectifs</h2>
                <p class="muted" style="margin:0.2rem 0 0">Douze derniers mois</p>
            </div>
            <?php if ($rbac->allows('students:read')) : ?>
                <a class="link-button" href="/students">Voir les <?= $this->e(mb_strtolower($studentsLabel)) ?> &rarr;</a>
            <?php endif; ?>
        </div>
        <?= $this->raw(Chart::line($enrolmentTrend)) ?>
    </div>

    <div class="card">
        <h2 style="margin:0">Resultats publiés</h2>
        <p class="muted" style="margin:0.2rem 0 0">Distribution par mention</p>

        <?php if ($mentionTotal === 0) : ?>
            <p class="muted" style="margin-top:1.5rem">
                Aucun resultat publié pour le moment. La répartition apparaitra
                des la première publication de bulletins.
            </p>
        <?php else : ?>
            <div style="max-width:15rem;margin:1rem auto 0">
                <?= $this->raw(Chart::donut($mentionSpread)) ?>
            </div>
            <?= $this->raw(Chart::legend($mentionSpread)) ?>
        <?php endif; ?>
    </div>
</div>

<div class="chart-grid">
    <div class="card">
        <div class="inline-form" style="justify-content:space-between;align-items:center">
            <h2 style="margin:0">Derniers <?= $this->e(mb_strtolower($studentsLabel)) ?> enregistrés</h2>
            <?php if ($rbac->allows('students:read')) : ?>
                <a class="link-button" href="/students">Tout voir &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if ($recentStudents === []) : ?>
            <p class="muted">Aucun dossier enregistré pour le moment.</p>
        <?php else : ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th><?= $this->e($studentsLabel) ?></th><th>Matricule</th><th>Statut</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentStudents as $student) : ?>
                        <?php $name = trim($student['last_name'].' '.$student['first_name']); ?>
                        <tr>
                            <td>
                                <span class="cell-name">
                                    <span class="avatar avatar--<?= Navigation::colorIndex($name) ?>">
                                        <?= $this->e(Navigation::initials($name)) ?>
                                    </span>
                                    <?= $this->e($name) ?>
                                </span>
                            </td>
                            <td class="muted"><?= $this->e($student['matricule']) ?></td>
                            <td><span class="badge badge--success">Actif</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="margin:0 0 1rem">A traiter</h2>
        <ul class="feed">
            <li>
                <span class="feed__icon"><?= $this->raw(Navigation::icon('clipboard')) ?></span>
                <span class="feed__body">
                    <strong><?= $this->number($pendingApplications) ?> pre-inscription<?= $pendingApplications > 1 ? 's' : '' ?></strong>
                    <span>en attente d'instruction</span>
                </span>
            </li>
            <li>
                <span class="feed__icon"><?= $this->raw(Navigation::icon('edit')) ?></span>
                <span class="feed__body">
                    <strong><?= $this->number($pendingGradeEntries) ?> classe<?= $pendingGradeEntries > 1 ? 's' : '' ?></strong>
                    <span>sans aucune note sur la séquence ouverte</span>
                </span>
            </li>
            <li>
                <span class="feed__icon"><?= $this->raw(Navigation::icon('wallet')) ?></span>
                <span class="feed__body">
                    <strong><?= $this->e($money((float) $stats['outstanding'])) ?> FCFA</strong>
                    <span>restent a recouvrer</span>
                </span>
            </li>
            <li>
                <span class="feed__icon"><?= $this->raw(Navigation::icon('briefcase')) ?></span>
                <span class="feed__body">
                    <strong><?= $this->number($stats['staff']) ?> membres du personnel</strong>
                    <span><?= $this->number($stats['classrooms']) ?> classes ouvertes</span>
                </span>
            </li>
        </ul>
    </div>
</div>
