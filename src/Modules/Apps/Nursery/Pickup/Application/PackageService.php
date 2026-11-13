<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Paquet du matin d'une section (US4, EX-MAT-13) : enfants présents, personnes autorisées du jour, restrictions et numéros de la
 * direction, pour que la sortie fonctionne hors ligne. Le paquet porte son horodatage et les âges limites lus de /settings : au-delà
 * de `max_age_days` l'appareil passe en mode « doute » (aucune remise sans appel à la direction). Les codes ne sont PAS dans le
 * paquet : un code se vérifie en ligne (le secret de hachage ne quitte jamais le serveur).
 */
final class PackageService
{
    public function __construct(
        private readonly SectionService $sections,
        private readonly AuthorizedPickupRegistry $registry,
        private readonly RestrictionService $restrictions,
        private readonly DailyPresence $presence,
        private readonly PersonDirectory $people,
        private readonly RoleDirectory $roles,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forSection(Perimeter $perimeter, string $sectionId, string $day): array
    {
        $section = $this->sections->get($perimeter, $sectionId);
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $ids = $this->sections->childIds($tenantId, $sectionId);
        $absent = array_flip($this->presence->absentOn($tenantId, $establishmentId, $day, $sectionId));
        $authorized = [];
        foreach ($this->registry->validOn($tenantId, $establishmentId, $ids, $day) as $info) {
            $authorized[$info->learnerId][] = AuthorizedPersonsService::resource($info);
        }
        $children = [];
        foreach ($this->people->findMany($tenantId, $ids) as $person) {
            $restrictions = [];
            foreach ($this->restrictions->activeFor($tenantId, $establishmentId, $person->id, $day) as $row) {
                $restrictions[] = ['restricted_pickup_id' => $row['restricted_pickup_id'], 'restricted_person_id' => $row['restricted_person_id'], 'restricted_name' => $row['restricted_name']];
            }
            $children[] = [
                'id' => $person->id, 'name' => $person->fullName(), 'absent' => isset($absent[$person->id]),
                'authorized' => $authorized[$person->id] ?? [], 'restrictions' => $restrictions,
            ];
        }
        $phones = [];
        foreach ($this->people->findMany($tenantId, $this->roles->personIdsWithRole($tenantId, $establishmentId, AlertSender::DIRECTOR_ROLES)) as $director) {
            if ($director->phone !== null) {
                $phones[] = ['name' => $director->fullName(), 'phone' => $director->phone];
            }
        }

        return [
            'section_id' => $sectionId, 'section_label' => $section['label'], 'date' => $day, 'generated_at' => $this->clock->now(),
            'max_age_days' => $this->settings->int($tenantId, $establishmentId, NurserySettings::PICKUP_OFFLINE_MAX_AGE),
            'read_days' => $this->settings->int($tenantId, $establishmentId, NurserySettings::PICKUP_OFFLINE_READ),
            'direction' => $phones, 'children' => $children,
        ];
    }
}
