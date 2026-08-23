<?php
/**
 * Tableau de bord parent : ses enfants, et rien d'autre.
 *
 * Les moyennes n'apparaissent qu'une fois publiees : un parent ne doit pas
 * decouvrir un resultat avant que le conseil de classe ne l'ait arrete.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $children
 */
$this->extends('layouts.app');
$title = 'Mes enfants';
?>
<h1>Mes enfants</h1>
<p class="subtitle"><?= $this->number(count($children)) ?> dossier(s) rattache(s) a votre compte</p>

<?php if ($children === []) : ?>
    <div class="card">
        <p class="muted">
            Aucun enfant rattache a votre compte. Contactez le secretariat de
            l etablissement pour effectuer ce rattachement.
        </p>
    </div>
<?php else : ?>
    <?php foreach ($children as $child) : ?>
        <div class="card">
            <div class="page-header">
                <div>
                    <h2><?= $this->e($child['last_name'].' '.$child['first_name']) ?></h2>
                    <p class="subtitle" style="margin:0">
                        <?= $this->e($child['matricule']) ?>
                        <?php if ($child['classroom_name']) : ?>
                            &middot; <?= $this->e($child['classroom_name']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="badge"><?= $this->e($child['status']) ?></span>
            </div>

            <div class="stats" style="margin-top:1rem">
                <div class="stat">
                    <div class="stat__label">Absences</div>
                    <div class="stat__value"><?= $this->number($child['absences']) ?></div>
                </div>

                <?php if ($child['invoice'] !== null) : ?>
                    <div class="stat">
                        <div class="stat__label">Scolarite reglee</div>
                        <div class="stat__value stat__value--money"><?= $this->money($child['invoice']['paid_amount']) ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat__label">Reste a payer</div>
                        <div class="stat__value stat__value--money"><?= $this->money($child['invoice']['balance']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <h3 style="margin-top:1rem">Resultats publies</h3>

            <?php if ($child['results'] === []) : ?>
                <p class="muted">Aucun resultat publie pour le moment.</p>
            <?php else : ?>
                <table class="table">
                    <thead><tr><th>Sequence</th><th>Moyenne</th><th>Rang</th><th>Mention</th></tr></thead>
                    <tbody>
                    <?php foreach ($child['results'] as $result) : ?>
                        <tr>
                            <td>Sequence <?= $this->e($result['period_number']) ?></td>
                            <td class="font-mono" style="font-weight:700;color:<?= (float) $result['general_average'] >= 10 ? '#00e5a0' : '#ff4d4d' ?>">
                                <?= $this->e(number_format((float) $result['general_average'], 2, ',', ' ')) ?>
                            </td>
                            <td class="font-mono">
                                <?= $this->e($result['rank_position']) ?>
                                <?php if ($result['total_students']) : ?>
                                    / <?= $this->e($result['total_students']) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= $this->e($result['mention']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
