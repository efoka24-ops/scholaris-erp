<?php
/**
 * Module 22 : comptabilite generale.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $balance, $entries, $accounts, $budget
 * @var array{charges: float, produits: float, solde: float} $result
 * @var list<string> $journals
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */

use Scholaris\Service\ChartOfAccounts;

$this->extends('layouts.app');
$title = 'Comptabilité';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');

$totalDebit = 0.0;
$totalCredit = 0.0;

foreach ($balance as $line) {
    $totalDebit += (float) $line['debit'];
    $totalCredit += (float) $line['credit'];
}
?>
<h1>Comptabilité</h1>
<p class="subtitle">
    <?= $this->number(count($accounts)) ?> compte(s) au plan &middot;
    <?= $this->number(count($entries)) ?> écriture(s) récente(s)
</p>

<?php if ($accounts === []) : ?>
    <div class="card">
        <h2>Le plan comptable est vide</h2>
        <p class="muted">
            Rien ne peut être saisi tant qu'aucun compte n'existe. Vous pouvez installer
            le plan SYSCOHADA adapté aux établissements scolaires — une quarantaine de
            comptes courants — puis le compléter librement.
        </p>
        <?php if ($rbac->allows('accounting:create')) : ?>
            <form method="post" action="/comptabilite/plan">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                <button type="submit" class="button">Installer le plan SYSCOHADA</button>
            </form>
        <?php endif; ?>
    </div>
