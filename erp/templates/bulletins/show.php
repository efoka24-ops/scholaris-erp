<?php
/**
 * Bulletin pret a imprimer.
 *
 * Toutes les valeurs viennent du snapshot fige a l'emission, jamais d'un
 * recalcul : c'est ce qui garantit qu'un bulletin reimprime est identique a
 * celui remis a la famille.
 *
 * @var \Scholaris\View\View $this
 * @var array<string, mixed> $bulletin
 * @var array<string, mixed> $data
 */
$this->extends('layouts.app');
$title = 'Bulletin';

$student = $data['student'] ?? [];
$context = $data['context'] ?? [];
$subjects = $data['subjects'] ?? [];
$summary = $data['summary'] ?? [];
?>
<div class="page-header">
    <div>
        <h1>Bulletin de notes</h1>
        <p class="subtitle">
            <?= $this->e(($student['last_name'] ?? '').' '.($student['first_name'] ?? '')) ?>
            &middot; <span class="badge"><?= $this->e($bulletin['status']) ?></span>
        </p>
    </div>
    <div class="form-actions" style="margin:0">
        <button type="button" class="button" onclick="window.print()">Imprimer / PDF</button>
        <a class="button--secondary" href="/bulletins">Retour</a>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>
            <div class="font-display" style="font-weight:800;font-size:1.05rem">
                <?= $this->e($context['tenant_name'] ?? '') ?>
            </div>
            <div class="font-mono" style="font-size:.72rem;color:rgba(242,240,232,.4);margin-top:.25rem">
                <?= $this->e($context['tenant_address'] ?? '') ?>
                <?php if (! empty($context['tenant_phone'])) : ?>
                    &middot; <?= $this->e($context['tenant_phone']) ?>
                <?php endif; ?>
            </div>
            <div class="font-mono" style="font-size:.72rem;color:rgba(242,240,232,.4);margin-top:.35rem">
                BULLETIN — SEQUENCE <?= $this->e($context['period_number'] ?? '') ?>
                &middot; <?= $this->e($context['year_label'] ?? '') ?>
            </div>
        </div>

        <div style="text-align:right">
            <span class="section-tag" style="color:rgba(242,240,232,.35)">CODE DE VERIFICATION</span>
            <div class="font-mono" style="font-weight:700;color:#c8ff00;font-size:1.05rem">
                <?= $this->e($bulletin['verification_code']) ?>
            </div>
            <div class="font-mono" style="font-size:.65rem;color:rgba(242,240,232,.3);margin-top:.25rem">
                a verifier sur /bulletins/verification
            </div>
        </div>
    </div>

    <div style="height:2px;margin:1.25rem 0;background:linear-gradient(90deg,#5b21f5,#ff3d8a,#ffb800)"></div>

    <dl class="details">
        <dt>Eleve</dt>
        <dd><strong><?= $this->e(($student['last_name'] ?? '').' '.($student['first_name'] ?? '')) ?></strong></dd>
        <dt>Matricule</dt><dd><?= $this->e($student['matricule'] ?? '-') ?></dd>
        <dt>Ne(e) le</dt><dd><?= $this->date($student['date_of_birth'] ?? null) ?></dd>
        <dt>Classe</dt>
        <dd>
            <?= $this->e($context['classroom_name'] ?? '') ?>
            (<?= $this->e($context['level_name'] ?? '') ?>, <?= $this->e($context['section'] ?? '') ?>)
        </dd>
    </dl>
</div>

<div class="card">
    <h2>Resultats par matiere</h2>

    <?php if ($subjects === []) : ?>
        <p class="muted">Aucune moyenne par matiere enregistree.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Matiere</th><th>Moyenne</th><th>Coef.</th><th>Total pondere</th><th>Rang</th></tr>
            </thead>
            <tbody>
            <?php foreach ($subjects as $subject) : ?>
                <?php $average = (float) $subject['calculated_average']; ?>
                <tr>
                    <td><?= $this->e($subject['subject_name']) ?></td>
                    <td class="font-mono" style="font-weight:700;color:<?= $average >= 10 ? '#00e5a0' : '#ff4d4d' ?>">
                        <?= $this->e(number_format($average, 2, ',', ' ')) ?>
                    </td>
                    <td class="font-mono"><?= $this->e(rtrim(rtrim((string) $subject['coefficient'], '0'), '.')) ?></td>
                    <td class="font-mono"><?= $this->e(number_format((float) $subject['weighted_total'], 2, ',', ' ')) ?></td>
                    <td class="font-mono"><?= $subject['rank_position'] !== null ? $this->e($subject['rank_position']) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Moyenne generale</div>
        <div class="stat__value"><?= $this->e(number_format((float) ($summary['general_average'] ?? 0), 2, ',', ' ')) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Rang</div>
        <div class="stat__value">
            <?= $this->e($summary['rank'] ?? '-') ?>
            <?php if (! empty($summary['total_students'])) : ?>
                <span style="font-size:1rem;color:rgba(242,240,232,.4)">/ <?= $this->e($summary['total_students']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat">
        <div class="stat__label">Mention</div>
        <div class="stat__value" style="font-size:1.25rem"><?= $this->e($summary['mention'] ?? '-') ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Decision</div>
        <div class="stat__value" style="font-size:1.25rem"><?= $this->e($summary['decision'] ?? '-') ?></div>
    </div>
</div>

<?php if (! empty($summary['teacher_comment'])) : ?>
    <div class="card">
        <h2>Appreciation</h2>
        <p><?= $this->e($summary['teacher_comment']) ?></p>
    </div>
<?php endif; ?>

<p class="muted" style="font-size:.85rem">
    Bulletin emis le <?= $this->date($data['generated_at'] ?? null, 'd/m/Y a H:i') ?>.
    Son authenticite se verifie avec le code <?= $this->e($bulletin['verification_code']) ?>.
</p>
