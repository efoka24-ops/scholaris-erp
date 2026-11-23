<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

use Scholaris\Modules\Socle\Messaging\Application\ChatService;

/** Ouverture d'une conversation : objet, nature, apprenant concerné, interlocuteurs et premier message. */
final class ConversationRequest extends ValidatedRequest
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    /** Liste d'identifiants (interlocuteurs, documents). */
    public static function idList(int $max): Field
    {
        return Field::custom(static function (mixed $value) use ($max): array {
            if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
                throw new \InvalidArgumentException(sprintf('Ce champ doit être une liste de %d identifiants au plus.', $max));
            }
            foreach ($value as $item) {
                if (!is_string($item) || preg_match(self::ID_PATTERN, $item) !== 1) {
                    throw new \InvalidArgumentException('Identifiant invalide.');
                }
            }

            return array_values(array_unique($value));
        });
    }

    protected function fields(): array
    {
        return [
            'subject' => Field::string(160)->required(),
            'kind' => Field::enum(ChatService::KINDS)->required(),
            'learner_id' => Field::string(40, 1, self::ID_PATTERN)->required(),
            'participant_ids' => self::idList(ChatService::MAX_PARTICIPANTS - 1)->required(),
            'body' => Field::string(5000)->required(),
            'attachments' => self::idList(ChatService::MAX_ATTACHMENTS),
        ];
    }
}
