<?php
/**
 * Module 40 : administration publique.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $decisions, $notes, $employees
 * @var array<string, string> $kinds, $audiences
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Administration';
$value = static fn (string $k): string => (string) ($old[$k] ?? '');
?>
<h1>Actes &amp; notes de service</h1>
<p class="subtitle">
    <?= $this->number(count($decisions)) ?> acte(s) &middot;
    <?= $this->number(count($notes)) ?> note(s) de service
</p>

<div class="card">
    <h2>Actes de carrière</h2>
    <p class="muted">
        Un acte reste modifiable tant qu'il est en projet. Une fois signé, il devient
        définitif : on le rapporte par un autre acte, on ne le réécrit pas.
    </p>
    <?php if ($decisions === []) : ?>
        <p class="muted">Aucun acte enregistré.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Référence</th><th>Agent</th><th>Nature</th><th>Objet</th><th>Décidé le</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($decisions as $decision) : ?>
                <tr>
                    <td class="font-mono">
                        <a href="/administration/actes/<?= $this->e($decision['id']) ?>">
                            <?= $this->e($decision['reference']) ?>
                        </a>
                    </td>
                    <td><?= $this->e($decision['last_name'].' '.$decision['first_name']) ?></td>
                    <td><?= $this->e($kinds[(string) $decision['kind']] ?? $decision['kind']) ?></td>
                    <td><?= $this->e($decision['subject']) ?></td>
                    <td class="font-mono"><?= $this->date($decision['decided_on']) ?></td>
                    <td>
                        <?php if ((string) $decision['status'] === 'SIGNE') : ?>
                            <span class="badge badge--success">Signé</span>
                        <?php else : ?>
                            <span class="badge badge--warning">Projet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((string) $decision['status'] !== 'SIGNE' && $rbac->allows('staff-decisions:sign')) : ?>
                            <form method="post" action="/administration/actes/<?= $this->e($decision['id']) ?>/signer" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Signer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('staff-decisions:create')) : ?>
    <div class="card">
        <form method="post" action="/administration/actes">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h2>Préparer un acte</h2>
            <?php if ($employees === []) : ?>
                <p class="muted">
                    Aucun agent enregistré : créez d'abord le personnel dans
                    <a href="/hr">Dossiers &amp; congés</a>.
                </p>
            <?php else : ?>
                <div class="grid-2">
                    <div class="field">
                        <label for="employee_id">Agent concerné *</label>
                        <select id="employee_id" name="employee_id" required>
                            <?php foreach ($employees as $employee) : ?>
                                <option value="<?= $this->e($employee['id']) ?>">
                                    <?= $this->e($employee['last_name'].' '.$employee['first_name'].' — '.$employee['position']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="kind">Nature de l'acte</label>
                        <select id="kind" name="kind">
                            <?php foreach ($kinds as $key => $name) : ?>
                                <option value="<?= $this->e($key) ?>"><?= $this->e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="decided_on">Date de la décision *</label>
                        <input id="decided_on" name="decided_on" type="date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="field">
                        <label for="effective_on">Date d'effet</label>
                        <input id="effective_on" name="effective_on" type="date">
                    </div>
                    <div class="field">
                        <label for="signed_by">Autorité signataire</label>
                        <input id="signed_by" name="signed_by" placeholder="Le Proviseur, Le Directeur...">
                    </div>
                </div>
                <div class="field">
                    <label for="subject">Objet *</label>
                    <input id="subject" name="subject" required value="<?= $this->e($value('subject')) ?>">
                </div>
                <div class="field">
                    <label for="content">Contenu de l'acte</label>
                    <textarea id="content" name="content" rows="5"></textarea>
                </div>
                <button type="submit" class="button">Enregistrer le projet d'acte</button>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Notes de service</h2>
    <?php if ($notes === []) : ?>
        <p class="muted">Aucune note de service.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Référence</th><th>Titre</th><th>Destinataires</th><th>Publiée le</th><th>État</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($notes as $note) : ?>
                <tr>
                    <td class="font-mono"><?= $this->e($note['reference']) ?></td>
                    <td><?= $this->e($note['title']) ?></td>
                    <td><?= $this->e($audiences[(string) $note['audience']] ?? $note['audience']) ?></td>
                    <td class="font-mono"><?= $this->date($note['published_on']) ?></td>
                    <td>
                        <?php if ((string) $note['status'] === 'PUBLIEE') : ?>
                            <span class="badge badge--success">Publiée</span>
                        <?php else : ?>
                            <span class="badge badge--warning">Projet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((string) $note['status'] !== 'PUBLIEE' && $rbac->allows('staff-decisions:sign')) : ?>
                            <form method="post" action="/administration/notes/<?= $this->e($note['id']) ?>/publier" class="inline-form">
                                <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
                                <button type="submit" class="link-button">Publier</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($rbac->allows('staff-decisions:create')) : ?>
        <form method="post" action="/administration/notes">
            <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
            <h3>Nouvelle note de service</h3>
            <div class="grid-2">
                <div class="field">
                    <label for="note_title">Titre *</label>
                    <input id="note_title" name="title" required>
                </div>
                <div class="field">
                    <label for="audience">Destinataires</label>
                    <select id="audience" name="audience">
                        <?php foreach ($audiences as $key => $name) : ?>
                            <option value="<?= $this->e($key) ?>"><?= $this->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="note_content">Contenu *</label>
                <textarea id="note_content" name="content" rows="5" required></textarea>
            </div>
            <button type="submit" class="button">Enregistrer la note</button>
        </form>
    <?php endif; ?>
</div>
