<?php
/**
 * Verification publique d'un bulletin par son code.
 *
 * Ne montre que ce qui authentifie le document : eleve, etablissement,
 * periode, moyenne. Le detail des notes ne regarde pas le tiers qui verifie.
 *
 * @var \Scholaris\View\View $this
 * @var string $code
 * @var array<string, mixed>|null $bulletin
 * @var bool $searched
 */
$this->extends('layouts.public');
$title = 'Verifier un bulletin';
$data = $bulletin !== null ? json_decode((string) $bulletin['data'], true) : null;
?>
<div class="public">
    <span class="section-tag" style="color:#00c2ff">— Authenticite</span>
    <h1 style="margin-bottom:1rem">Verifier un bulletin</h1>
    <p class="muted" style="margin-bottom:2rem;max-width:600px">
        Saisissez le code imprime sur le bulletin. La verification confirme que
        le document a bien ete emis par l etablissement, sans en reveler le detail.
    </p>

    <form method="get" action="/bulletins/verification" class="filters">
        <input type="text" name="code" placeholder="Code de verification"
               value="<?= $this->e($code) ?>" style="min-width:280px">
        <button type="submit" class="btn-volt">Verifier</button>
    </form>

    <?php if ($searched && $bulletin === null) : ?>
        <div class="alert alert--error">
            Aucun bulletin publie ne correspond a ce code. Verifiez la saisie ;
            un bulletin non encore publie n est pas verifiable.
        </div>
    <?php elseif ($bulletin !== null) : ?>
        <div class="card">
            <div class="alert alert--success">Bulletin authentique.</div>

            <dl class="details">
                <dt>Etablissement</dt><dd><?= $this->e($bulletin['tenant_name']) ?></dd>
                <dt>Eleve</dt>
                <dd><?= $this->e($bulletin['last_name'].' '.$bulletin['first_name']) ?></dd>
                <dt>Matricule</dt><dd><?= $this->e($bulletin['matricule']) ?></dd>
                <dt>Classe</dt><dd><?= $this->e($data['context']['classroom_name'] ?? '-') ?></dd>
                <dt>Periode</dt>
                <dd>
                    Sequence <?= $this->e($data['context']['period_number'] ?? '-') ?>
                    &middot; <?= $this->e($data['context']['year_label'] ?? '') ?>
                </dd>
                <dt>Moyenne generale</dt>
                <dd><strong><?= $this->e(number_format((float) ($data['summary']['general_average'] ?? 0), 2, ',', ' ')) ?>/20</strong></dd>
                <dt>Emis le</dt><dd><?= $this->date($bulletin['created_at'], 'd/m/Y') ?></dd>
            </dl>
        </div>
    <?php endif; ?>
</div>
