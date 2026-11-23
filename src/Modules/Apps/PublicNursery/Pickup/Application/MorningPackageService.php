<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Application;

use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Paquet du matin (T725, FR-M06) : enfants inscrits dans la portée de l'appelant, absents du jour, personnes autorisées valables ce
 * jour, restrictions de garde et numéros de la direction, pour que la porte fonctionne hors ligne. Le paquet porte son horodatage et
 * l'âge maximal lu de `public_nursery.pickup.offline_max_age_days` : au-delà, l'appareil passe en mode « doute » (aucune remise
 * sans appel à la direction). Les codes ne sont PAS dans le paquet : un code se vérifie en ligne, le secret de hachage ne quitte
 * jamais le serveur. Aucune donnée médicale ni allergie.
 */
final class MorningPackageService
{
    public const MAX_AGE_KEY = 'public_nursery.pickup.offline_max_age_days';

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly AuthorizedPickupRegistry $registry,
        private readonly CustodyRestrictionService $restrictions,
        private readonly DailyPresence $presence,
        private readonly RoleDirectory $roles,
        private readonly SettingsReader $settings,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forDay(string $tenantId, string $establishmentId, SectionScope $scope, string $day): array
    {
        $ids = [];
        foreach ($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId) as $learnerId) {
            foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
                if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)
                    && ($enrollment->structureId === null || $scope->contains($enrollment->structureId))) {
                    $ids[] = $learnerId;
                    break;
                }
            }
        }
        $absent = array_flip($this->presence->absentOn($tenantId, $establishmentId, $day));
        $authorized = [];
        foreach ($this->registry->validOn($tenantId, $establishmentId, $ids, $day) as $info) {
            $authorized[$info->learnerId][] = ['id' => $info->id, 'name' => $info->name, 'relationship' => $info->relationship, 'grant_type' => $info->grantType, 'valid_on' => $info->validOn];
        }
        $children = [];
        foreach ($this->people->findMany($tenantId, $ids) as $person) {
            $restrictions = [];
            foreach ($this->restrictions->activeFor($tenantId, $establishmentId, $person->id, $day) as $row) {
                $restrictions[] = ['restricted_pickup_id' => $row['restricted_pickup_id'], 'restricted_person_id' => $row['restricted_person_id'], 'restricted_name' => $row['restricted_name']];
            }
            $children[] = ['id' => $person->id, 'name' => $person->fullName(), 'absent' => isset($absent[$person->id]), 'authorized' => $authorized[$person->id] ?? [], 'restrictions' => $restrictions];
        }
        usort($children, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        $phones = [];
        foreach ($this->people->findMany($tenantId, $this->roles->personIdsWithRole($tenantId, $establishmentId, ['directeur'])) as $director) {
            if ($director->phone !== null) {
                $phones[] = ['name' => $director->fullName(), 'phone' => $director->phone];
            }
        }
        $maxAge = $this->settings->value($tenantId, $establishmentId, self::MAX_AGE_KEY, null);

        return [
            'date' => $day, 'generated_at' => $this->clock->now(), 'max_age_days' => is_int($maxAge) && $maxAge >= 0 ? $maxAge : null,
            'direction' => $phones, 'children' => $children,
        ];
    }
}
