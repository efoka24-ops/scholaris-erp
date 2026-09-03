<?php
/**
 * Comparatif des etablissements.
 *
 * Un chiffre isole ne sert a rien : c est la comparaison qui designe les
 * ecoles a appeler.
 *
 * @var \Scholaris\View\View $this
 * @var list<array<string, mixed>> $rows
 * @var callable $regionName
 */
$this->extends('layouts.app');
$title = 'Comparatif des établissements';

$money = static fn (float $a): string => $a >= 1000000
    ? number_format($a / 1000000, 2, ',', ' ').'M'
    : number_format($a, 0, ',', ' ');
?>
<div class="page-header">
    <div>
        <h1>Comparatif des établissements</h1>
        <p class="subtitle">
            Effectifs, recouvrement et activite, cote a cote.
        </p>
    </div>
    <a class="button button--secondary" href="/admin/rapports/export">Exporter en CSV</a>
</div>

<div class="card">
    <?php if ($rows === []) : ?>
        <p class="muted">Aucun établissement sur la plateforme.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr>
                <th>Établissement</th><th>Localisation</th><th>Élèves</th><th>Classes</th>
                <th>Comptes</th><th>Facture</th><th>Encaisse</th><th>Taux</th><th>Dernière activite</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td>
                        <strong><?= $this->e($row['name']) ?></strong>
                        <span class="badge"><?= $this->e($row['code']) ?></span>
                        <?php if ((string) $row['platform_status'] === 'SUSPENDED') : ?>
                            <span class="badge badge--warning">suspendu</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted">
                        <?= $this->e($regionName($row['region'] ?? null)) ?>
                        <?php if (($row['city'] ?? null) !== null && $row['city'] !== '') : ?>
                            &middot; <?= $this->e($row['city']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->number($row['students']) ?></td>
                    <td><?= $this->number($row['classrooms']) ?></td>
                    <td><?= $this->number($row['accounts']) ?></td>
                    <td><?= $this->e($money((float) $row['invoiced'])) ?></td>
                    <td><?= $this->e($money((float) $row['collected'])) ?></td>
                    <td>
                        <?php if ($row['collection_rate'] === null) : ?>
                            <span class="muted" title="Rien n a encore ete facture">&mdash;</span>
                        <?php else : ?>
                            <?= $this->e(number_format($row['collection_rate'], 1, ',', ' ')) ?>%
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($row['last_activity'] ?? null) === null) : ?>
                            <span class="muted">jamais</span>
                        <?php else : ?>
                            <?= $this->date($row['last_activity'], 'd/m/Y') ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="muted">
            Un tiret dans la colonne « Taux » signale un établissement qui n'a
            rien facture : ce n'est pas un recouvrement nul.
        </p>
    <?php endif; ?>
</div>
