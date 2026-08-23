<?php
/**
 * Apercu — bulletins. Repris de TAB_CONTENT.bulletins de la maquette.
 *
 * @var \Scholaris\View\View $this
 */
$tiles = [
    ['Generes', '35/35', '#00e5a0'],
    ['Envoyes', '28/35', '#ffb800'],
    ['Confirmes', '21', '#5b21f5'],
];

$fields = [
    ['Eleve', 'Kameni, Alice M.'],
    ['Moyenne', '15.4/20'],
    ['Rang', '3/35'],
];
?>
<div class="preview__panel" data-panel="bulletins">
    <div class="preview__head">
        <div>
            <div class="preview__title">Bulletins — 1ere A &middot; Trimestre 1</div>
            <div class="preview__sub">35 generes &middot; 28 envoyes &middot; 21 lus</div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <span class="chip" style="background:rgba(0,229,160,.15);color:#00e5a0;border-color:rgba(0,229,160,.3)">📥 Tout telecharger</span>
            <span class="chip" style="background:#5b21f5;color:#c8ff00;font-weight:700">📤 Envoyer WhatsApp</span>
        </div>
    </div>

    <div class="tiles tiles--3">
        <?php foreach ($tiles as [$label, $value, $colour]) : ?>
            <div class="tile">
                <div class="tile__v" style="color:<?= $this->e($colour) ?>"><?= $this->e($value) ?></div>
                <div class="tile__l"><?= $this->e($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="border-radius:12px;padding:1.25rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;gap:1rem">
            <div>
                <div class="font-display" style="font-weight:700;font-size:.9rem">LYCEE BILINGUE DE GAROUA</div>
                <div class="font-mono" style="font-size:.72rem;color:rgba(242,240,232,.35)">BULLETIN DE NOTES — T1 &middot; 2026–2027</div>
            </div>
            <div style="width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-size:.72rem;font-weight:700;background:rgba(91,33,245,.25);border:1px solid rgba(91,33,245,.5);color:#c8ff00">
                QR
            </div>
        </div>

        <div style="height:1px;margin-bottom:0.75rem;background:linear-gradient(90deg,#5b21f5,#ff3d8a,#ffb800)"></div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
            <?php foreach ($fields as [$label, $value]) : ?>
                <div>
                    <div class="font-mono" style="color:rgba(242,240,232,.4);font-size:.65rem"><?= $this->e($label) ?></div>
                    <div class="font-display" style="font-weight:700"><?= $this->e($value) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:0.75rem;padding:0.5rem 0.75rem;border-radius:10px;font-size:.78rem;background:rgba(0,229,160,.08);color:#00e5a0">
            ✅ Envoye sur WhatsApp au parent — il y a 3 min
        </div>
    </div>
</div>
