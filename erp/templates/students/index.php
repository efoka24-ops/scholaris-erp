<?php
/**
 * Liste des eleves.
 *
 * Le bandeau de compteurs repond a la question qu'on se pose en ouvrant cet
 * ecran — combien d'eleves, combien de dossiers en attente — sans avoir a
 * filtrer quatre fois pour l'apprendre.
 *
 * La classe et la moyenne figurent dans le tableau : ce sont les deux
 * informations qu'on y cherche, et il fallait jusqu'ici ouvrir chaque fiche
 * pour les obtenir.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $students
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var int $perPage
 * @var string $search
 * @var string $status
 * @var string $classroom
 * @var list<string> $statuses
 * @var array<string, int> $counts
 * @var array<int, array<string, mixed>> $classrooms
 * @var \Scholaris\Auth\Rbac $rbac
 * @var \Scholaris\Tenant\Features $features
 */

use Scholaris\View\Navigation;

$this->extends('layouts.app');
$label = $features->label('students', 'Eleves');
$title = $label.' & inscriptions';

$statusLabels = [
    'ACTIVE' => ['Actif', 'success'],
    'SUSPENDED' => ['Suspendu', 'warning'],
    'GRADUATED' => ['Diplome', 'neutral'],
    'EXCLUDED' => ['Exclu', 'danger'],
    'ABANDONED' => ['Abandon', 'neutral'],
];

/** La couleur porte le verdict : on lit la ligne sans comparer de tete. */
$scoreClass = static function (?float $average): string {
    if ($average === null) {
        return 'score--none';
    }

    if ($average >= 14.0) {
        return 'score--good';
    }

    return $average >= 10.0 ? 'score--fair' : 'score--poor';
};

$query = static fn (array $extra): string => '/students?'.http_build_query(array_merge(
    ['q' => $search, 'status' => $status, 'classe' => $classroom],
    $extra
));
?>
<div class="page-header">
    <div>
        <h1><?= $this->e($title) ?></h1>
        <p class="subtitle">
            <?= $this->number($total) ?> <?= $this->e(mb_strtolower($label)) ?>
            <?php if ($search !== '' || $status !== '' || $classroom !== '') : ?>
                correspondant a votre recherche
            <?php endif; ?>
        </p>
    </div>
    <?php if ($rbac->allows('students:create')) : ?>
        <a class="button" href="/students/create">Nouvel eleve</a>
    <?php endif; ?>
</div>

<div class="stats stats--compact">
    <div class="stat">
        <div class="stat__value"><?= $this->number($counts['TOTAL']) ?></div>
        <div class="stat__label">Total</div>
    </div>
    <div class="stat stat--figure-green">
        <div class="stat__value"><?= $this->number($counts['ACTIVE']) ?></div>
        <div class="stat__label">Actifs</div>
    </div>
    <div class="stat stat--figure-amber">
        <div class="stat__value"><?= $this->number($counts['SUSPENDED']) ?></div>
        <div class="stat__label">Suspendus</div>
    </div>
    <div class="stat stat--figure-violet">
        <div class="stat__value"><?= $this->number($counts['PENDING']) ?></div>
        <div class="stat__label">Pre-inscriptions en attente</div>
    </div>
</div>

<div class="card">
    <form method="get" action="/students" class="inline-form" style="gap:0.75rem;align-items:flex-end">
        <div class="field" style="margin-bottom:0;flex:1 1 18rem">
            <label for="q">Rechercher</label>
            <input id="q" type="search" name="q" placeholder="Nom, prenom ou matricule"
                   value="<?= $this->e($search) ?>">
        </div>
        <div class="field" style="margin-bottom:0">
            <label for="classe">Classe</label>
            <select id="classe" name="classe">
                <option value="">Toutes les classes</option>
                <?php foreach ($classrooms as $room) : ?>
                    <option value="<?= $this->e($room['id']) ?>" <?= $classroom === (string) $room['id'] ? 'selected' : '' ?>>
                        <?= $this->e($room['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="margin-bottom:0">
            <label for="status">Statut</label>
            <select id="status" name="status">
                <option value="">Tous les statuts</option>
                <?php foreach ($statuses as $option) : ?>
                    <option value="<?= $this->e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                        <?= $this->e($statusLabels[$option][0] ?? $option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button button--secondary">Filtrer</button>
        <?php if ($search !== '' || $status !== '' || $classroom !== '') : ?>
            <a class="link-button" href="/students">Effacer</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if ($students === []) : ?>
        <p class="muted">
            Aucun <?= $this->e(mb_strtolower(rtrim($label, 's'))) ?> ne correspond a cette recherche.
        </p>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th><?= $this->e(rtrim($label, 's')) ?></th>
                    <th>Matricule</th>
                    <th>Classe</th>
                    <th>Statut</th>
                    <th>Moyenne</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student) : ?>
                    <?php
                    $name = trim($student['last_name'].' '.$student['first_name']);
                    [$statusLabel, $statusTone] = $statusLabels[$student['status']]
                        ?? [$student['status'], 'neutral'];
                    ?>
                    <tr>
                        <td>
                            <span class="cell-name">
                                <span class="avatar avatar--<?= Navigation::colorIndex($name) ?>">
                                    <?= $this->e(Navigation::initials($name)) ?>
                                </span>
                                <a href="/students/<?= $this->e($student['id']) ?>"><?= $this->e($name) ?></a>
                            </span>
                        </td>
                        <td class="muted"><?= $this->e($student['matricule']) ?></td>
                        <td>
                            <?php if (($student['classroom_name'] ?? null) === null) : ?>
                                <span class="muted">non inscrit</span>
                            <?php else : ?>
                                <span class="badge"><?= $this->e($student['classroom_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= $this->e($statusTone) ?>">
                                <?= $this->e($statusLabel) ?>
                            </span>
                        </td>
                        <td>
                            <?php $average = $student['average'] ?? null; ?>
                            <span class="score <?= $this->e($scoreClass($average)) ?>">
                                <?php if ($average === null) : ?>
                                    &mdash;
                                <?php else : ?>
                                    <?= $this->e(number_format($average, 2, ',', ' ')) ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <a class="link-button" href="/students/<?= $this->e($student['id']) ?>">Voir</a>
                            <?php if ($rbac->allows('students:update')) : ?>
                                <a class="link-button" href="/students/<?= $this->e($student['id']) ?>/edit">Modifier</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1) : ?>
            <p class="muted" style="margin-top:1rem">
                Page <?= $this->number($page) ?> sur <?= $this->number($pages) ?>
                <?php if ($page > 1) : ?>
                    &middot; <a href="<?= $this->e($query(['page' => $page - 1])) ?>">precedente</a>
                <?php endif; ?>
                <?php if ($page < $pages) : ?>
                    &middot; <a href="<?= $this->e($query(['page' => $page + 1])) ?>">suivante</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <p class="muted">
            Un tiret dans la colonne « Moyenne » signale un dossier sans
            resultat publie : ce n est pas une note nulle.
        </p>
    <?php endif; ?>
</div>
