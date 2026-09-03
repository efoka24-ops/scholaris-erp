<?php
/**
 * Module 43 : objectifs et performances.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $objectives, $owners
 * @var array<string, int> $summary
 * @var array<string, string> $scopes
 * @var list<string> $statuses
 * @var float $averageProgress
 * @var array<string, float> $suggestions
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */

use Scholaris\Controller\ObjectiveController;

$this->extends('layouts.app');
$title = 'Objectifs';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');

$label = static function (string $status): string {
    return match ($status) {
        'ATTEINT' => 'Atteint',
        'MANQUE' => 'Manqué',
        'ABANDONNE' => 'Abandonné',
        default => 'En cours',
    };
};

$badge = static function (string $status): string {
    return match ($status) {
        'ATTEINT' => 'badge badge--success',
        'MANQUE' => 'badge badge--danger',
        'ABANDONNE' => 'badge badge--neutral',
        default => 'badge badge--warning',
    };
};
?>
<h1>Objectifs &amp; performances</h1>
<p class="subtitle"><?= $this->number(count($objectives)) ?> objectif(s) pour l'année en cours</p>

<div class="stats">
    <div class="stat">
        <div class="stat__label">En cours</div>
        <div class="stat__value"><?= $this->number($summary['EN_COURS'] ?? 0) ?></div>
    </div>
    <div class="stat stat--green">
        <div class="stat__label">Atteints</div>
        <div class="stat__value"><?= $this->number($summary['ATTEINT'] ?? 0) ?></div>
    </div>
    <div class="stat stat--pink">
        <div class="stat__label">Manqués</div>
        <div class="stat__value"><?= $this->number($summary['MANQUE'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Avancement moyen</div>
        <div class="stat__value"><?= $this->number($averageProgress) ?> %</div>
    </div>
</div>

<?php if ($suggestions !== []) : ?>
    <div class="card">
        <h2>Indicateurs déjà mesurés</h2>
        <p class="muted">
            Relevés automatiquement à partir des données de l'établissement. Reportez-les
            comme valeur atteinte si vous suivez ces indicateurs.
        </p>
        <table class="table">
            <tbody>
            <?php foreach ($suggestions as $name => $measure) : ?>
                <tr>
                    <th><?= $this->e($name) ?></th>
                    <td class="font-mono"><?= $this->number($measure) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Objectifs suivis</h2>
    <?php if ($objectives === []) : ?>
        <p class="muted">Aucun objectif fixé.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Objectif</th><th>Périmètre</th><th>Cible</th><th>Atteint</th><th>Avancement</th><th>Échéance</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($objectives as $objective) :
                $progress = ObjectiveController::progress($objective); ?>
                <tr>
                    <td>
                        <?= $this->e($objective['label']) ?>
                        <?php if ($objective['indicator'] !== null) : ?>
                            <div class="muted"><?= $this->e($objective['indicator']) ?></div>
                        <?php endif; ?>
                        <?php if ($objective['last_name'] !== null) : ?>
                            <div class="muted">
                                Responsable : <?= $this->e($objective['last_name'].' '.$objective['first_name']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?= $this->e($scopes[(string) $objective['scope']] ?? $objective['scope']) ?></td>
                    <td class="font-mono">
                        <?= $this->number($objective['target_value']) ?> <?= $this->e($objective['unit']) ?>
                    </td>
                    <td class="font-mono">
                        <?= $this->number($objective['current_value']) ?> <?= $this->e($objective['unit']) ?>
                    </td>
                    <td class="font-mono"><?= $this->number($progress) ?> %</td>
                    <td class="font-mono">
                        <?= $objective['due_on'] === null ? '—' : $this->date($objective['due_on']) ?>
                    </td>
                    <td>
                        <span class="<?= $this->e($badge((string) $objective['status'])) ?>">
                            <?= $this->e($label((string) $objective['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($rbac->allows('objectives:update')) : ?>
                            <form method="post" action="/objectifs/<?= $this->e($objective['id']) ?>/releve" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <input name="current_value" inputmode="decimal" size="6"
                                       value="<?= $this->e($objective['current_value']) ?>"
                                       aria-label="Valeur atteinte">
                                <button type="submit" class="link-button">Relever</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('objectives:create')) : ?>
    <div class="card">
        <form method="post" action="/objectifs">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Fixer un objectif</h2>
            <p class="muted">
                L'objectif bascule seul sur « atteint » dès que la valeur relevée franchit
                la cible, et redevient « en cours » si elle repasse dessous.
            </p>
            <div class="grid-2">
                <div class="field">
                    <label for="label">Intitulé *</label>
                    <input id="label" name="label" required value="<?= $this->e($value('label')) ?>">
                </div>
                <div class="field">
                    <label for="scope">Périmètre</label>
                    <select id="scope" name="scope">
                        <?php foreach ($scopes as $key => $name) : ?>
                            <option value="<?= $this->e($key) ?>"><?= $this->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="indicator">Indicateur mesuré</label>
                    <input id="indicator" name="indicator" placeholder="Taux de réussite au BEPC, effectif...">
                </div>
                <div class="field">
                    <label for="unit">Unité *</label>
                    <input id="unit" name="unit" required value="<?= $this->e($value('unit') ?: '%') ?>">
                </div>
                <div class="field">
                    <label for="target_value">Valeur cible *</label>
                    <input id="target_value" name="target_value" inputmode="decimal" required>
                </div>
                <div class="field">
                    <label for="current_value">Valeur actuelle</label>
                    <input id="current_value" name="current_value" inputmode="decimal" value="0">
                </div>
                <div class="field">
                    <label for="due_on">Échéance</label>
                    <input id="due_on" name="due_on" type="date">
                </div>
                <div class="field">
                    <label for="owner_id">Responsable</label>
                    <select id="owner_id" name="owner_id">
                        <option value="">Aucun</option>
                        <?php foreach ($owners as $owner) : ?>
                            <option value="<?= $this->e($owner['id']) ?>">
                                <?= $this->e($owner['last_name'].' '.$owner['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="button">Enregistrer l'objectif</button>
        </form>
    </div>
<?php endif; ?>
