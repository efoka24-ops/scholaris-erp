<?php
/**
 * Apercu — presences. Repris de TAB_CONTENT.presences de la maquette.
 *
 * @var \Scholaris\View\View $this
 */
$tiles = [
    ['Presents', '24', '#00e5a0'],
    ['Absents', '3', '#ff4d4d'],
    ['Retards', '1', '#ffb800'],
    ['Notifies', '4/4', '#5b21f5'],
];

$pupils = [
    ['Kameni, Alice', 'present', '✅'],
    ['Mbarga, Jean-Paul', 'absent', '🔴'],
    ['Ateba, Christelle', 'present', '✅'],
    ['Fouda, Emmanuel', 'late', '⚠️'],
    ['Ngo, Marie-Claire', 'present', '✅'],
    ['Biya, Patrick K.', 'absent', '🔴'],
];

$backgrounds = [
    'present' => ['rgba(0,229,160,.06)', 'rgba(0,229,160,.15)'],
    'absent' => ['rgba(255,77,77,.08)', 'rgba(255,77,77,.15)'],
    'late' => ['rgba(255,184,0,.08)', 'rgba(255,184,0,.15)'],
];
?>
<div class="preview__panel" data-panel="presences">
    <div class="preview__title" style="margin-bottom:1rem">Appel numerique — Tle C &middot; Math &middot; 08h00</div>

    <div class="tiles tiles--4">
        <?php foreach ($tiles as [$label, $value, $colour]) : ?>
            <div class="tile" style="text-align:center">
                <div class="tile__v" style="color:<?= $this->e($colour) ?>"><?= $this->e($value) ?></div>
                <div class="tile__l"><?= $this->e($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;margin-bottom:0.75rem">
        <?php foreach ($pupils as [$name, $state, $icon]) : ?>
            <?php [$background, $border] = $backgrounds[$state]; ?>
            <div style="display:flex;align-items:center;gap:0.75rem;border-radius:10px;padding:0.5rem 0.75rem;background:<?= $background ?>;border:1px solid <?= $border ?>">
                <span><?= $this->e($icon) ?></span>
                <span style="font-size:.88rem;font-weight:500"><?= $this->e($name) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="border-radius:10px;padding:0.75rem 1rem;background:rgba(91,33,245,.1);border:1px solid rgba(91,33,245,.2)">
        <div class="font-mono" style="font-size:.72rem;color:#7c5af5">
            📱 Notifications WhatsApp envoyees aux parents de Mbarga &amp; Biya &middot; il y a 2 min
        </div>
    </div>
</div>
