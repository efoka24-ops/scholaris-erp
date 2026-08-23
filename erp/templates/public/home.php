<?php
/**
 * Page d'accueil publique, reprise de la maquette section par section :
 * hero, bandeau defilant, 25 modules, apercu a onglets, chiffres, guide,
 * temoignages, appel a l'action.
 *
 * Le contenu (modules, guide, temoignages) vient de database/landing-content.php,
 * extrait automatiquement de la maquette pour n'en omettre aucune entree.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $tenants
 * @var array<int, array<string, mixed>> $modules
 * @var array<int, array<string, mixed>> $guide
 * @var array<int, array<string, mixed>> $testimonials
 */
$this->extends('layouts.public');
$title = 'Accueil';

$marquee = [
    'Bulletins PDF', 'Orange Money', 'MTN MoMo', 'WhatsApp Business', 'SYSCOHADA',
    'LMD / ECTS', 'Multi-tenancy', 'QR Code Auth', "Africa's Talking", 'Playwright E2E',
    'BullMQ', 'Redis', 'Prisma ORM', 'NestJS', 'Next.js 14', 'TypeScript Strict',
    'PostgreSQL 16', 'Turborepo', 'Jest', 'Supertest',
];

$heroStats = [['25', 'modules'], ['200+', 'endpoints'], ['~100', 'ecrans'], ['3 types', 'de tests']];

$mockKpis = [
    ['Eleves', '1 247', '#5b21f5'],
    ['Recouvr.', '87.3%', '#00e5a0'],
    ['Bulletins', '3 842', '#ffb800'],
    ['Impayes', '8.2M', '#ff4d4d'],
];

$mockBars = [65, 80, 72, 88, 74, 92, 68, 84, 76, 90, 71, 86, 79, 93, 67, 82, 75, 89];

$mockRows = [
    ['Kameni, Alice', 'Tle C', '15.4', '1', 'Passe'],
    ['Mbarga, Jean-Paul', '3eme A', '9.8', '28', 'Rattrapage'],
    ['Fouda, Claire', '1ere D', '17.2', '2', 'Passe'],
    ['Ateba, Marc', 'Tle A', '11.6', '14', 'Passe'],
];

$figures = [
    ['25', '', 'Modules integres'],
    ['200', '+', 'Endpoints API REST'],
    ['~100', '', 'Ecrans frontend'],
    ['99.9', '%', 'Disponibilite'],
    ['60', '', 'Bulletins/min'],
    ['3', '', 'Types de tests'],
];

$features = [
    ['⚡', 'Saisie hors-ligne', 'Notes saisies sans connexion. Synchronisation automatique au retour en ligne.'],
    ['🔒', 'Donnees au Cameroun', 'Infrastructure hebergee localement. Vos donnees ne quittent jamais le pays.'],
    ['📊', 'Declarations automatiques', 'DIPE, CNPS, IRPP generes automatiquement chaque mois. Zero saisie manuelle.'],
];
?>

