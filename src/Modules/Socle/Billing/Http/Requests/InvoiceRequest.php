<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;

/**
 * Génération d'une facture pour une inscription, avec d'éventuelles remises ou bourses (mérite, personnel, exonération)
 * et, facultativement, un payeur tiers au nom duquel la facture est établie (S13).
 */
final class InvoiceRequest extends ValidatedRequest
{
    /** @param list<string> $discountTargets */
    public function __construct(private readonly array $discountTargets = ['tuition', 'registration', 'other', 'all'])
    {
    }

    protected function fields(): array
    {
        $targets = $this->discountTargets;

        return [
            'enrollment_id' => Field::id()->required(),
            'level' => Field::string(60),
            'track' => Field::string(60),
            'discounts' => Field::custom(static function (mixed $value) use ($targets): array {
                if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
                    throw new InvalidArgumentException('Liste de 10 remises au plus.');
                }
                $out = [];
                foreach ($value as $item) {
                    $out[] = is_array($item) ? (new DiscountRequest($targets))->validate($item) : throw new InvalidArgumentException('Remise invalide.');
                }

                return $out;
            }),
            'payer' => Field::custom(static fn (mixed $value): InvoicePayer => self::payer($value)),
        ];
    }

    /** Payeur tiers : {kind: person|organisation, name, reference?}. */
    public static function payer(mixed $value): InvoicePayer
    {
        if (!is_array($value) || array_diff(array_keys($value), ['kind', 'name', 'reference']) !== []) {
            throw new InvalidArgumentException('Payeur attendu : {kind, name, reference?}.');
        }
        $reference = $value['reference'] ?? null;
        if (!is_string($value['kind'] ?? null) || !is_string($value['name'] ?? null) || ($reference !== null && !is_string($reference))) {
            throw new InvalidArgumentException('Payeur attendu : {kind, name, reference?} en texte.');
        }

        return new InvoicePayer($value['kind'], trim($value['name']), $reference === null ? null : trim($reference));
    }
}
