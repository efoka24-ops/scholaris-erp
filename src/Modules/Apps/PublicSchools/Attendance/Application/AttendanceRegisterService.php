<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoAttendanceCalls;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Données du cahier d'appel mensuel d'une classe (DOC-06, T637) : pour chaque jour où un appel a été fait, le statut P ou A de chaque
 * élève par demi-journée. Le PDF du modèle est émis par le sous-module Documents ; cette lecture fournit ses variables. Nominatif :
 * réservé à l'enseignant de la classe et au directeur (périmètre contrôlé par le contrôleur).
 */
final class AttendanceRegisterService
{
    public function __construct(
        private readonly PdoAttendanceCalls $calls,
        private readonly DailyPresence $presence,
        private readonly ClassRoster $classes,
        private readonly PersonDirectory $people,
    ) {
    }

    /** @return array<string, mixed> */
    public function month(string $tenantId, string $establishmentId, string $structureId, string $month): array
    {
        [$from, $to] = TeacherPresenceService::monthBounds($month);
        $class = $this->classes->find($tenantId, $establishmentId, $structureId);
        if ($class === null) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }

        $days = [];
        $absences = [];
        foreach ($this->calls->between($tenantId, $establishmentId, $structureId, $from, $to) as $call) {
            $day = (string) $call['day_on'];
            $part = (string) $call['day_part'];
            $days[$day][$part] = true;
            foreach ($this->presence->absentOn($tenantId, $establishmentId, $day, $structureId, $part) as $pupilId) {
                $absences[$pupilId][$day][$part] = true;
            }
        }
        ksort($days);

        $pupilIds = $this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $to);
        $names = [];
        foreach ($this->people->findMany($tenantId, $pupilIds) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $pupils = [];
        foreach ($pupilIds as $pupilId) {
            $marks = [];
            $count = 0;
            foreach ($days as $day => $parts) {
                foreach (array_keys($parts) as $part) {
                    $absent = isset($absences[$pupilId][$day][$part]);
                    $marks[$day][$part] = $absent ? 'A' : 'P';
                    $count += $absent ? 1 : 0;
                }
            }
            $pupils[] = ['person_id' => $pupilId, 'name' => $names[$pupilId] ?? '', 'marks' => $marks, 'absent_half_days' => $count];
        }

        return [
            'month' => $month,
            'class' => ['structure_id' => $structureId, 'label' => $class->label],
            'days' => array_map(static fn (string $day, array $parts): array => ['date' => $day, 'morning' => isset($parts['morning']), 'afternoon' => isset($parts['afternoon'])], array_keys($days), array_values($days)),
            'pupils' => $pupils,
        ];
    }
}
