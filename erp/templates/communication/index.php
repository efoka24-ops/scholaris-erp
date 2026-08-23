<?php
/**
 * Module 8 : communication multicanal.
 *
 * @var \Scholaris\View\View $this
 * @var array<int, array<string, mixed>> $templates
 * @var array<int, array<string, mixed>> $messages
 * @var array<int, array<string, mixed>> $recipients
 * @var int $pending
 * @var list<string> $channels
 * @var array<string, mixed> $old
 * @var \Scholaris\Auth\Rbac $rbac
 * @var string $csrfToken
 */
$this->extends('layouts.app');
$title = 'Communication';

$channelLabels = [
    'EMAIL' => 'Email',
    'SMS' => 'SMS',
    'WHATSAPP' => 'WhatsApp',
    'PUSH' => 'Notification push',
    'INTERNAL' => 'Message interne',
];

$value = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<div class="page-header">
    <div>
        <h1>Communication</h1>
        <p class="subtitle">
            <?= $this->number(count($templates)) ?> modele(s) &middot;
            <?= $this->number($pending) ?> message(s) en file
        </p>
    </div>
    <a class="button--secondary" href="/messages">Ma messagerie</a>
</div>

<div class="alert">
    Aucun prestataire d envoi n est configure : les messages externes restent en
    file, avec le statut « en attente ». Une file visible vaut mieux qu un envoi
    silencieusement perdu.
</div>

<?php if ($rbac->allows('communications:create')) : ?>
    <form method="post" action="/communication/send" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Envoyer un message</h2>

        <div class="grid-2">
            <div class="field">
                <label for="recipient_user_id">Destinataire *</label>
                <select id="recipient_user_id" name="recipient_user_id" required>
                    <option value="">Choisir...</option>
                    <?php foreach ($recipients as $recipient) : ?>
                        <option value="<?= $this->e($recipient['id']) ?>">
                            <?= $this->e($recipient['last_name'].' '.$recipient['first_name']) ?>
                            (<?= $this->e($recipient['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="channel">Canal *</label>
                <select id="channel" name="channel" required>
                    <?php foreach ($channels as $channel) : ?>
                        <option value="<?= $this->e($channel) ?>" <?= $value('channel') === $channel ? 'selected' : '' ?>>
                            <?= $this->e($channelLabels[$channel] ?? $channel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label for="subject">Objet</label>
            <input id="subject" name="subject" value="<?= $this->e($value('subject')) ?>">
        </div>

        <div class="field">
            <label for="body">Message *</label>
            <textarea id="body" name="body" rows="4" required><?= $this->e($value('body')) ?></textarea>
        </div>

        <button type="submit" class="button">Envoyer</button>
    </form>
<?php endif; ?>

<div class="card">
    <h2>Derniers messages</h2>

    <?php if ($messages === []) : ?>
        <p class="muted">Aucun message.</p>
    <?php else : ?>
        <table class="table">
            <thead>
            <tr><th>Date</th><th>Destinataire</th><th>Canal</th><th>Objet</th><th>Statut</th></tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $message) : ?>
                <tr>
                    <td class="font-mono"><?= $this->date($message['created_at'], 'd/m/Y H:i') ?></td>
                    <td><?= $this->e($message['last_name'].' '.$message['first_name']) ?></td>
                    <td><span class="badge"><?= $this->e($channelLabels[$message['channel']] ?? $message['channel']) ?></span></td>
                    <td><?= $this->e($message['subject'] ?: '-') ?></td>
                    <td><?= $this->e($message['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($rbac->allows('communication-templates:create')) : ?>
    <form method="post" action="/communication/templates" class="card">
        <input type="hidden" name="_token" value="<?= $this->e($csrfToken) ?>">
        <h2>Nouveau modele</h2>

        <div class="grid-2">
            <div class="field">
                <label for="code">Code *</label>
                <input id="code" name="code" required placeholder="RELANCE_SCOLARITE">
            </div>
            <div class="field">
                <label for="name">Nom *</label>
                <input id="name" name="name" required placeholder="Relance de scolarite">
            </div>
            <div class="field">
                <label for="t_channel">Canal *</label>
                <select id="t_channel" name="channel" required>
                    <?php foreach ($channels as $channel) : ?>
                        <option value="<?= $this->e($channel) ?>"><?= $this->e($channelLabels[$channel] ?? $channel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="subject_fr">Objet (francais)</label>
                <input id="subject_fr" name="subject_fr">
            </div>
        </div>

        <div class="field">
            <label for="body_fr">Message en francais *</label>
            <textarea id="body_fr" name="body_fr" rows="3" required></textarea>
        </div>

        <div class="field">
            <label for="body_en">Message en anglais</label>
            <textarea id="body_en" name="body_en" rows="3"></textarea>
        </div>

        <button type="submit" class="button">Enregistrer le modele</button>
    </form>
<?php endif; ?>

<?php if ($templates !== []) : ?>
    <div class="card">
        <h2>Modeles</h2>
        <table class="table">
            <thead><tr><th>Code</th><th>Nom</th><th>Canal</th><th>Bilingue</th></tr></thead>
            <tbody>
            <?php foreach ($templates as $template) : ?>
                <tr>
                    <td class="font-mono"><?= $this->e($template['code']) ?></td>
                    <td><?= $this->e($template['name']) ?></td>
                    <td><span class="badge"><?= $this->e($channelLabels[$template['channel']] ?? $template['channel']) ?></span></td>
                    <td><?= $template['body_en'] ? 'oui' : 'francais seulement' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
