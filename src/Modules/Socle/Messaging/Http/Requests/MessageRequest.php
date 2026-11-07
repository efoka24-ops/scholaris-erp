<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Messaging\Domain\Audience;
use Scholaris\Modules\Socle\Messaging\Domain\Channel;

/** Création d'un message : un modèle ou un texte libre, une audience, des canaux, un envoi éventuellement programmé. */
final class MessageRequest extends ValidatedRequest
{
    public static function audienceField(): Field
    {
        return Field::custom(static function (mixed $value): Audience {
            if (!is_array($value)) {
                throw new InvalidArgumentException('Audience invalide : {"type": ..., "id": ...} attendu.');
            }

            return Audience::fromArray($value);
        })->required();
    }

    protected function fields(): array
    {
        return [
            'template_key' => Field::string(60, 2, '/^[a-z][a-z0-9_.]*$/'),
            'body' => Field::string(2000),
            'channels' => Field::enumList(Channel::all(), 3),
            'variables' => Field::scalarMap(),
            'audience' => self::audienceField(),
            'urgent' => Field::bool(),
            'scheduled_at' => Field::dateTime()->nullable(),
        ];
    }
}
