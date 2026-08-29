<?php
/**
 * Examens officiels : liste et ouverture.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $exams
 * @var array<int, array<string, mixed>> $levels
 * @var list<string> $codes
 * @var string|null $academicYearId
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Examens officiels';
$now = date('Y-m-d\'H:i:s');
$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1>Examens officiels</h1>
<p class="subtitle">CEP, BEPC, Probatoire, BAC et examens configurables</p>

<?php if ($academicYearId === null) : ?>
    <div class="alert alert--error">
        Aucune année académique activé : impossible d'ouvrir un examen.
    </div>
<?php endif; ?>

<?php if ($exams === []) : ?>
    <div class="card"><p class="muted">Aucun examen ouvert.</p></div>
<?php else : ?>
    <table class="table">
        <thead>
        <tr><th>Examen</th><th>Code</th><th>Inscriptions</th><th>Epreuves</th><th>Candidats</th><th>Frais</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($exams as $exam) : ?>
            <?php $open = $exam['registration_start'] <= $now && $now <= $exam['registration_end']; ?>
            <tr>
                <td><?= $this->e($exam['name']) ?></td>
                <td><span class="badge"><?= $this->e($exam['code']) ?></span></td>
                <td class="font-mono">
                    <?= $this->date($exam['registration_start']) ?> — <?= $this->date($exam['registration_end']) ?>
                    <?php if ($open) : ?>
                        <span class="badge badge--success">ouvertes</span>
                    <?php else : ?>
                        <span class="muted">fermees</span>
                    <?php endif; ?>
                </td>
                <td class="font-mono"><?= $this->date($exam['exam_start']) ?></td>
                <td class="font-mono"><?= $this->number($exam['registrations']) ?></td>
                <td><?= $this->money($exam['fee_amount']) ?></td>
                <td><a href="/exams/<?= $this->e($exam['id']) ?>">Ouvrir</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($rbac->allows('exams:create') && $academicYearId !== null) : ?>
    <form method="post" action="/exams" class="card" style="margin-top:1.5rem">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Ouvrir un examen</h2>

        <div class="grid-2">
            <div class="field">
                <label for="name">Intitule *</label>
                <input id="name" name="name" required placeholder="BAC serie C — session 2027"
                       value="<?= $this->e($value('name')) ?>">
            </div>
            <div class="field">
                <label for="code">Examen *</label>
                <select id="code" name="code" required>
                    <?php foreach ($codes as $code) : ?>
                        <option value="<?= $this->e($code) ?>"><?= $this->e($code) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="level_id">Niveau concerne</label>
                <select id="level_id" name="level_id">
                    <option value="">Tous niveaux</option>
                    <?php foreach ($levels as $level) : ?>
                        <option value="<?= $this->e($level['id']) ?>"><?= $this->e($level['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="fee_amount">Frais d'inscription (FCFA)</label>
                <input id="fee_amount" name="fee_amount" inputmode="numeric" value="0">
            </div>
            <div class="field">
                <label for="registration_start">Ouverture des inscriptions *</label>
                <input id="registration_start" name="registration_start" type="date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="field">
                <label for="registration_end">Cloture des inscriptions *</label>
                <input id="registration_end" name="registration_end" type="date" required
                       value="<?= date('Y-m-d', strtotime('+60 days')) ?>">
            </div>
            <div class="field">
                <label for="exam_start">Debut des epreuves</label>
                <input id="exam_start" name="exam_start" type="date">
            </div>
        </div>

        <button type="submit" class="button">Ouvrir aux inscriptions</button>
    </form>
<?php endif; ?>
