<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Portals\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Billing\Contracts\FamilyPaymentReferences;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOutcome;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;

/**
 * Portails élève et parent du secondaire dans l'établissement actif (T834) : chacun lit par SON périmètre, jamais par les droits de
 * lecture du personnel. L'élève (secondary.self.read) voit sa fiche ; le parent (secondary.family.read) voit ses enfants inscrits dans
 * l'établissement, et seulement eux (lien garant du socle People) ; le paiement (secondary.family.pay) passe par le contrat
 * d'encaissement du socle (mobile money, idempotent), pour une facture d'un de ses enfants seulement. Un enfant d'une autre famille,
 * ou d'un autre établissement, répond 404 (son existence n'est pas révélée).
 */
final class PortalService
{
    public const SELF = ['secondary.self.read'];
    public const FAMILY = ['secondary.family.read'];
    public const PAY = ['secondary.family.pay'];
    public const CHANNELS = ['orange_money', 'mtn_momo'];

    public function __construct(
        private readonly StudentCards $cards,
        private readonly EstablishmentProfiles $profiles,
        private readonly PersonDirectory $people,
        private readonly UserPersonLink $users,
        private readonly AdultLearnerCheck $adults,
        private readonly ConsentChecker $consents,
        private readonly InvoiceIssuer $invoices,
        private readonly PaymentRequester $payments,
        private readonly Access $access,
        private readonly SchoolLife $life,
        private readonly FamilyPaymentReferences $references,
    ) {
    }

    /**
     * T974 : vie scolaire de l'élève connecté (résultats publiés, bulletins, absences, devoirs, services).
     *
     * @return array<string, mixed>
     */
    public function studentLife(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::SELF);
        $t = $principal->tenantId;
        $person = $this->people->find($t, $this->personOf($principal)) ?? throw HttpException::notFound('Fiche introuvable.');
        $enrollment = $this->cards->liveEnrollment($t, $establishmentId, $person->id)
            ?? throw HttpException::notFound('Aucune inscription en cours dans cet établissement.');

