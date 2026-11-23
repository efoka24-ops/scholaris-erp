<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Contexte commun de l'emploi du temps et du cahier de textes : permissions, profil ouvert exigé pour écrire, classe de l'année,
 * personne du compte, noms affichés, contrôle des heures HH:MM.
 */
final class TimetableContext
{
    public const READ = ['secondary.timetable.read', 'secondary.timetable.manage', 'secondary.timetable.validate'];
    public const MANAGE = ['secondary.timetable.manage'];
    /** L'enseignant (R09) lit son propre emploi du temps. */
    public const OWN = ['secondary.lesson.log'];
    public const DAYS_FR = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    public function __construct(
        private readonly EstablishmentProfiles $profiles,
        private readonly ClassProfiles $classes,
        private readonly UserPersonLink $links,
        private readonly PersonDirectory $people,
    ) {
    }

    /** Profil ouvert exigé avant toute écriture (sinon 409). */
    public function opened(string $tenantId, string $establishmentId): EstablishmentProfile
    {
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        if (!$profile->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }

        return $profile;
    }

    public function profile(string $tenantId, string $establishmentId): EstablishmentProfile
    {
        return $this->profiles->forEstablishment($tenantId, $establishmentId);
    }

    /** Classe paramétrée de l'année (sinon 422 sur le champ). */
    public function schoolClass(string $tenantId, string $establishmentId, string $classId, ?string $academicYearId, string $field = 'class_id'): ClassProfileInfo
    {
        $class = $this->classes->find($tenantId, $establishmentId, $classId);
        if ($class === null || ($academicYearId !== null && $class->academicYearId !== $academicYearId)) {
            throw HttpException::validation([$field => ['Classe non paramétrée pour cette année dans l\'établissement.']]);
        }

        return $class;
    }

    public function findClass(string $tenantId, string $establishmentId, string $classId): ?ClassProfileInfo
    {
        return $this->classes->find($tenantId, $establishmentId, $classId);
    }

    /** @return list<ClassProfileInfo> */
    public function classesOfYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        return $this->classes->forYear($tenantId, $establishmentId, $academicYearId);
    }

    public function className(string $tenantId, string $establishmentId, string $classId): string
    {
        $class = $this->classes->find($tenantId, $establishmentId, $classId);

        return $class === null ? $classId : $class->label;
    }

    public function personOf(Principal $principal): ?string
    {
        return $this->links->personIdForUser($principal->tenantId, $principal->userId);
    }

    public function personExists(string $tenantId, string $personId): bool
    {
        return $this->people->find($tenantId, $personId) !== null;
    }

    /**
     * @param list<string> $personIds
     * @return array<string, string> identifiant => nom affiché
     */
    public function names(string $tenantId, array $personIds): array
    {
        $names = [];
        foreach (array_values(array_unique($personIds)) as $personId) {
            $person = $this->people->find($tenantId, $personId);
            $names[$personId] = $person === null ? $personId : $person->fullName();
        }

        return $names;
    }

    public static function time(Input $in, string $field, bool $required = true): ?string
    {
        $value = $in->custom($field, static function (mixed $v): string {
            if (!is_string($v) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                throw new \InvalidArgumentException('Heure HH:MM attendue.');
            }

            return $v;
        }, $required);

        return is_string($value) ? $value : null;
    }

    /** Premier jour, à partir de `from`, qui tombe le jour de semaine donné (1 = lundi). */
    public static function firstWeekday(string $from, int $weekday): string
    {
        $day = new \DateTimeImmutable($from);
        $offset = ($weekday - (int) $day->format('N') + 7) % 7;

        return $day->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    public static function dayBefore(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    public static function dayAfter(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }
}
