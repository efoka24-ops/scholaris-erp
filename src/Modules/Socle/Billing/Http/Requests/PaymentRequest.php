<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;

/** Paiement au guichet ou demande de paiement mobile money. Le montant est un entier en XAF. */
final class PaymentRequest extends ValidatedRequest
{
    public function __construct(private readonly bool $cashOnly = false)
    {
    }

    protected function fields(): array
    {
        $fields = [
            'amount_xaf' => Field::int(1, 100000000)->required(),
            'family_reference' => Field::string(30),
            'learner_id' => Field::id(),
            'invoice_id' => Field::id(),
            'external_reference' => Field::string(80),
            'payer_phone' => Field::string(30),
        ];

        return $this->cashOnly ? $fields : ['channel' => Field::enum(PaymentChannel::values())->required()] + $fields;
    }

    protected function crossCheck(array $values, array $errors): array
    {
        $targets = array_filter([$values['family_reference'] ?? null, $values['learner_id'] ?? null, $values['invoice_id'] ?? null]);
        if (count($targets) > 1) {
            $errors['family_reference'] = ['Indiquez une seule cible : référence famille, apprenant ou facture.'];
        }
        if (isset($values['external_reference']) && !$this->cashOnly && ($values['channel'] ?? null) === 'cash') {
            $errors['external_reference'] = ['Une référence d\'opérateur ne s\'applique pas aux espèces.'];
        }

        return $errors;
    }
}
