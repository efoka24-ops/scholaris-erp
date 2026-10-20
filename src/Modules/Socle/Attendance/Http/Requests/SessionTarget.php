<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/**
 * Cible d'un appel : soit un créneau (`slot_id`), soit, en mode day_part, une classe et un moment de la journée
 * (`structure_id` + `day_part`). Les deux formes s'excluent ; le mode de l'établissement est vérifié par le service.
 */
final class SessionTarget
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    public static function check(array $values, array $errors): array
    {
        $slot = isset($values['slot_id']);
        $dayPart = isset($values['day_part']) || isset($values['structure_id']);
        if ($slot && $dayPart) {
            $errors['day_part'] = ['Indiquez soit slot_id, soit structure_id et day_part, pas les deux.'];
        } elseif (!$slot && !$dayPart) {
            $errors['slot_id'] = ['Ce champ est obligatoire.'];
        } elseif (!$slot && !isset($values['structure_id'])) {
            $errors['structure_id'] = ['Ce champ est obligatoire avec day_part.'];
        } elseif (!$slot && !isset($values['day_part'])) {
            $errors['day_part'] = ['Ce champ est obligatoire avec structure_id.'];
        }

        return $errors;
    }
}
