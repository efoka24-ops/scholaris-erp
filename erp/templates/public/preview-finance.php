<?php
/**
 * Apercu — finance. Repris de TAB_CONTENT.finance de la maquette.
 *
 * @var \Scholaris\View\View $this
 */
$kpis = [
    ['Recettes / mois', '24.5M FCFA', '#00e5a0'],
    ['Taux recouvrement', '87.3%', '#c8ff00'],
    ['Impayes', '8.2M FCFA', '#ff4d4d'],
    ['Orange Money', '1 247 txn', '#ffb800'],
];

$entries = [
    ['Kameni, Alice', 'Scolarite T1', '125 000 FCFA', 'Orange Money', '#ffb800'],
    ['Mbarga, Jean-Paul', 'Internat', '85 000 FCFA', 'Especes', '#00e5a0'],
    ['Fouda, Emmanuel', 'Transport', '45 000 FCFA', 'MTN MoMo', '#00c2ff'],
    ['Ateba, Marc', 'Inscription', '35 000 FCFA', 'Especes', '#00e5a0'],
];
?>
<div class="preview__panel" data-panel="finance">
    <div class="preview__title" style="margin-bottom:1rem">Tableau de bord financier — 2026–2027</div>

    <div class="tiles tiles--4">
        <?php foreach ($kpis as [$label, $value, $colour]) : ?>
            <div class="tile">
                <div class="tile__v" style="color:<?= $this->e($colour) ?>;font-size:1rem"><?= $this->e($value) ?></div>
                <div class="tile__l"><?= $this->e($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="border-radius:12px;padding:1rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07)">
        <div class="font-mono" style="font-size:.72rem;color:rgba(242,240,232,.4);margin-bottom:0.75rem">
            JOURNAL SYSCOHADA — DERNIERES ECRITURES
        </div>

        <?php foreach ($entries as [$name, $label, $amount, $method, $colour]) : ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.6rem 0;border-top:1px solid rgba(255,255,255,.05);gap:1rem">
                <div>
                    <div style="font-size:.88rem;font-weight:600"><?= $this->e($name) ?></div>
                    <div class="font-mono" style="font-size:.72rem;color:rgba(242,240,232,.35)"><?= $this->e($label) ?></div>
                </div>
                <div style="text-align:right">
                    <div class="font-mono" style="font-weight:600;font-size:.88rem;color:<?= $this->e($colour) ?>"><?= $this->e($amount) ?></div>
                    <div class="font-mono" style="font-size:.72rem;color:rgba(242,240,232,.3)"><?= $this->e($method) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
