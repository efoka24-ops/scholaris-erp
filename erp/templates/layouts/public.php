<?php
/**
 * Gabarit des pages publiques, repris de la maquette.
 *
 * La barre de navigation devient opaque au defilement, les onglets et le guide
 * sont interactifs : ces comportements viennent du composant React d'origine,
 * reecrits en JavaScript natif. L'hebergement ne pouvant rien compiler, le
 * script est inline et sans dependance.
 *
 * @var \Scholaris\View\View $this
 * @var string $appName
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title ?? 'Accueil') ?> — SCHOLARIS</title>
    <meta name="description" content="ERP de gestion scolaire concu pour le Cameroun : 25 modules, des inscriptions aux bulletins, de la paie CNPS aux paiements Orange Money et MTN MoMo.">
    <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<nav class="nav" id="nav">
    <div class="nav__inner">
        <a class="brand" href="/">
            <span class="brand__mark">S</span>
            <span class="brand__name">SCHOLARIS<span>.</span></span>
        </a>

        <div class="nav__links">
            <a class="section-tag" href="/#modules">modules</a>
            <a class="section-tag" href="/#apercu">apercu</a>
            <a class="section-tag" href="/#temoignages">temoignages</a>
        </div>

        <div class="nav__actions">
            <a class="btn-ghost" href="/login">Connexion</a>
            <a class="btn-volt" href="/pre-inscription">Pre-inscription</a>
        </div>
    </div>
</nav>

<?= $this->raw($this->section('content')) ?>

<footer class="foot">
    <div class="foot__inner">
        <div class="foot__cols">
            <div>
                <a class="brand" href="/" style="margin-bottom:1rem">
                    <span class="brand__mark" style="width:32px;height:32px">S</span>
                    <span class="brand__name" style="font-size:1rem">SCHOLARIS<span>.</span></span>
                </a>
                <p class="foot__about">
                    ERP de gestion scolaire concu pour le Cameroun par TRU GROUP SARL.
                </p>
            </div>

            <?php
            $columns = [
                'Produit' => ['Modules', 'Tarifs', 'Roadmap', 'Changelog', 'Statut système'],
                'Ressources' => ['Documentation', 'Guide API', 'Templates PDF', 'Formations', 'Webinaires'],
                'Support' => ["Centre d'aide", 'Contact', 'WhatsApp support', '+237 6XX XXX XXX', 'Yaounde, Cameroun'],
            ];
            ?>
            <?php foreach ($columns as $heading => $links) : ?>
                <div>
                    <div class="foot__t"><?= $this->e($heading) ?></div>
                    <ul class="foot__list">
                        <?php foreach ($links as $link) : ?>
                            <li><a href="#"><?= $this->e($link) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="foot__bottom">
            <div class="foot__copy">
                &copy; 2026 TRU GROUP SARL &middot; SCHOLARIS ERP v2.0 &middot; Tous droits reserves
            </div>
            <div class="foot__tags">
                <?php foreach (['PHP 8.1', 'MySQL 8', 'Zero dependance', 'Multi-tenant', 'RBAC', 'RFC-9421', 'pawaPay'] as $tag) : ?>
                    <span class="foot__tag"><?= $this->e($tag) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</footer>

<script>
    // Barre de navigation opaque au-dela de 50 pixels de defilement.
    (function () {
        var nav = document.getElementById('nav');
        if (!nav) return;

        var apply = function () {
            nav.classList.toggle('nav--scrolled', window.scrollY > 50);
        };

        apply();
        window.addEventListener('scroll', apply, { passive: true });
    })();

    // Onglets de l'apercu et accordeon du guide : un seul gestionnaire, pilote
    // par des attributs data, plutot qu'un script par section.
    (function () {
        document.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-tab]');

            if (tab) {
                var group = tab.closest('[data-tabs]');
                if (!group) return;

                group.querySelectorAll('[data-tab]').forEach(function (b) {
                    b.classList.toggle('preview__tab--activé', b === tab);
                });

                group.querySelectorAll('[data-panel]').forEach(function (p) {
                    p.classList.toggle('preview__panel--activé', p.dataset.panel === tab.dataset.tab);
                });

                return;
            }

            var step = event.target.closest('[data-guide]');

            if (step) {
                var guide = step.closest('[data-guide-group]');
                if (!guide) return;

                var target = step.dataset.guide;

                guide.querySelectorAll('[data-guide]').forEach(function (b) {
                    var on = b === step;
                    b.style.background = on ? b.dataset.color + '14' : 'rgba(255,255,255,.03)';
                    b.style.borderColor = on ? b.dataset.color + '44' : 'rgba(255,255,255,.07)';
                    b.querySelector('.guide__chevron').style.transform = on ? 'rotate(180deg)' : 'none';
                });

                guide.querySelectorAll('[data-guide-détail]').forEach(function (d) {
                    d.classList.toggle('guide__detail--activé', d.dataset.guideDetail === target);
                });
            }
        });
    })();
</script>
</body>
</html>
