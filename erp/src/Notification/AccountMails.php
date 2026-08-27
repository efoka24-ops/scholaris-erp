<?php

declare(strict_types=1);

namespace Scholaris\Notification;

/**
 * Courriers lies au cycle de vie d'un compte et de la vie scolaire d'un
 * eleve : bienvenue, relance d'impaye, absence, incident disciplinaire,
 * suspension d'etablissement.
 *
 * Meme esprit que EstablishmentMails : texte bref, repli sur un texte code en
 * dur si aucun modele systeme n'est enregistre, et un envoi qui ne fait jamais
 * echouer l'action qui l'a declenche.
 */
final class AccountMails
{
    private Mailer $mailer;

    private string $appName;

    private string $baseUrl;

    private SystemTemplates $templates;

    public function __construct(Mailer $mailer, string $appName, string $baseUrl, SystemTemplates $templates)
    {
        $this->mailer = $mailer;
        $this->appName = $appName;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->templates = $templates;
    }

    /**
     * Bienvenue a un compte cree par l'etablissement (staff, eleve, parent),
     * avec ses identifiants et son lien d'activation.
     */
    public function accountCreated(
        string $recipientEmail,
        string $firstName = '',
        string $lastName = '',
        string $password = '',
        ?string $activationToken = null,
        ?string $tenantId = null
    ): void {
        $name = trim($firstName.' '.$lastName);
        $activationLine = $activationToken !== null
            ? 'Definissez votre propre mot de passe via ce lien (valable 72h) :'."\n".$this->baseUrl.'/activation/'.$activationToken
            : '';

        [$subject, $body] = $this->templates->render(
            'account.created',
            [
                'name' => $name,
                'loginUrl' => $this->baseUrl.'/login',
                'email' => $recipientEmail,
                'password' => $password,
                'activationLink' => $activationToken !== null ? $this->baseUrl.'/activation/'.$activationToken : '',
                'appName' => $this->appName,
            ],
            'Votre acces '.$this->appName,
            implode("\n", [
                'Bonjour '.$name.',',
                '',
                'Un acces vous a ete ouvert sur '.$this->appName.'.',
                '',
                'Adresse : '.$this->baseUrl.'/login',
                'Identifiant : '.$recipientEmail,
                'Mot de passe provisoire : '.$password,
                '',
                $activationLine,
                '',
                'Changez ce mot de passe des votre premiere connexion.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send($recipientEmail, $subject, $body, 'user', null, $tenantId);
    }

    /** Relance d'une facture impayee, adressee au parent. */
    public function paymentOverdue(string $parentEmail, string $parentName, string $studentName, float $amountDue, string $dueDate, string $tenantId): void
    {
        [$subject, $body] = $this->templates->render(
            'billing.overdue',
            [
                'parentName' => $parentName,
                'studentName' => $studentName,
                'amountDue' => number_format($amountDue, 0, ',', ' ').' FCFA',
                'dueDate' => $dueDate,
                'appName' => $this->appName,
            ],
            'Facture en retard - '.$studentName,
            implode("\n", [
                'Bonjour '.$parentName.',',
                '',
                'La scolarite de '.$studentName.' presente un solde impaye',
                'depuis le '.$dueDate.', pour un montant de '.number_format($amountDue, 0, ',', ' ').' FCFA.',
                '',
                'Merci de regulariser aupres de l etablissement des que possible.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send($parentEmail, $subject, $body, 'invoice', null, $tenantId);
    }

    /** Absence signalee, adressee au parent. */
    public function absenceReported(string $parentEmail, string $parentName, string $studentName, string $date, string $status, string $tenantId): void
    {
        [$subject, $body] = $this->templates->render(
            'attendance.absence',
            [
                'parentName' => $parentName,
                'studentName' => $studentName,
                'date' => $date,
                'status' => $status,
                'appName' => $this->appName,
            ],
            'Absence signalee - '.$studentName,
            implode("\n", [
                'Bonjour '.$parentName.',',
                '',
                $studentName.' a ete signale(e) "'.$status.'" le '.$date.'.',
                '',
                'Contactez l etablissement si cette absence n est pas justifiee.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send($parentEmail, $subject, $body, 'attendance', null, $tenantId);
    }

    /** Incident disciplinaire, adresse au parent. */
    public function disciplineIncident(string $parentEmail, string $parentName, string $studentName, string $type, string $date, string $tenantId): void
    {
        [$subject, $body] = $this->templates->render(
            'discipline.incident',
            [
                'parentName' => $parentName,
                'studentName' => $studentName,
                'type' => $type,
                'date' => $date,
                'appName' => $this->appName,
            ],
            'Incident disciplinaire - '.$studentName,
            implode("\n", [
                'Bonjour '.$parentName.',',
                '',
                'Un incident disciplinaire concernant '.$studentName.' a ete',
                'enregistre le '.$date.' ('.$type.').',
                '',
                'L etablissement reste a votre disposition pour en discuter.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send($parentEmail, $subject, $body, 'discipline', null, $tenantId);
    }

    /** Suspension d'etablissement, adressee au(x) directeur(s). */
    public function establishmentSuspended(string $directorEmail, string $directorName, string $establishmentName, string $reason, string $tenantId): void
    {
        [$subject, $body] = $this->templates->render(
            'tenant.suspended',
            [
                'directorName' => $directorName,
                'establishmentName' => $establishmentName,
                'reason' => $reason,
                'appName' => $this->appName,
            ],
            'Etablissement suspendu - '.$establishmentName,
            implode("\n", [
                'Bonjour '.$directorName.',',
                '',
                $establishmentName.' a ete suspendu sur '.$this->appName.'.',
                '',
                'Motif : '.$reason,
                '',
                'Aucun compte de l etablissement ne peut plus se connecter tant',
                'que la suspension n est pas levee. Contactez l administration',
                'de la plateforme pour la regulariser.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send($directorEmail, $subject, $body, 'tenant', null, $tenantId);
    }

    /** Reactivation d'un etablissement suspendu, adressee au(x) directeur(s). */
    public function establishmentReactivated(string $directorEmail, string $directorName, string $establishmentName, string $tenantId): void
    {
        [$subject, $body] = $this->templates->render(
            'tenant.reactivated',
            [
                'directorName' => $directorName,
                'establishmentName' => $establishmentName,
                'appName' => $this->appName,
            ],
            'Etablissement reactive - '.$establishmentName,
            implode("\n", [
                'Bonjour '.$directorName.',',
                '',
                $establishmentName.' est de nouveau actif sur '.$this->appName.'.',
                '',
                'Les comptes de l etablissement peuvent de nouveau se connecter.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send($directorEmail, $subject, $body, 'tenant', null, $tenantId);
    }
}
