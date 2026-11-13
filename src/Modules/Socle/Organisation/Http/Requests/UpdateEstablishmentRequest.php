<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

/** Le type d'un établissement est définitif : toute tentative de le modifier est refusée. */
final class UpdateEstablishmentRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'name' => Field::string(160)->required(),
            'type' => Field::forbidden('Le type d\'un établissement est définitif : il ne peut pas être modifié après la création.'),
        ];
    }
}