<!-- ── HERO ────────────────────────────────────────────────────────────── -->
<section class="hero noise">
    <div class="decor">
        <div class="glow" style="top:12%;left:4%;width:500px;height:500px;background:radial-gradient(circle,rgba(91,33,245,.2) 0%,transparent 70%)"></div>
        <div class="glow" style="bottom:5%;right:5%;width:380px;height:380px;background:radial-gradient(circle,rgba(200,255,0,.07) 0%,transparent 70%)"></div>

        <div class="shape tri anim-float-a" style="top:9%;left:5%;width:70px;height:70px;background:#5b21f5;opacity:.4"></div>
        <div class="shape dia anim-float-b" style="top:20%;right:7%;width:52px;height:52px;background:#c8ff00;opacity:.5"></div>
        <div class="shape anim-float-c" style="bottom:20%;left:9%;width:44px;height:44px;background:#ff3d8a;opacity:.4;border-radius:50%"></div>
        <div class="shape anim-float-a" style="bottom:12%;right:14%;width:60px;height:60px;background:#00c2ff;opacity:.35;border-radius:10px"></div>
        <div class="shape tri anim-float-b" style="top:50%;right:4%;width:38px;height:38px;background:#ffb800;opacity:.45"></div>
        <div class="shape hex anim-float-c" style="top:35%;left:3%;width:42px;height:42px;background:#00e5a0;opacity:.35"></div>

        <div class="ring anim-spin-cw" style="top:18%;left:40%;width:240px;height:240px;border:1px solid rgba(200,255,0,.1)"></div>
        <div class="ring anim-spin-ccw" style="top:10%;left:34%;width:340px;height:340px;border:1px solid rgba(91,33,245,.08)"></div>

        <svg style="position:absolute;inset:0;width:100%;height:100%;opacity:.03" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="grid" x="0" y="0" width="48" height="48" patternUnits="userSpaceOnUse">
                    <path d="M48 0H0V48" fill="none" stroke="white" stroke-width=".5"/>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#grid)"/>
        </svg>
    </div>

    <div class="hero__inner">
        <div class="hero__grid">
            <div class="anim-slide-up">
                <div class="hero__badge">
                    <span class="hero__badge-dot"></span>
                    <span class="section-tag" style="color:#c8ff00">v2.0 &middot; Aout 2026 &middot; TRU GROUP SARL</span>
                </div>

                <h1 class="hero__title">
                    L'ERP<br>
                    <span class="gradient-volt">SCOLAIRE</span><br>
                    du <span style="color:#ffb800">Cameroun</span>
                </h1>

                <p class="hero__lead">
                    <strong>25 modules integres</strong> — des inscriptions aux bulletins,
                    de la paie CNPS aux paiements Orange Money &amp; MTN MoMo.
                    Concu pour tous les etablissements camerounais.
                </p>

                <div class="hero__actions">
                    <a class="btn-volt" href="#guide">Voir le guide complet →</a>
                    <a class="btn-ghost" href="/demande-etablissement">Creer mon etablissement</a>
                </div>

                <div class="hero__stats">
                    <?php foreach ($heroStats as [$number, $label]) : ?>
                        <div>
                            <div class="hero__stat-n"><?= $this->e($number) ?></div>
                            <div class="section-tag hero__stat-l"><?= $this->e($label) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Maquette de tableau de bord -->
            <div class="mock anim-pulse">
                <div class="mock__bar">
                    <span class="mock__dot" style="background:#ff4d4d"></span>
                    <span class="mock__dot" style="background:#ffb800"></span>
                    <span class="mock__dot" style="background:#00e5a0"></span>
                    <span class="mock__url">scholaris-erp.trugroup.cm/dashboard</span>
                </div>

                <div class="mock__body">
                    <div class="mock__kpis">
                        <?php foreach ($mockKpis as [$label, $value, $colour]) : ?>
                            <div class="mock__kpi">
                                <div class="mock__kpi-v" style="color:<?= $this->e($colour) ?>"><?= $this->e($value) ?></div>
                                <div class="mock__kpi-l"><?= $this->e($label) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mock__chart">
                        <div class="mock__chart-t">MOYENNES — SEQUENCE 1 &middot; TOUTES CLASSES</div>
                        <div class="mock__bars">
                            <?php foreach ($mockBars as $value) : ?>
                                <?php $colour = $value >= 80 ? '#c8ff00' : ($value >= 70 ? '#5b21f5' : '#ff3d8a'); ?>
                                <div class="mock__bar-item" style="height:<?= $this->e($value) ?>%;background:<?= $this->e($colour) ?>"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mock__table">
                        <?php foreach ($mockRows as [$name, $classroom, $average, $rank, $status]) : ?>
                            <div class="mock__row">
                                <span style="color:#f2f0e8;font-weight:600"><?= $this->e($name) ?></span>
                                <span class="font-mono" style="color:rgba(242,240,232,.4)"><?= $this->e($classroom) ?></span>
                                <span class="font-mono" style="font-weight:700;color:<?= (float) $average >= 10 ? '#c8ff00' : '#ff4d4d' ?>"><?= $this->e($average) ?></span>
                                <span class="font-mono" style="color:rgba(242,240,232,.4)">#<?= $this->e($rank) ?></span>
                                <span style="color:rgba(242,240,232,.6)"><?= $this->e($status) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mock__badge mock__badge--tr">🇨🇲 Made in Cameroun</div>
                <div class="mock__badge mock__badge--bl">Orange Money &middot; MTN MoMo ✓</div>
            </div>
        </div>
    </div>

    <div class="hero__scroll">
        <span class="section-tag">DECOUVRIR</span>
        <div class="hero__scroll-line"></div>
    </div>
