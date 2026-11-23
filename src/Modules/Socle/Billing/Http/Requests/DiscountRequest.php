<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

/** Remise ou bourse : appliquée à la pension par défaut, répartie sur les tranches restantes. */
final class DiscountRequest extends ValidatedRequest
{
    /** @param list<string> $targets cibles permises (types de frais du socle et des applications, plus « all ») */
    public function __construct(private readonly array $targets = ['tuition', 'registration', 'other', 'all'])
    {
    }

    protected function fields(): array
    {
        return [
            'kind' => Field::enum(['sibling', 'staff', 'merit', 'waiver'])->required(),
            'mode' => Field::enum(['percent', 'fixed'])->required(),
            'value' => Field::int(0, 100000000)->required(),
            'applies_to' => Field::enum($this->targets),
            'reason' => Field::string(255)->required(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        if ($values['mode'] === 'percent' && $values['value'] > 100) {
            $errors['value'] = ['Un pourcentage ne peut pas dépasser 100.'];
        }

        return $errors;
    }
}
