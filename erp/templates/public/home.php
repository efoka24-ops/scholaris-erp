<?php
/**
 * Page d'accueil publique.
 *
 * Elle sert trois publics distincts, d'ou les trois actions mises en avant :
 * les familles (pre-inscription), les chefs d'etablissement (ouverture d'un
 * espace) et le personnel deja equipe (connexion).
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $tenants
 * @var string $appName
 */
$this->extends('layouts.public');
$title = 'Accueil';

$cycles = [
    ['Maternelle', 'Eveil et preparation a la vie scolaire'],
    ['Primaire', 'SIL au CM2 : apprentissages fondamentaux'],
    ['Secondaire 1er cycle', '6eme a la 3eme'],
    ['Secondaire 2nd cycle', '2nde a la Terminale, toutes series'],
];

$steps = [
    'Vous deposez une pre-inscription en ligne pour votre enfant.',
    'Notre secretariat etudie le dossier et vous contacte par telephone.',
    'Vous completez le dossier physique et finalisez l inscription a l etablissement.',
];
?>
<section class="hero">
    <h1 class="hero__title"><?= $this->e($appName) ?></h1>
    <p class="hero__lead">
        La gestion scolaire de bout en bout : inscriptions, notes, bulletins et
        scolarite, pour les etablissements du primaire au superieur.
    </p>

    <div class="hero__actions">
        <a class="button" href="/pre-inscription">Faire une pre-inscription</a>
        <a class="button button--secondary" href="/demande-etablissement">Creer un etablissement</a>
        <a class="button button--secondary" href="/login">Se connecter</a>
    </div>

    <p class="hero__note">
        Directeur d ecole ? <strong>Creer un etablissement</strong> vous permet de
        demander l ouverture de votre espace ; apres validation, vous recevrez vos
        identifiants par email.
    </p>
</section>

<section class="section">
    <h2 class="section__title">Nos cycles</h2>

    <div class="cards">
        <?php foreach ($cycles as [$name, $description]) : ?>
            <article class="cards__item">
                <h3 class="cards__title"><?= $this->e($name) ?></h3>
                <p class="cards__text"><?= $this->e($description) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <h2 class="section__title">Comment se deroule l admission ?</h2>

    <ol class="steps">
        <?php foreach ($steps as $index => $step) : ?>
            <li class="steps__item">
                <span class="steps__number"><?= $index + 1 ?></span>
                <span><?= $this->e($step) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<?php if ($tenants !== []) : ?>
    <section class="section">
        <h2 class="section__title">Etablissements ouverts a la pre-inscription</h2>

        <div class="cards">
            <?php foreach ($tenants as $tenant) : ?>
                <article class="cards__item">
                    <h3 class="cards__title"><?= $this->e($tenant['name']) ?></h3>
                    <p class="cards__text">Code etablissement : <?= $this->e($tenant['code']) ?></p>
                    <a href="/pre-inscription">Deposer un dossier</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php else : ?>
    <section class="section">
        <p class="muted">
            Aucun etablissement n a encore ouvert la pre-inscription en ligne.
            Les demandes restent possibles des qu un etablissement l active.
        </p>
    </section>
<?php endif; ?>