</section>

<!-- ── BANDEAU DEFILANT ────────────────────────────────────────────────── -->
<div class="marquee">
    <div class="marquee__track anim-marquee">
        <?php foreach (array_merge($marquee, $marquee) as $item) : ?>
            <span class="marquee__item">
                <?= $this->e($item) ?>
                <span class="marquee__sep">◆</span>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── 25 MODULES ──────────────────────────────────────────────────────── -->
<section class="section section--dark" id="modules">
    <div class="section__inner">
        <div class="section__head section__head--split">
            <div>
                <span class="section-tag" style="color:#5b21f5">— 25 modules complets</span>
                <h2 class="section__title">
                    Tout ce dont votre<br>
                    <span class="gradient-all">etablissement a besoin</span>
                </h2>
            </div>
            <p class="section__note">
                Du module AUTH jusqu'a l'IA predictive — un systeme coherent, teste, documente.
            </p>
        </div>

        <div class="modules">
            <?php foreach ($modules as $module) : ?>
                <div class="module"
                     onmouseover="this.style.background='<?= $this->e($module['color']) ?>18';this.style.borderColor='<?= $this->e($module['color']) ?>44';this.querySelector('.module__name').style.color='<?= $this->e($module['color']) ?>'"
                     onmouseout="this.style.background='';this.style.borderColor='';this.querySelector('.module__name').style.color=''">
                    <span class="module__icon"><?= $this->e($module['icon']) ?></span>
                    <div class="module__name"><?= $this->e($module['name']) ?></div>
                    <div class="section-tag module__tag"><?= $this->e($module['tag']) ?></div>
                    <div class="module__desc"><?= $this->e($module['desc']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── APERCU A ONGLETS ────────────────────────────────────────────────── -->
<section class="section section--fade" id="apercu">
    <div class="section__inner">
        <div class="section__head section__head--center">
            <div>
                <span class="section-tag" style="color:#ff3d8a">— Interface en action</span>
                <h2 class="section__title">Un outil <span class="gradient-fire">concu pour vos equipes</span></h2>
                <p class="section__note" style="max-width:none;margin-top:0.5rem">Navigation intuitive. Formes en 30 minutes.</p>
            </div>
        </div>

        <div class="preview" data-tabs>
            <div class="preview__tabs">
                <button class="preview__tab preview__tab--active" data-tab="notes">Saisie Notes</button>
                <button class="preview__tab" data-tab="bulletins">Bulletins</button>
                <button class="preview__tab" data-tab="finance">Finance</button>
                <button class="preview__tab" data-tab="presences">Presences</button>
            </div>

            <div class="preview__body">
                <?= $this->include('public.preview-notes') ?>
                <?= $this->include('public.preview-bulletins') ?>
                <?= $this->include('public.preview-finance') ?>
                <?= $this->include('public.preview-presences') ?>
            </div>
        </div>
    </div>
</section>

<!-- ── CHIFFRES ────────────────────────────────────────────────────────── -->
<section class="section section--volt">
    <div class="section__inner">
        <div class="section__head section__head--center">
            <div>
                <span class="section-tag">— SCHOLARIS EN CHIFFRES</span>
                <h2 class="section__title">Concu pour passer a l'echelle</h2>
            </div>
        </div>

        <div class="figures">
            <?php foreach ($figures as [$number, $unit, $label]) : ?>
                <div class="figure">
                    <div class="figure__n"><?= $this->e($number.$unit) ?></div>
                    <div class="figure__l"><?= $this->e($label) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="features">
            <?php foreach ($features as [$icon, $heading, $text]) : ?>
                <div class="feature">
                    <div class="feature__i"><?= $this->e($icon) ?></div>
                    <div class="feature__t"><?= $this->e($heading) ?></div>
                    <div class="feature__d"><?= $this->e($text) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── GUIDE ───────────────────────────────────────────────────────────── -->
<section class="section section--dark" id="guide">
    <div class="section__inner">
        <div class="section__head">
            <div>
                <span class="section-tag" style="color:#00e5a0">— Guide d'utilisation</span>
                <h2 class="section__title">Prise en main<br><span style="color:#00e5a0">module par module</span></h2>
                <p class="section__note" style="max-width:500px;margin-top:0.75rem;line-height:1.7">
                    Chaque module est concu pour etre operationnel en moins d'une heure.
                    Suivez les etapes dans l'ordre pour un deploiement sans friction.
                </p>
            </div>
        </div>

        <div class="guide" data-guide-group>
            <div class="guide__list">
                <?php foreach ($guide as $index => $entry) : ?>
                    <?php $active = $index === 0; ?>
                    <button type="button" class="guide__item" data-guide="<?= $this->e($entry['step']) ?>"
                            data-color="<?= $this->e($entry['color']) ?>"
                            style="<?= $active
                                ? 'background:'.$this->e($entry['color']).'14;border-color:'.$this->e($entry['color']).'44'
                                : '' ?>">
                        <span class="guide__num"><?= $this->e($entry['step']) ?></span>
                        <span style="flex:1;min-width:0">
                            <span class="guide__t"><?= $this->e($entry['title']) ?></span>
                            <span class="section-tag guide__s" style="color:<?= $active ? $this->e($entry['color']) : 'rgba(242,240,232,.2)' ?>">
                                <?= $this->e($entry['subtitle']) ?>
                            </span>
                        </span>
                        <span class="guide__chevron" style="<?= $active ? 'transform:rotate(180deg)' : '' ?>">▼</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div>
                <?php foreach ($guide as $index => $entry) : ?>
                    <div class="guide__detail<?= $index === 0 ? ' guide__detail--active' : '' ?>"
                         data-guide-detail="<?= $this->e($entry['step']) ?>"
                         style="border-color:<?= $this->e($entry['color']) ?>28">
                        <div class="guide__detail-head">
                            <div class="guide__detail-icon"
                                 style="background:<?= $this->e($entry['color']) ?>22;border:1px solid <?= $this->e($entry['color']) ?>44">
                                <?= $this->e($entry['icon']) ?>
                            </div>
                            <div>
                                <div class="guide__detail-t"><?= $this->e($entry['title']) ?></div>
                                <span class="section-tag" style="color:<?= $this->e($entry['color']) ?>"><?= $this->e($entry['subtitle']) ?></span>
                            </div>
                        </div>

                        <div class="guide__steps">
                            <?php foreach ($entry['items'] as $step => $item) : ?>
                                <div class="guide__step">
                                    <span class="guide__step-n"><?= $step + 1 ?></span>
                                    <p><?= $this->e($item) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="guide__tip">
                            <span style="font-size:1.1rem;flex-shrink:0">💡</span>
                            <p>
                                <strong style="color:<?= $this->e($entry['color']) ?>">Conseil expert : </strong>
                                <?= $this->e($entry['tip']) ?>
                            </p>
                        </div>

                        <div>
                            <span class="section-tag" style="color:rgba(242,240,232,.35);margin-bottom:0.5rem">CRITERES DE VALIDATION</span>
                            <div class="guide__validate">
                                <?php foreach ($entry['validate'] as $criterion) : ?>
                                    <span class="guide__check"
                                          style="background:<?= $this->e($entry['color']) ?>14;color:<?= $this->e($entry['color']) ?>;border:1px solid <?= $this->e($entry['color']) ?>30">
                                        ✓ <?= $this->e($criterion) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="guide__cta">
            <div>
                <div class="font-display" style="font-weight:700;font-size:1.25rem">Guide complet SCHOLARIS ERP v2.0</div>
                <div style="color:rgba(242,240,232,.45);font-size:.9rem;margin-top:4px">
                    Documentation complete &middot; 25 modules &middot; ~200 endpoints &middot; Tests unitaires, integration et E2E
                </div>
            </div>
            <div style="display:flex;gap:0.75rem;flex-shrink:0;flex-wrap:wrap">
                <a class="btn-volt" href="/demande-etablissement">Creer mon etablissement</a>
                <a class="btn-ghost" href="/pre-inscription">Pre-inscrire un eleve</a>
            </div>
        </div>
    </div>
</section>

<!-- ── TEMOIGNAGES ─────────────────────────────────────────────────────── -->
<section class="section section--fade-up" id="temoignages">
    <div class="section__inner">
        <div class="section__head section__head--center">
            <div>
                <span class="section-tag" style="color:#ffb800">— Temoignages</span>
                <h2 class="section__title">Ils font confiance a <span style="color:#ffb800">SCHOLARIS</span></h2>
            </div>
        </div>

        <div class="quotes">
            <?php foreach ($testimonials as $testimonial) : ?>
                <div class="quote card-lift">
                    <div class="quote__mark">&laquo;</div>
                    <p class="quote__text"><?= $this->e($testimonial['quote']) ?></p>
                    <div class="quote__who">
                        <span class="quote__avatar" style="background:<?= $this->e($testimonial['color']) ?>">
                            <?= $this->e($testimonial['avatar']) ?>
                        </span>
                        <div>
                            <div class="quote__name"><?= $this->e($testimonial['name']) ?></div>
                            <div class="quote__role"><?= $this->e($testimonial['role']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── ETABLISSEMENTS OUVERTS ──────────────────────────────────────────── -->
<?php if ($tenants !== []) : ?>
    <section class="section section--dark">
        <div class="section__inner">
            <div class="section__head">
                <div>
                    <span class="section-tag" style="color:#00c2ff">— Pre-inscription ouverte</span>
                    <h2 class="section__title">Etablissements <span class="gradient-ice">qui recrutent</span></h2>
                </div>
            </div>

            <div class="quotes">
                <?php foreach ($tenants as $tenant) : ?>
                    <div class="quote card-lift">
                        <div class="quote__name" style="font-size:1.05rem"><?= $this->e($tenant['name']) ?></div>
                        <div class="section-tag" style="color:rgba(242,240,232,.3);margin:0.35rem 0 1rem">
                            CODE <?= $this->e($tenant['code']) ?>
                        </div>
                        <a class="btn-volt" href="/pre-inscription">Deposer un dossier</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ── APPEL A L'ACTION ────────────────────────────────────────────────── -->
<section class="section section--dark">
    <div class="cta">
        <div class="cta__box noise">
            <div class="cta__inner">
                <div class="cta__meta">TRU GROUP SARL &middot; Emmanuel &middot; Aout 2026</div>
                <h2 class="cta__title">Deployez SCHOLARIS dans<br>votre etablissement</h2>
                <p class="cta__lead">
                    48h de deploiement. Formation incluse. Support en francais. Hebergement au Cameroun.
                </p>
                <div class="cta__actions">
                    <a class="btn-ink" href="/login">Acceder a la plateforme →</a>
                    <a class="btn-frost" href="/demande-etablissement">Parler a un conseiller</a>
                </div>
            </div>
        </div>
    </div>
</section>
