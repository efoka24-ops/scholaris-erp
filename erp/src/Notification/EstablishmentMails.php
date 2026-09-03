<?php

declare(strict_types=1);

namespace Scholaris\Notification;

/**
 * Courriers adresses au chef d'etablissement au fil de sa demande.
 *
 * Trois moments, trois courriers : la demande est recue, elle est acceptee,
 * elle est refusee. Sans eux, le demandeur depose un dossier et n'entend plus
 * jamais parler de rien — c'etait le cas jusqu'ici, et il ne lui restait que
 * le telephone.
 *
 * Le texte est volontairement bref et sans mise en forme : il sera lu sur un
 * telephone, souvent en 2G. Chaque texte peut etre remplace par un modele
 * systeme enregistre en base (voir SystemTemplates) ; a defaut, c'est le texte
 * ci-dessous qui part.
 */
final class EstablishmentMails
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
     * Accuse de reception, avec la reference du dossier.
     *
     * @param  array<string, mixed>  $demand
     */
    public function acknowledge(array $demand): void
    {
        $reference = (string) $demand['reference'];

        $body = $this->lines([
            'Bonjour '.$demand['director_first_name'].' '.$demand['director_last_name'].',',
            '',
            'Votre demande d ouverture de '.$demand['name'].' sur '.$this->appName.' a bien ete recue.',
            '',
            'Reference du dossier : '.$reference,
            'Code souhaite : '.$demand['code'],
            '',
            'Elle sera instruite par l administration de la plateforme. Vous',
            'recevrez vos identifiants a cette adresse des qu elle sera acceptee.',
            '',
            'Vous pouvez suivre son avancement a tout moment :',
            $this->baseUrl.'/demande-etablissement/suivi',
            'Munissez-vous de la reference ci-dessus et de cette adresse email.',
            '',
            $this->appName,
        ]);

        $this->mailer->send(
            (string) $demand['director_email'],
            'Demande recue - '.$demand['name'].' ('.$reference.')',
            $body,
            'establishment_request',
            (string) $demand['id']
        );
    }

    /**
     * Identifiants du responsable, une fois la demande acceptee.
     *
     * Le mot de passe provisoire reste indique, pour le cas ou le responsable
     * ne peut pas cliquer le lien (2G, boite mail sur un vieux telephone) ;
     * le lien d'activation, valable 72 heures, lui permet de choisir lui-meme
     * son mot de passe sans le faire circuler davantage.
     *
     * @param  array<string, mixed>  $demand
     */
    public function approved(array $demand, string $password, ?string $tenantId = null, ?string $activationToken = null): void
    {
        $name = $demand['director_first_name'].' '.$demand['director_last_name'];
        $activationLine = $activationToken !== null
            ? 'Ou definissez votre propre mot de passe via ce lien (valable 72h) :'."\n".$this->baseUrl.'/activation/'.$activationToken
            : '';

        [$subject, $body] = $this->templates->render(
            'establishment.approved',
            [
                'directorName' => $name,
                'establishmentName' => (string) $demand['name'],
                'loginUrl' => $this->baseUrl.'/login',
                'email' => (string) $demand['director_email'],
                'password' => $password,
                'activationLink' => $activationToken !== null ? $this->baseUrl.'/activation/'.$activationToken : '',
                'appName' => $this->appName,
            ],
            'Votre etablissement est ouvert - '.$demand['name'],
            $this->lines([
                'Bonjour '.$name.',',
                '',
                'Votre etablissement '.$demand['name'].' est ouvert sur '.$this->appName.'.',
                '',
                'Adresse : '.$this->baseUrl.'/login',
                'Identifiant : '.$demand['director_email'],
                'Mot de passe provisoire : '.$password,
                '',
                $activationLine,
                '',
                'Changez ce mot de passe des votre premiere connexion : il a circule',
                'par courrier electronique, il ne doit pas rester en service.',
                '',
                'Votre structure pedagogique a ete creee selon le type de votre',
                'etablissement. Vous pouvez l ajuster depuis votre espace.',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send(
            (string) $demand['director_email'],
            $subject,
            $body,
            'establishment_request',
            (string) $demand['id'],
            $tenantId
        );
    }

    /**
     * Refus motive.
     *
     * Le motif est repris tel quel : un refus sans raison laisse le demandeur
     * sans rien a corriger, et il redeposera le meme dossier.
     *
     * @param  array<string, mixed>  $demand
     */
    public function rejected(array $demand, string $reason): void
    {
        $name = $demand['director_first_name'].' '.$demand['director_last_name'];

        [$subject, $body] = $this->templates->render(
            'establishment.rejected',
            [
                'directorName' => $name,
                'establishmentName' => (string) $demand['name'],
                'rejectReason' => $reason,
                'appName' => $this->appName,
            ],
            'Demande non retenue - '.$demand['name'],
            $this->lines([
                'Bonjour '.$name.',',
                '',
                'Votre demande d ouverture de '.$demand['name'].' n a pas ete retenue.',
                '',
                'Motif : '.$reason,
                '',
                'Vous pouvez deposer une nouvelle demande apres avoir corrige ce point :',
                $this->baseUrl.'/demande-etablissement',
                '',
                $this->appName,
            ])
        );

        $this->mailer->send(
            (string) $demand['director_email'],
            $subject,
            $body,
            'establishment_request',
            (string) $demand['id']
        );
    }

    /** @param  list<string>  $lines */
    private function lines(array $lines): string
    {
        return implode("\n", $lines);
    }
}
