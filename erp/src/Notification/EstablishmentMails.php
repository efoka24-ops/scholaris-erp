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
 * telephone, souvent en 2G.
 */
final class EstablishmentMails
{
    private Mailer $mailer;

    private string $appName;

    private string $baseUrl;

    public function __construct(Mailer $mailer, string $appName, string $baseUrl)
    {
        $this->mailer = $mailer;
        $this->appName = $appName;
        $this->baseUrl = rtrim($baseUrl, '/');
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
     * @param  array<string, mixed>  $demand
     */
    public function approved(array $demand, string $password, ?string $tenantId = null): void
    {
        $body = $this->lines([
            'Bonjour '.$demand['director_first_name'].' '.$demand['director_last_name'].',',
            '',
            'Votre etablissement '.$demand['name'].' est ouvert sur '.$this->appName.'.',
            '',
            'Adresse : '.$this->baseUrl.'/login',
            'Identifiant : '.$demand['director_email'],
            'Mot de passe provisoire : '.$password,
            '',
            'Changez ce mot de passe des votre premiere connexion : il a circule',
            'par courrier electronique, il ne doit pas rester en service.',
            '',
            'Votre structure pedagogique a ete creee selon le type de votre',
            'etablissement. Vous pouvez l ajuster depuis votre espace.',
            '',
            $this->appName,
        ]);

        $this->mailer->send(
            (string) $demand['director_email'],
            'Votre etablissement est ouvert - '.$demand['name'],
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
        $body = $this->lines([
            'Bonjour '.$demand['director_first_name'].' '.$demand['director_last_name'].',',
            '',
            'Votre demande d ouverture de '.$demand['name'].' n a pas ete retenue.',
            '',
            'Motif : '.$reason,
            '',
            'Vous pouvez deposer une nouvelle demande apres avoir corrige ce point :',
            $this->baseUrl.'/demande-etablissement',
            '',
            $this->appName,
        ]);

        $this->mailer->send(
            (string) $demand['director_email'],
            'Demande non retenue - '.$demand['name'],
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