<?php else : ?>

    <div class="stats">
        <div class="stat">
            <div class="stat__label">Produits</div>
            <div class="stat__value stat__value--money"><?= $this->money($result['produits']) ?></div>
        </div>
        <div class="stat">
            <div class="stat__label">Charges</div>
            <div class="stat__value stat__value--money"><?= $this->money($result['charges']) ?></div>
        </div>
        <div class="stat <?= $result['solde'] >= 0 ? 'stat--green' : 'stat--pink' ?>">
            <div class="stat__label">Résultat</div>
            <div class="stat__value stat__value--money"><?= $this->money($result['solde']) ?></div>
        </div>
    </div>

    <div class="card">
        <h2>Balance générale</h2>
        <p class="muted">Seules les écritures validées entrent dans la balance.</p>
        <table class="table">
            <thead>
            <tr><th>Compte</th><th>Intitulé</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr>
            </thead>
            <tbody>
            <?php foreach ($balance as $line) :
                $solde = (float) $line['debit'] - (float) $line['credit']; ?>
                <tr>
                    <td class="font-mono"><?= $this->e($line['code']) ?></td>
                    <td>
                        <?= $this->e($line['name']) ?>
                        <span class="badge"><?= $this->e(ChartOfAccounts::className((int) $line['account_class'])) ?></span>
                    </td>
                    <td class="font-mono"><?= $this->money($line['debit']) ?></td>
                    <td class="font-mono"><?= $this->money($line['credit']) ?></td>
                    <td class="font-mono"><?= $this->money($solde) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th class="font-mono"><?= $this->money($totalDebit) ?></th>
                <th class="font-mono"><?= $this->money($totalCredit) ?></th>
                <th></th>
            </tr>
            </tfoot>
        </table>
    </div>

    <div class="card">
        <h2>Journal des écritures</h2>
        <?php if ($entries === []) : ?>
            <p class="muted">Aucune écriture enregistrée.</p>
        <?php else : ?>
            <table class="table">
                <thead>
                <tr><th>Référence</th><th>Date</th><th>Libellé</th><th>Montant</th><th>État</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td class="font-mono"><?= $this->e($entry['reference']) ?></td>
                        <td class="font-mono"><?= $this->date($entry['entry_date']) ?></td>
                        <td>
                            <a href="/comptabilite/ecritures/<?= $this->e($entry['id']) ?>"><?= $this->e($entry['label']) ?></a>
                            <span class="badge"><?= $this->e(ChartOfAccounts::journalName((string) $entry['journal'])) ?></span>
                        </td>
                        <td class="font-mono"><?= $this->money($entry['total']) ?></td>
                        <td>
                            <?php if ((int) $entry['posted'] === 1) : ?>
                                <span class="badge badge--success">Validée</span>
                            <?php else : ?>
                                <span class="badge badge--warning">Brouillard</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rbac->allows('accounting:post')) : ?>
                                <?php if ((int) $entry['posted'] === 0) : ?>
                                    <form method="post" action="/comptabilite/ecritures/<?= $this->e($entry['id']) ?>/valider" class="inline-form">
                                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                        <button type="submit" class="link-button">Valider</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" action="/comptabilite/ecritures/<?= $this->e($entry['id']) ?>/contrepasser" class="inline-form">
                                        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                        <button type="submit" class="link-button">Contre-passer</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($rbac->allows('accounting:create')) : ?>
        <div class="card">
            <form method="post" action="/comptabilite/ecritures">
                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                <h2>Saisir une écriture</h2>
                <p class="muted">
                    L'écriture doit être équilibrée : le total des débits égale celui des
                    crédits. Elle reste modifiable tant qu'elle n'est pas validée.
                </p>

                <div class="grid-2">
                    <div class="field">
                        <label for="entry_date">Date *</label>
                        <input id="entry_date" name="entry_date" type="date" required
                               value="<?= $this->e($value('entry_date') ?: date('Y-m-d')) ?>">
                    </div>
                    <div class="field">
                        <label for="journal">Journal</label>
                        <select id="journal" name="journal">
                            <?php foreach ($journals as $journal) : ?>
                                <option value="<?= $this->e($journal) ?>" <?= $value('journal') === $journal ? 'selected' : '' ?>>
                                    <?= $this->e(ChartOfAccounts::journalName($journal)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label for="label">Libellé *</label>
                    <input id="label" name="label" required value="<?= $this->e($value('label')) ?>">
                </div>

                <table class="table">
                    <thead>
                    <tr><th>Compte</th><th>Libellé de la ligne</th><th>Débit</th><th>Crédit</th></tr>
                    </thead>
                    <tbody>
                    <?php for ($i = 0; $i < 4; $i++) : ?>
                        <tr>
                            <td>
                                <select name="lines[<?= $i ?>][account_id]" aria-label="Compte de la ligne <?= $i + 1 ?>">
                                    <option value="">&mdash;</option>
                                    <?php foreach ($accounts as $account) : ?>
                                        <option value="<?= $this->e($account['id']) ?>">
                                            <?= $this->e($account['code'].' — '.$account['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input name="lines[<?= $i ?>][label]" aria-label="Libellé de la ligne <?= $i + 1 ?>"></td>
                            <td><input name="lines[<?= $i ?>][debit]" inputmode="decimal" aria-label="Débit de la ligne <?= $i + 1 ?>"></td>
                            <td><input name="lines[<?= $i ?>][credit]" inputmode="decimal" aria-label="Crédit de la ligne <?= $i + 1 ?>"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>

                <button type="submit" class="button">Enregistrer au brouillard</button>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<div class="card">
    <h2>Budget prévisionnel</h2>
    <?php if ($budget === []) : ?>
        <p class="muted">Aucune ligne budgétaire pour l'année en cours.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Poste</th><th>Sens</th><th>Prévu</th><th>Réalisé</th><th>Écart</th></tr>
            </thead>
            <tbody>
            <?php foreach ($budget as $line) :
                $planned = (float) $line['planned_amount'];
                $actual = (float) $line['actual_amount'];
                // Pour une charge, dépasser le prévu est défavorable ; pour un
                // produit c'est l'inverse. L'écart est donc signé selon le sens.
                $gap = (string) $line['direction'] === 'CHARGE' ? $planned - $actual : $actual - $planned; ?>
                <tr>
                    <td>
                        <?= $this->e($line['label']) ?>
                        <?php if ($line['code'] !== null) : ?>
                            <span class="badge font-mono"><?= $this->e($line['code']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= (string) $line['direction'] === 'CHARGE' ? 'Charge' : 'Produit' ?></td>
                    <td class="font-mono"><?= $this->money($planned) ?></td>
                    <td class="font-mono"><?= $this->money($actual) ?></td>
                    <td class="font-mono">
                        <?= $this->money($gap) ?>
                        <?php if ($gap < 0) : ?>
                            <span class="badge badge--warning">dépassement</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($rbac->allows('budget:create') && $accounts !== []) : ?>
        <form method="post" action="/comptabilite/budget">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <div class="grid-2">
                <div class="field">
                    <label for="budget_label">Poste budgétaire *</label>
                    <input id="budget_label" name="label" required>
                </div>
                <div class="field">
                    <label for="direction">Sens</label>
                    <select id="direction" name="direction">
                        <option value="CHARGE">Charge</option>
                        <option value="PRODUIT">Produit</option>
                    </select>
                </div>
                <div class="field">
                    <label for="budget_account">Compte rattaché</label>
                    <select id="budget_account" name="account_id">
                        <option value="">Aucun</option>
                        <?php foreach ($accounts as $account) : ?>
                            <option value="<?= $this->e($account['id']) ?>">
                                <?= $this->e($account['code'].' — '.$account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="planned_amount">Montant prévu (FCFA)</label>
                    <input id="planned_amount" name="planned_amount" inputmode="decimal" value="0">
                </div>
            </div>
            <button type="submit" class="button">Ajouter la ligne</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('accounting:create') && $accounts !== []) : ?>
    <div class="card">
        <form method="post" action="/comptabilite/comptes">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Ajouter un compte</h2>
            <div class="grid-2">
                <div class="field">
                    <label for="code">Numéro *</label>
                    <input id="code" name="code" required>
                </div>
                <div class="field">
                    <label for="name">Intitulé *</label>
                    <input id="name" name="name" required>
                </div>
                <div class="field">
                    <label for="account_class">Classe</label>
                    <select id="account_class" name="account_class">
                        <?php for ($c = 1; $c <= 8; $c++) : ?>
                            <option value="<?= $c ?>"><?= $c ?> — <?= $this->e(ChartOfAccounts::className($c)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="nature">Nature</label>
                    <select id="nature" name="nature">
                        <option value="BILAN">Bilan</option>
                        <option value="GESTION">Gestion</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="button">Ajouter le compte</button>
        </form>
    </div>
<?php endif; ?>
