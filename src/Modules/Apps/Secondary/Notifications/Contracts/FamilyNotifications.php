<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Notifications\Contracts;

/**
 * Notifications aux familles du lot E1 (CDC « Notifications »), appelées par le sous-module qui porte l'événement :
 *  - NOT-01 « inscription validée, matricule enregistré » : Enrolment, quand l'inscription est validée ET le matricule enregistré ;
 *  - NOT-09 « paiement reçu » : Fees ou CarteScolaire, pour un paiement dont le reçu n'est pas déjà envoyé par le socle Billing.
 * Modèles `sec.not01.enrolment_validated` et `sec.not09.payment_received` (FR/EN, migration 202610281602), envoyés par le Messenger
 * du socle (consentement, plages horaires). Un même événement n'est jamais notifié deux fois au même destinataire (journal
 * `se_notification_log`). Un échec d'envoi ne remet jamais en cause l'opération appelante.
 */
interface FamilyNotifications
{
    /** @return int nombre de destinataires notifiés lors de cet appel (0 si déjà fait ou sans garant) */
    public function enrolmentValidated(string $tenantId, string $establishmentId, string $enrollmentId): int;

    /** @return int nombre de destinataires notifiés lors de cet appel */
    public function paymentReceived(string $tenantId, string $establishmentId, string $learnerId, string $receiptNumber, int $amountXaf): int;
}
