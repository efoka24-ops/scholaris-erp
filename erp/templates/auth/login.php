<?php
/**
 * Connexion — double panneau, repris de LoginPage de la maquette.
 *
 * Le panneau gauche porte la marque et les formes animees, le droit le
 * formulaire. Sur petit ecran le panneau gauche disparait et un logo compact
 * le remplace, comme dans la maquette.
 *
 * @var \Scholaris\View\View $this
 * @var string|null $error
 * @var array<string, mixed> $old
 * @var string $csrfToken
 */
$title = 'Connexion';

$roles = [
    ['Admin', '#5b21f5'],
    ['Censeur', '#ff3d8a'],
    ['Enseignant', '#ffb800'],
    ['Parent', '#00e5a0'],
    ['Secretaire', '#00c2ff'],
    ['Intendant', '#ff4d4d'],
];

// Comptes du lycee de demonstration : ils remplissent le formulaire d'un clic.
$demoAccounts = [
    ['Proviseur', 'proviseur@lbg.cm', '#5b21f5'],
    ['Enseignant', 'a.tchoumi@lbg.cm', '#00c2ff'],
    ['Secretaire', 'secretaire@lbg.cm', '#ffb800'],
    ['Parent', 'parent@lbg.cm', '#00e5a0'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — SCHOLARIS</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="login noise">
    <div class="decor">
        <div class="glow" style="top:15%;left:30%;width:500px;height:500px;background:radial-gradient(circle,rgba(91,33,245,.18) 0%,transparent 70%)"></div>
        <div class="glow" style="bottom:10%;right:20%;width:350px;height:350px;background:radial-gradient(circle,rgba(200,255,0,.08) 0%,transparent 70%)"></div>
        <div class="glow" style="top:55%;left:10%;width:250px;height:250px;background:radial-gradient(circle,rgba(255,61,138,.1) 0%,transparent 70%)"></div>

        <div class="shape tri anim-float-a" style="top:8%;left:6%;width:56px;height:56px;background:#5b21f5;opacity:.45"></div>
        <div class="shape dia anim-float-b" style="top:18%;right:8%;width:44px;height:44px;background:#c8ff00;opacity:.5"></div>
        <div class="shape anim-float-c" style="bottom:22%;left:12%;width:38px;height:38px;background:#ff3d8a;opacity:.4;border-radius:50%"></div>
        <div class="shape anim-float-a" style="bottom:10%;right:12%;width:50px;height:50px;background:#00c2ff;opacity:.35;border-radius:8px"></div>
        <div class="shape tri anim-float-b" style="top:45%;right:5%;width:34px;height:34px;background:#ffb800;opacity:.45"></div>

        <div class="ring anim-spin-cw" style="top:20%;left:42%;width:220px;height:220px;border:1px solid rgba(200,255,0,.12)"></div>
        <div class="ring anim-spin-ccw" style="top:12%;left:36%;width:320px;height:320px;border:1px solid rgba(91,33,245,.1)"></div>
        <div class="ring anim-spin-cw" style="bottom:5%;left:22%;width:180px;height:180px;border:1px solid rgba(255,61,138,.1)"></div>

        <svg style="position:absolute;inset:0;width:100%;height:100%;opacity:.04" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="dots" x="0" y="0" width="32" height="32" patternUnits="userSpaceOnUse">
                    <circle cx="1" cy="1" r="1" fill="#ffffff"/>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#dots)"/>
        </svg>
    </div>

    <!-- Panneau gauche : marque -->
    <div class="login__brandside">
        <div>
            <a class="brand" href="/" style="margin-bottom:5rem">
                <span class="brand__mark" style="width:44px;height:44px;font-size:1.05rem">S</span>
                <span>
                    <span class="brand__name" style="display:block;font-size:1.25rem">SCHOLARIS<span>.</span></span>
                    <span class="section-tag" style="color:rgba(242,240,232,.35)">ERP v2.0</span>
                </span>
            </a>

            <div class="anim-slide-l">
                <h1 class="login__title">
                    Gerez votre<br>
                    <span class="gradient-volt">etablissement</span><br>
                    intelligemment.
                </h1>
                <p class="login__lead">
                    25 modules integres — des inscriptions aux bulletins, de la paie CNPS
                    aux paiements Orange Money. Un seul systeme concu pour le Cameroun.
                </p>
            </div>

            <div class="login__roles">
                <?php foreach ($roles as [$role, $colour]) : ?>
                    <span class="pill" style="background:<?= $this->e($colour) ?>18;color:<?= $this->e($colour) ?>;border:1px solid <?= $this->e($colour) ?>30">
                        <?= $this->e($role) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section-tag" style="color:rgba(242,240,232,.25)">
            TRU GROUP SARL &middot; Yaounde, Cameroun
        </div>
    </div>

    <!-- Panneau droit : formulaire -->
    <div class="login__formside">
        <a class="brand login__mobile-brand" href="/">
            <span class="brand__mark">S</span>
            <span class="brand__name">SCHOLARIS<span>.</span></span>
        </a>

        <div class="login__form-wrap">
            <div class="anim-slide-up" style="margin-bottom:2.5rem">
                <h2 class="login__heading">Connexion</h2>
                <p class="login__sub">Accedez a votre espace SCHOLARIS ERP.</p>
            </div>

            <?php if ($error !== null) : ?>
                <div class="alert alert--error" role="alert"><?= $this->e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/login">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">

                <div class="field">
                    <label for="email">Adresse email</label>
                    <input class="inp" id="email" name="email" type="email" required autofocus
                           autocomplete="username" placeholder="proviseur@lbg.cm"
                           value="<?= $this->e($old['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="password">Mot de passe</label>
                    <input class="inp" id="password" name="password" type="password" required
                           autocomplete="current-password" placeholder="••••••••••••">
                </div>

                <div class="field">
                    <label for="tenant_code">Code etablissement (si demande)</label>
                    <input class="inp" id="tenant_code" name="tenant_code" type="text"
                           autocomplete="organization" placeholder="LBG"
                           value="<?= $this->e($old['tenant_code'] ?? '') ?>">
                </div>

                <button type="submit" class="btn-volt button--block">Se connecter →</button>
            </form>

            <div class="login__demo">
                <span class="section-tag" style="color:#5b21f5;margin-bottom:0.75rem">ACCES DEMO RAPIDE</span>
                <div class="login__demo-grid">
                    <?php foreach ($demoAccounts as [$role, $email, $colour]) : ?>
                        <button type="button" class="login__demo-item"
                                onclick="document.getElementById('email').value='<?= $this->e($email) ?>';document.getElementById('password').value='Test123!';document.getElementById('password').focus()">
                            <span class="login__demo-r" style="color:<?= $this->e($colour) ?>"><?= $this->e($role) ?></span>
                            <span class="login__demo-e" style="display:block"><?= $this->e($email) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="margin-top:1.5rem;font-size:.85rem;color:rgba(242,240,232,.4)">
                Pas encore de compte ?
                <a href="/demande-etablissement">Ouvrir un etablissement</a>
                &middot;
                <a href="/pre-inscription">Pre-inscrire un eleve</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
