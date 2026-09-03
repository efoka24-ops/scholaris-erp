<?php

declare(strict_types=1);

namespace Scholaris\Notification;

use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Throwable;

/**
 * Courriers sortants de la plateforme.
 *
 * Ecrire d'abord, envoyer ensuite. Un hebergement mutualise peut refuser un
 * envoi, le mettre en file, ou le perdre sans rien dire ; si l'envoi etait la
 * seule trace, personne ne saurait jamais ce qui a ete adresse a qui. Le
 * courrier est donc enregistre avant la tentative, puis marque envoye ou en
 * echec.
 *
 * Un echec d'envoi ne fait jamais echouer l'action qui l'a declenche. Refuser
 * la creation d'un etablissement parce que le serveur de courrier est
 * indisponible serait absurde : le compte existe, le mot de passe est affiche
 * a l'ecran, et le courrier peut etre repris.
 */
final class Mailer
{
    private Connection $db;

    private string $fromAddress;

    private string $fromName;

    /**
     * Envoi reellement tente ? Faux en test, ou quand aucune adresse
     * d'expedition n'est configuree : le courrier est alors journalise sans
     * quitter la machine.
     */
    private bool $delivers;

    /**
     * Serveur de messagerie du domaine, quand il est configure.
     *
     * S'authentifier aupres de lui permet d'expedier reellement depuis
     * noreply@ ; a defaut, mail() remet le courrier au serveur local, avec une
     * adresse d'expedition que l'on ne maitrise pas et qui finit souvent en
     * indesirable.
     */
    private ?SmtpTransport $smtp;

    public function __construct(
        Connection $db,
        string $fromAddress,
        string $fromName,
        bool $delivers = true,
        ?SmtpTransport $smtp = null
    ) {
        $this->db = $db;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName;
        $this->delivers = $delivers && $fromAddress !== '';
        $this->smtp = $smtp;
    }

    /**
     * Enregistre un courrier et tente de le remettre.
     *
     * @return string identifiant du courrier
     */
    public function send(
        string $recipient,
        string $subject,
        string $body,
        ?string $contextType = null,
        ?string $contextId = null,
        ?string $tenantId = null
    ): string {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO notifications
                (id, tenant_id, channel, recipient, subject, body, context_type, context_id, status, created_at)
             VALUES (:id, :tenant, :channel, :recipient, :subject, :body, :ctype, :cid, :status, :created_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'channel' => 'EMAIL',
                'recipient' => $recipient,
                'subject' => $subject,
                'body' => $body,
                'ctype' => $contextType,
                'cid' => $contextId,
                'status' => 'PENDING',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        $this->deliver($id, $recipient, $subject, $body);

        return $id;
    }

    /**
     * Reprend un courrier reste en echec.
     *
     * @return bool vrai si la remise a abouti cette fois
     */
    public function retry(string $notificationId): bool
    {
        $row = $this->db->selectOne(
            'SELECT * FROM notifications WHERE id = :id',
            ['id' => $notificationId]
        );

        if ($row === null || (string) $row['status'] === 'SENT') {
            return false;
        }

        return $this->deliver(
            (string) $row['id'],
            (string) $row['recipient'],
            (string) $row['subject'],
            (string) $row['body']
        );
    }

    private function deliver(string $id, string $recipient, string $subject, string $body): bool
    {
        if (! $this->delivers) {
            $this->mark($id, 'SKIPPED', 'Envoi desactive : aucune adresse d expedition configuree.');

            return false;
        }

        try {
            if ($this->smtp !== null) {
                $this->smtp->send($this->fromAddress, $this->fromName, $recipient, $subject, $body);
                $this->mark($id, 'SENT', null);

                return true;
            }

            $headers = implode("\r\n", [
                'From: '.$this->encodeHeader($this->fromName).' <'.$this->fromAddress.'>',
                'Reply-To: '.$this->fromAddress,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);

            $ok = @mail($recipient, $this->encodeHeader($subject), $body, $headers);
        } catch (Throwable $e) {
            $this->mark($id, 'FAILED', $e->getMessage());

            return false;
        }

        if ($ok) {
            $this->mark($id, 'SENT', null);

            return true;
        }

        $this->mark($id, 'FAILED', 'Le serveur de courrier a refuse la remise.');

        return false;
    }

    private function mark(string $id, string $status, ?string $error): void
    {
        $this->db->execute(
            'UPDATE notifications SET status = :status, error = :error, sent_at = :sent_at WHERE id = :id',
            [
                'status' => $status,
                'error' => $error,
                'sent_at' => $status === 'SENT' ? date('Y-m-d H:i:s') : null,
                'id' => $id,
            ]
        );
    }

    /**
     * Un sujet ou un nom d'expediteur accentue doit etre encode, sinon il
     * arrive illisible chez le destinataire.
     */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }
}
