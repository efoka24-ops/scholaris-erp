<?php
/**
 * Apercu — saisie des notes. Repris de TAB_CONTENT.notes de la maquette.
 *
 * @var \Scholaris\View\View $this
 */
$rows = [
    ['Kameni, Alice M.', '17', '15', '18', '17.0', '#c8ff00'],
    ['Mbarga, Jean-Paul', '11', '9', '13', '11.5', '#ffb800'],
    ['Ateba, Christelle', '14', '16', '15', '15.0', '#c8ff00'],
    ['Fouda, Emmanuel', '8', '—', '9', '8.5', '#ff4d4d'],
    ['Ngo, Marie-Claire', '19', '18', '20', '19.0', '#c8ff00'],
    ['Biya, Patrick K.', '12', '14', '11', '12.4', '#ffb800'],
];

$columns = '1.5fr repeat(4, 72px)';
?>
<div class="preview__panel preview__panel--active" data-panel="notes">
    <div class="preview__head">
        <div>
            <div class="preview__title">Grille de saisie — Tle C &middot; Mathematiques &middot; Seq. 1</div>
            <div class="preview__sub">28 eleves &middot; Periode ouverte &middot; 2026–2027</div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <span class="chip" style="background:rgba(255,184,0,.15);color:#ffb800;border-color:rgba(255,184,0,.3)">📥 Template XLS</span>
            <span class="chip" style="background:#5b21f5;color:#c8ff00;font-weight:700">🔒 Verrouiller</span>
        </div>
    </div>

    <div class="grid-table">
        <div class="grid-table__head" style="grid-template-columns:<?= $columns ?>">
            <span>Eleve</span>
            <span style="text-align:center">Devoir</span>
            <span style="text-align:center">Test</span>
            <span style="text-align:center">Examen</span>
            <span style="text-align:center">Moy.</span>
        </div>

        <?php foreach ($rows as [$name, $devoir, $test, $examen, $moyenne, $colour]) : ?>
            <div class="grid-table__row" style="grid-template-columns:<?= $columns ?>">
                <span style="color:#f2f0e8;font-weight:500"><?= $this->e($name) ?></span>
                <?php foreach ([$devoir, $test, $examen] as $mark) : ?>
                    <?php
                    $markColour = $mark === '—'
                        ? 'rgba(242,240,232,.25)'
                        : ((float) $mark >= 10 ? '#00e5a0' : '#ff4d4d');
                    ?>
                    <span class="font-mono" style="text-align:center;color:<?= $markColour ?>"><?= $this->e($mark) ?></span>
                <?php endforeach; ?>
                <span class="font-display" style="text-align:center;font-weight:700;color:<?= $this->e($colour) ?>">
                    <?= $this->e($moyenne) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
