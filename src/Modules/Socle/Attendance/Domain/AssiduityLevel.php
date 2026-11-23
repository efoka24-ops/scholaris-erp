<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Domain;

/**
 * Niveau d'assiduité d'un apprenant d'après son taux d'absences non justifiées :
 * au-dessus du seuil maximal, il n'est pas autorisé à composer ; à partir du seuil d'alerte, il est signalé.
 */
enum AssiduityLevel: string
{
    case Ok = 'ok';
    case Alert = 'alert';
    case NotAllowed = 'not_allowed';

    public static function of(float $unjustifiedPercent, float $maxPercent, float $alertPercent): self
    {
        if ($unjustifiedPercent > $maxPercent) {
            return self::NotAllowed;
        }

        return $unjustifiedPercent >= $alertPercent ? self::Alert : self::Ok;
    }
}
