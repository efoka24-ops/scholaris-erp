<?php
/**
 * Module 42 : paie.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $periods, $payslips, $employees
 * @var array<string, mixed>|null $current
 * @var array{gross: float, net: float, cnps: float, tax: float} $totals
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Paie';
$open = $current !== null && (string) $current['status'] === 'OUVERTE';
?>
<h1>Paie &amp; bulletins</h1>
<p class="subtitle"><?= $this->number(count($periods)) ?> période(s) de paie</p>

<?php if ($periods === []) : ?>
    <div class="card">
        <h2>Aucune période de paie</h2>
        <p class="muted">
            Ouvrez une période mensuelle, puis générez les bulletins des agents actifs.
            Le calcul applique les taux CNPS et le barème IRPP en vigueur.
        </p>
    </div>
<?php else : ?>

    <form method="get" action="/paie" class="inline-form">
        <select name="periode" aria-label="Période de paie">
            <?php foreach ($periods as $period) : ?>
                <option value="<?= $this->e($period['id']) ?>"
                    <?= $current !== null && $period['id'] === $current['id'] ? 'selected' : '' ?>>
                    <?= $this->e($period['label']) ?>
                    <?= (string) $period['status'] === 'CLOSE' ? '(close)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button--secondary">Afficher</button>
    </form>

<?php endif; ?>

<?php if ($current !== null) : ?>
    <div class="stats">
        <div class="stat">
            <div class="stat__label">Bulletins</div>
            <div class="stat__value"><?= $this->number(count($payslips)) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">Masse salariale brute</div>
            <div class="stat__value stat__value--money"><?= $this->money($totals['gross']) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">Net à payer</div>
            <div class="stat__value stat__value--money"><?= $this->money($totals['net']) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">CNPS (part salariale et patronale)</div>
            <div class="stat__value stat__value--money"><?= $this->money($totals['cnps']) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">IRPP retenu</div>
            <div class="stat__value stat__value--money"><?= $this->money($totals['tax']) ?></div>
        </div>
    </div>

    <div class="card">
        <h2>
            <?= $this->e($current['label']) ?>
            <?php if ($open) : ?>
                <span class="badge badge--warning">Ouverte</span>
            <?php else : ?>
                <span class="badge badge--neutral">Close</span>
            <?php endif; ?>
        </h2>

        <?php if ($payslips === []) : ?>
            <p class="muted">Aucun bulletin sur cette période.</p>
        <?php else : ?>
            <table class="table">
                <thead>
                <tr><th>Agent</th><th>Fonction</th><th>Brut</th><th>CNPS</th><th>IRPP</th><th>Net à payer</th><th>État</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($payslips as $slip) : ?>
                    <tr>
                        <td>
                            <a href="/paie/bulletins/<?= $this->e($slip['id']) ?>">
                                <?= $this->e($slip['last_name'].' '.$slip['first_name']) ?>
                            </a>
                        </td>
                        <td><?= $this->e($slip['position']) ?></td>
                        <td class="font-mono"><?= $this->money($slip['gross_amount']) ?></td>
                        <td class="font-mono"><?= $this->money($slip['cnps_employee']) ?></td>
                        <td class="font-mono"><?= $this->money($slip['income_tax']) ?></td>
                        <td class="font-mono"><?= $this->money($slip['net_amount']) ?></td>
                        <td>
                            <?php if ((string) $slip['status'] === 'VALIDE') : ?>
                                <span class="badge badge--success">Validé</span>
                            <?php else : ?>
                                <span class="badge badge--warning">Brouillon</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($open && (string) $slip['status'] !== 'VALIDE' && $rbac->allows('payroll:close')) : ?>
                                <form method="post" action="/paie/bulletins/<?= $this->e($slip['id']) ?>/valider" class="inline-form">
                                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                    <button type="submit" class="link-button">Valider</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($open) : ?>
            <?php if ($rbac->allows('payroll:create')) : ?>
                <form method="post" action="/paie/periodes/<?= $this->e($current['id']) ?>/generer" class="inline-form">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="button">Générer les bulletins manquants</button>
                </form>
            <?php endif; ?>
            <?php if ($rbac->allows('payroll:close')) : ?>
                <form method="post" action="/paie/periodes/<?= $this->e($current['id']) ?>/clore" class="inline-form">
                    <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                    <button type="submit" class="button button--secondary">Clore la période</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($rbac->allows('payroll:create')) : ?>
    <div class="card">
        <form method="post" action="/paie/periodes">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Ouvrir une période</h2>
            <div class="grid-2">
                <div class="field">
                    <label for="month">Mois</label>
                    <select id="month" name="month">
                        <?php
                        $months = [
                            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
                            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
                        ];
                        foreach ($months as $number => $name) : ?>
                            <option value="<?= $number ?>" <?= (int) date('n') === $number ? 'selected' : '' ?>>
                                <?= $this->e($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="year">Année</label>
                    <input id="year" name="year" inputmode="numeric" value="<?= date('Y') ?>">
                </div>
            </div>
            <p class="muted">
                <?= $this->number(count($employees)) ?> agent(s) actif(s) seront concernés.
                Ceux dont le salaire de base n'est pas renseigné seront ignorés.
            </p>
            <button type="submit" class="button">Ouvrir la période</button>
        </form>
    </div>
<?php endif; ?>