        return $this->life->forStudent($t, $establishmentId, $person, $enrollment, true);
    }

    /**
     * T974 : vie scolaire d'un de SES enfants (404 sinon) ; accès restreint pour un élève majeur sans consentement.
     *
     * @return array<string, mixed>
     */
    public function childLife(Principal $principal, string $establishmentId, string $childId): array
    {
        $this->access->require($principal, $establishmentId, self::FAMILY);
        $t = $principal->tenantId;
        $child = $this->ownChild($principal, $establishmentId, $childId);
        $enrollment = $this->cards->liveEnrollment($t, $establishmentId, $child->id) ?? throw HttpException::notFound('Élève introuvable.');

        return $this->life->forStudent($t, $establishmentId, $child, $enrollment, $this->fullAccess($t, $child));
    }

    /** @return array<string, mixed> */
    public function student(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::SELF);
        $t = $principal->tenantId;
        $person = $this->people->find($t, $this->personOf($principal)) ?? throw HttpException::notFound('Fiche introuvable.');
        $enrollment = $this->cards->liveEnrollment($t, $establishmentId, $person->id)
            ?? throw HttpException::notFound('Aucune inscription en cours dans cet établissement.');

        return $this->cards->build($t, $this->profiles->forEstablishment($t, $establishmentId), $person, $enrollment, true, false);
    }

    /** @return array<string, mixed> */
    public function family(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::FAMILY);
        $t = $principal->tenantId;
        $guardianId = $this->personOf($principal);
        $profile = $this->profiles->forEstablishment($t, $establishmentId);
        $canPay = !$this->access->perimeter($principal, $establishmentId, self::PAY)->isEmpty();
        $children = [];
        $total = 0;
        foreach ($this->people->learnersOf($t, $guardianId) as $child) {
            if ($child->tenantId !== $t) {
                continue;
            }
            $enrollment = $this->cards->liveEnrollment($t, $establishmentId, $child->id);
            if ($enrollment === null) {
                continue;
            }
            $card = $this->cards->build($t, $profile, $child, $enrollment, $this->fullAccess($t, $child), $canPay);
            $total += (int) $card['balance_xaf'];
            $children[] = $card;
        }

        // D-BUL : la référence de paiement famille du parent connecté, et seulement la sienne (guichet, mobile money).
        $reference = $this->references->referenceOf($t, $guardianId);

        return ['guardian_id' => $guardianId, 'can_pay' => $canPay, 'family_balance_xaf' => $total, 'payment_reference' => $reference, 'children' => $children];
    }

    /**
     * @param array<string, mixed> $input invoice_id, amount_xaf, channel, payer_phone, idempotency_key
     * @return array<string, mixed>
     */
    public function pay(Principal $principal, string $establishmentId, string $childId, array $input): array
    {
        $this->access->require($principal, $establishmentId, self::PAY);
        $t = $principal->tenantId;
        $this->ownChild($principal, $establishmentId, $childId);
        $invoiceId = (string) $input['invoice_id'];
        $invoice = $this->invoices->find($t, $establishmentId, $invoiceId);
        if ($invoice === null || $invoice->subjectId !== $childId || $invoice->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Facture introuvable.');
        }
        $amount = (int) $input['amount_xaf'];
        if ($amount > $invoice->balanceXaf) {
            throw HttpException::validation(['amount_xaf' => ['Le montant dépasse le reste dû de cette facture.']]);
        }
        $outcome = $this->payments->request(new PaymentOrder(
            $t,
            $establishmentId,
            null,
            $invoiceId,
            $amount,
            (string) $input['channel'],
            'secondary-portal:' . $principal->userId . ':' . (string) $input['idempotency_key'],
            (string) $input['payer_phone'],
        ));
        $this->access->audit($principal, 'secondary.portal.payment.requested', 'invoice', $invoiceId, null, ['payment_id' => $outcome->paymentId, 'amount_xaf' => $amount, 'channel' => $outcome->channel]);

        return self::payment($outcome, $childId);
    }

    /** @return array<string, mixed> */
    public function paymentStatus(Principal $principal, string $establishmentId, string $childId, string $paymentId): array
    {
        $this->access->require($principal, $establishmentId, self::PAY);
        $this->ownChild($principal, $establishmentId, $childId);
        $outcome = $this->payments->status($principal->tenantId, $establishmentId, $paymentId);
        $invoice = $outcome === null || $outcome->invoiceId === null ? null : $this->invoices->find($principal->tenantId, $establishmentId, $outcome->invoiceId);
        if ($outcome === null || $invoice === null || $invoice->subjectId !== $childId) {
            throw HttpException::notFound('Paiement introuvable.');
        }

        return self::payment($outcome, $childId);
    }

    /** @return array<string, mixed> */
    private static function payment(PaymentOutcome $outcome, string $childId): array
    {
        return [
            'id' => $outcome->paymentId, 'status' => $outcome->status, 'amount_xaf' => $outcome->amountXaf, 'channel' => $outcome->channel,
            'invoice_id' => $outcome->invoiceId, 'receipt_number' => $outcome->receiptNumber, 'child_id' => $childId, 'replayed' => $outcome->replayed,
        ];
    }

    private function ownChild(Principal $principal, string $establishmentId, string $childId): PersonSummary
    {
        $t = $principal->tenantId;
        foreach ($this->people->learnersOf($t, $this->personOf($principal)) as $child) {
            if ($child->id === $childId && $child->tenantId === $t && $this->cards->liveEnrollment($t, $establishmentId, $childId) !== null) {
                return $child;
            }
        }

        throw HttpException::notFound('Élève introuvable.');
    }

    private function fullAccess(string $tenantId, PersonSummary $child): bool
    {
        return $this->adults->isAdult($tenantId, $child->id) === false || $this->consents->hasConsent($tenantId, $child->id, ConsentChecker::PARENT_ACCESS);
    }

    private function personOf(Principal $principal): string
    {
        return $this->users->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : contactez l\'établissement.');
    }
}
