<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

use InvalidArgumentException;

/** Frais du catalogue : type (socle ou enregistré par une application, S10), code stable facultatif, libellé, montant. */
final class FeeItemRequest extends ValidatedRequest
{
    public const CODE_FORMAT = '/^[a-z][a-z0-9_.-]{1,39}$/';

    /** @param list<string> $kinds types permis */
    public function __construct(private readonly array $kinds = ['registration', 'tuition', 'other'])
    {
    }

    protected function fields(): array
    {
        return [
            'kind' => Field::enum($this->kinds)->required(),
            'code' => Field::custom(static function (mixed $v): string {
                if (!is_string($v) || preg_match(self::CODE_FORMAT, $v) !== 1) {
                    throw new InvalidArgumentException('Code : minuscules, chiffres, « _ », « . » ou « - » (2 à 40 caractères, commence par une lettre).');
                }

                return $v;
            }),
            'name' => Field::string(160)->required(),
            'amount_xaf' => Field::int(0, 100000000)->required(),
            'active' => Field::custom(static function (mixed $v): int {
                if (!is_bool($v)) {
                    throw new \InvalidArgumentException('Ce champ doit être vrai ou faux.');
                }

                return $v ? 1 : 0;
            }),
        ];
    }
}
