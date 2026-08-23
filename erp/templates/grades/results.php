<?php
/**
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $classroom
 * @var array<string, mixed> $period
 * @var array<int, array<string, mixed>> $results
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Resultats - '.$classroom['name'];

$published = $results !== [] && (int) $results[0]['is_published'] === 1;
$average = 0.0;
$passing = 0;

foreach ($results as $result) {
    $average += (float) $result['general_average'];

    if ((float) $result['general_average'] >= 10) {
        $passing++;
    }
}

$average = $results === [] ? 0.0 : round($average / count($results), 2);
?>
<div class="page-header">
    <div>
        <h1>Resultats - <?= $this->e($classroom['name']) ?></h1>
        <p class="subtitle">
            Sequence <?= $this->e($period['number']) ?>
            &middot; <?= $this->number(count($results)) ?> eleve(s)
            <?php if ($published) : ?>
                &middot; <span class="badge">publies</span>
            <?php endif; ?>
        </p>
    </div>
    <a class="button button--secondary" href="/grades">Retour</a>
</div>

<?php if ($results === []) : ?>
    <div class="card">
        <p class="muted">
            Aucun resultat calcule pour cette sequence. Lancez le calcul depuis
            la page Notes apres avoir saisi les evaluations.
        </p>
    </div>
<?php else : ?>
    <div class="stats">
        <div class="stat">
            <div class="stat__label">Moyenne de classe</div>
            <div class="stat__value"><?= $this->e(number_format($average, 2, ',', ' ')) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">Moyennes >= 10</div>
            <div class="stat__value"><?= $this->number($passing) ?> / <?= $this->number(count($results)) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">Taux de reussite</div>
            <div class="stat__value">
                <?= $this->e(number_format(count($results) > 0 ? $passing / count($results) * 100 : 0, 1, ',', ' ')) ?> %
            </div>
        </div>
    </div>

    <table class="table">
        <thead>
        <tr><th>Rang</th><th>Matricule</th><th>Eleve</th><th>Moyenne</th><th>Mention</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $result) : ?>
            <tr>
                <td><strong><?= $this->e($result['rank_position']) ?></strong></td>
                <td><a href="/students/<?= $this->e($result['student_id']) ?>"><?= $this->e($result['matricule']) ?></a></td>
                <td><?= $this->e($result['last_name'].' '.$result['first_name']) ?></td>
                <td><?= $this->e(number_format((float) $result['general_average'], 2, ',', ' ')) ?></td>
                <td><span class="badge"><?= $this->e($result['mention']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($rbac->allows('grades:publish') && ! $published) : ?>
        <form method="post"
              action="/grades/publish/<?= $this->e($classroom['id']) ?>/<?= $this->e($period['id']) ?>"
              class="card"
              onsubmit="return confirm('Publier ces resultats ? Ils deviendront visibles des familles.');">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Publication</h2>
            <p class="muted">
                Tant qu ils ne sont pas publies, ces resultats restent internes a
                l etablissement. La publication les rend visibles des parents et
                des eleves.
            </p>
            <button type="submit" class="button">Publier les resultats</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
